<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;
use App\Models\User;
use App\Models\UserType;
use App\Models\AgeGroup;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventConvocation;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\Result;
use App\Models\Presence;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\UserDocument;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Performance\AuthenticatedModuleWarmupService;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Family\FamilyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    /**
     * Normalized codes that identify admin/staff user types.
     * Applied after ->lower()->ascii() normalization, so accented variants need not be listed.
     *
     * @var array<string>
     */
    private const ADMIN_TYPE_CODES = [
        'admin', 'administrador', 'direcao', 'gestor', 'staff', 'tecnico',
    ];

    /**
     * @var array<string>
     */
    private const ATHLETE_TYPE_CODES = [
        'atleta',
    ];

    /**
     * @var array<string>
     */
    private const GUARDIAN_TYPE_CODES = [
        'encarregado_educacao', 'encarregado',
    ];

    public function index(): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $accessControl = app(UserTypeAccessControlService::class)->getCurrentUserAccess($user);
        $familyService = app(FamilyService::class);

        $forceAdmin = request()->string('mode')->lower()->value() === 'admin';
        $canAccessAdminDashboard = $familyService->userCanAccessAdmin($user);

        if ($forceAdmin) {
            abort_unless($canAccessAdminDashboard, 403);

            return $this->renderAdminDashboard($user);
        }

        if ($familyService->userIsOnlyAdmin($user)) {
            return $this->renderAdminDashboard($user);
        }

        return $this->renderAthleteView($user, $accessControl, [
            'is_atleta' => $this->userIsAtleta($user, $accessControl),
            'is_also_admin' => $familyService->userHasAdministratorProfile($user),
            'portal_context_label' => $this->userIsAtleta($user, $accessControl) ? null : 'A Minha Área',
        ]);
    }

    private function renderAdminDashboard(User $user): Response
    {
        app(AuthenticatedModuleWarmupService::class)->scheduleForUser($user);

        $stats = Cache::remember('dashboard:stats', 60, function () {
            return [
                'totalMembers' => User::count(),
                'activeAthletes' => User::whereJsonContains('tipo_membro', 'atleta')
                    ->where('estado', 'ativo')
                    ->count(),
                'guardians' => User::whereJsonContains('tipo_membro', 'encarregado_educacao')->count(),
                'upcomingEvents' => Event::where('data_inicio', '>=', now())
                    ->where('estado', 'agendado')
                    ->count(),
                'monthlyRevenue' => Invoice::whereMonth('data_emissao', now()->month)
                    ->whereYear('data_emissao', now()->year)
                    ->where('estado_pagamento', 'pago')
                    ->sum('valor_total'),
                'totalUserTypes' => UserType::count(),
                'totalAgeGroups' => AgeGroup::count(),
            ];
        });

        $recentEvents = Cache::remember('dashboard:recent_events', 60, fn () =>
            Event::with(['creator:id,name'])
                ->select('id', 'titulo', 'data_inicio', 'estado', 'created_at', 'criado_por')
                ->latest()
                ->take(5)
                ->get()
        );

        $recentActivity = Cache::remember('dashboard:recent_activity', 60, fn () =>
            $this->getRecentActivity()
        );

        $userTypes = Cache::remember('dashboard:user_types', 300, fn () =>
            UserType::where('ativo', true)->get()
        );

        $ageGroups = Cache::remember('dashboard:age_groups', 300, fn () =>
            AgeGroup::all()
        );

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'recentEvents' => $recentEvents,
            'recentActivity' => $recentActivity,
            'userTypes' => $userTypes,
            'ageGroups' => $ageGroups,
        ]);
    }

    private function getRecentActivity(): array
    {
        $activities = [];

        // Recent user registrations — select only needed columns
        $recentUsers = User::latest()->take(3)->select('id', 'name', 'created_at')->get();
        foreach ($recentUsers as $user) {
            $activities[] = [
                'type' => 'user_registered',
                'description' => "Novo membro: {$user->name}",
                'created_at' => $user->created_at,
            ];
        }

        // Recent events — select only needed columns
        $recentEvents = Event::latest()->take(3)->select('id', 'titulo', 'created_at')->get();
        foreach ($recentEvents as $event) {
            $activities[] = [
                'type' => 'event_created',
                'description' => "Evento criado: {$event->titulo}",
                'created_at' => $event->created_at,
            ];
        }

        // Sort by date and limit
        usort($activities, fn($a, $b) => $b['created_at'] <=> $a['created_at']);

        return array_slice($activities, 0, 10);
    }

    private function userIsEncarregadoEducacao(User $user, array $accessControl): bool
    {
        return $this->userMatchesTypeIdentifiers($user, $accessControl, self::GUARDIAN_TYPE_CODES);
    }

    private function userIsAtleta(User $user, array $accessControl): bool
    {
        return $this->userMatchesTypeIdentifiers($user, $accessControl, self::ATHLETE_TYPE_CODES);
    }

    private function userMatchesTypeIdentifiers(User $user, array $accessControl, array $aliases): bool
    {
        $currentUserType = $accessControl['currentUserType'] ?? [];
        $normalizedAliases = collect($aliases)
            ->map(fn (string $alias) => $this->normalizeTypeIdentifier($alias))
            ->filter()
            ->unique()
            ->values();

        $activeUserTypes = $user->relationLoaded('userTypes')
            ? $user->userTypes
            : $user->userTypes()->where('ativo', true)->get();

        $candidates = collect([
            $currentUserType['codigo'] ?? null,
            $currentUserType['nome'] ?? null,
            $user->perfil,
        ])
            ->merge(is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro)
            ->merge($activeUserTypes->pluck('codigo'))
            ->merge($activeUserTypes->pluck('nome'))
            ->map(fn ($value) => $this->normalizeTypeIdentifier((string) $value))
            ->filter()
            ->unique();

        return $candidates->intersect($normalizedAliases)->isNotEmpty();
    }

    private function canAccessAdminDashboard(User $user, array $accessControl): bool
    {
        return $this->userMatchesTypeIdentifiers($user, $accessControl, self::ADMIN_TYPE_CODES)
            || $this->userHasAdminType($user);
    }

    /**
     * @param  array{codigo?: mixed, nome?: mixed}  $userType
     */
    private function matchesAdminType(array $userType): bool
    {
        $codigo = Str::of((string) ($userType['codigo'] ?? ''))->lower()->ascii()->value();
        $nome = Str::of((string) ($userType['nome'] ?? ''))->lower()->ascii()->value();

        return in_array($codigo, self::ADMIN_TYPE_CODES, true)
            || in_array($nome, self::ADMIN_TYPE_CODES, true);
    }

    private function userHasAdminType(User $user): bool
    {
        return $user->userTypes()
            ->where('ativo', true)
            ->get()
            ->contains(fn (UserType $ut) => $this->matchesAdminType([
                'codigo' => $ut->codigo,
                'nome' => $ut->nome,
            ]));
    }

    private function normalizeTypeIdentifier(?string $value): string
    {
        $normalized = Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return match ($normalized) {
            'encarregado', 'encarregado_de_educacao' => 'encarregado_educacao',
            'administrador' => 'admin',
            default => $normalized,
        };
    }

    private function portalUserPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $this->displayName($user),
            'email' => $user->email,
        ];
    }

    private function displayName(User $user): string
    {
        return trim((string) ($user->nome_completo ?: $user->name)) ?: 'Utilizador';
    }

    private function primaryEscalao(User $user): ?string
    {
        $escaloes = is_array($user->escalao) ? $user->escalao : (array) $user->escalao;

        return collect($escaloes)->filter()->map(fn ($item) => (string) $item)->first();
    }

    private function resolveProfileTypes(User $user, array $accessControl): array
    {
        $currentUserType = $accessControl['currentUserType'] ?? [];
        $activeUserTypes = $user->relationLoaded('userTypes')
            ? $user->userTypes
            : $user->userTypes()->where('ativo', true)->get();

        return collect([
            $currentUserType['nome'] ?? null,
            $user->perfil,
        ])
            ->merge(is_array($user->tipo_membro) ? $user->tipo_membro : (array) $user->tipo_membro)
            ->merge($activeUserTypes->pluck('nome'))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function renderFamilyPortal(User $user, array $accessControl): Response
    {
        $educandos = $user->educandos()
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.nome_completo',
                'users.numero_socio',
                'users.foto_perfil',
                'users.estado',
                'users.escalao',
                'users.tipo_membro',
            ])
            ->orderByRaw('COALESCE(users.nome_completo, users.name)')
            ->get();

        $paymentMemberIds = $educandos->pluck('id')
            ->push($user->id)
            ->filter()
            ->unique()
            ->values();
        $educandoIds = $educandos->pluck('id')->filter()->values();

        $pagamentos = $paymentMemberIds->isEmpty()
            ? collect()
            : Invoice::query()
                ->with(['user:id,name,nome_completo'])
                ->whereIn('user_id', $paymentMemberIds)
                ->where('oculta', false)
                ->where(function ($query) {
                    $query->whereNull('estado_pagamento')
                        ->orWhereNotIn('estado_pagamento', ['pago', 'cancelado']);
                })
                ->orderByRaw('CASE WHEN data_vencimento IS NULL THEN 1 ELSE 0 END')
                ->orderBy('data_vencimento')
                ->take(6)
                ->get();

        $proximosTreinos = $educandoIds->isEmpty()
            ? collect()
            : TrainingAthlete::query()
                ->with([
                    'user:id,name,nome_completo',
                    'training:id,numero_treino,data,hora_inicio,local,tipo_treino',
                ])
                ->whereIn('user_id', $educandoIds)
                ->whereHas('training', function ($query) {
                    $query->whereDate('data', '>=', now()->toDateString());
                })
                ->get()
                ->filter(fn (TrainingAthlete $record) => $record->training !== null)
                ->sortBy(fn (TrainingAthlete $record) => sprintf(
                    '%s %s',
                    $record->training?->data?->toDateString() ?? '9999-12-31',
                    $record->training?->hora_inicio ?? '23:59'
                ))
                ->values()
                ->take(6);

        $convocatoriasPendentes = $paymentMemberIds->isEmpty()
            ? collect()
            : EventAttendance::query()
                ->with([
                    'user:id,name,nome_completo',
                    'event:id,titulo,data_inicio,hora_inicio,local,tipo,estado',
                ])
                ->whereIn('user_id', $paymentMemberIds)
                ->whereHas('event', function ($query) {
                    $query->whereDate('data_inicio', '>=', now()->toDateString())
                        ->where('estado', '!=', 'cancelado');
                })
                ->get()
                ->filter(fn (EventAttendance $attendance) => $attendance->event !== null)
                ->sortBy(fn (EventAttendance $attendance) => sprintf(
                    '%s %s',
                    $attendance->event?->data_inicio?->toDateString() ?? '9999-12-31',
                    $attendance->event?->hora_inicio ?? '23:59'
                ))
                ->values()
                ->take(6);

        $documentosAlerta = $paymentMemberIds->isEmpty()
            ? collect()
            : UserDocument::query()
                ->with(['user:id,name,nome_completo'])
            ->whereIn('user_id', $paymentMemberIds)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
                ->orderBy('expiry_date')
                ->take(6)
                ->get();

        $pagamentosPorEducando = $pagamentos->groupBy('user_id');
        $treinosPorEducando = $proximosTreinos->groupBy('user_id');
        $convocatoriasPorEducando = $convocatoriasPendentes->groupBy('user_id');
        $documentosPorEducando = $documentosAlerta->groupBy('user_id');

        $educandosPayload = $educandos->map(function (User $educando) use (
            $pagamentosPorEducando,
            $treinosPorEducando,
            $convocatoriasPorEducando,
            $documentosPorEducando
        ) {
            $nextTraining = $treinosPorEducando->get($educando->id)?->first()?->training;

            return [
                'id' => $educando->id,
                'name' => $this->displayName($educando),
                'email' => $educando->email,
                'numero_socio' => $educando->numero_socio,
                'escalao' => $this->primaryEscalao($educando),
                'estado' => $educando->estado,
                'foto_perfil' => $educando->foto_perfil,
                'member_url' => route('membros.show', ['member' => $educando->id]),
                'pending_payments' => $pagamentosPorEducando->get($educando->id, collect())->count(),
                'pending_documents' => $documentosPorEducando->get($educando->id, collect())->count(),
                'pending_convocations' => $convocatoriasPorEducando->get($educando->id, collect())->count(),
                'next_training' => $nextTraining ? [
                    'title' => $nextTraining->numero_treino ?: 'Treino agendado',
                    'date' => $nextTraining->data?->toDateString(),
                    'time' => $nextTraining->hora_inicio,
                    'location' => $nextTraining->local,
                ] : null,
            ];
        })->values()->all();

        return Inertia::render('Portal/Family', [
            'user' => $this->portalUserPayload($user),
            'familyMember' => [
                'id' => $user->id,
                'name' => $this->displayName($user),
                'email' => $user->email,
                'numero_socio' => $user->numero_socio,
                'foto_perfil' => $user->foto_perfil,
                'estado' => $user->estado,
            ],
            'is_also_admin' => $this->userHasAdministratorProfile($user),
            'is_encarregado_educacao' => true,
            'is_also_athlete' => $this->userIsAtleta($user, $accessControl),
            'educandos' => $educandosPayload,
            'familySummary' => [
                'total_educandos' => count($educandosPayload),
                'pagamentos_pendentes' => $pagamentos->count(),
                'pagamentos_pendentes_valor' => (float) $pagamentos->sum('valor_total'),
                'convocatorias_pendentes' => $convocatoriasPendentes->count(),
                'proximos_treinos' => $proximosTreinos->count(),
                'documentos_alerta' => $documentosAlerta->count(),
            ],
            'alertas' => [],
            'pagamentos' => $pagamentos->map(fn (Invoice $invoice) => [
                'id' => $invoice->id,
                'user_id' => $invoice->user_id,
                'user_name' => $this->displayName($invoice->user),
                'mes' => $invoice->mes,
                'valor' => $invoice->valor_total,
                'estado' => $invoice->estado_pagamento,
                'data_vencimento' => $invoice->data_vencimento?->toDateString(),
            ])->values()->all(),
            'convocatorias_pendentes' => $convocatoriasPendentes->map(fn (EventAttendance $attendance) => [
                'id' => $attendance->id,
                'user_id' => $attendance->user_id,
                'user_name' => $this->displayName($attendance->user),
                'title' => $attendance->event?->titulo,
                'date' => $attendance->event?->data_inicio?->toDateString(),
                'time' => $attendance->event?->hora_inicio,
                'location' => $attendance->event?->local,
                'type' => $attendance->event?->tipo,
            ])->values()->all(),
            'proximos_treinos' => $proximosTreinos->map(fn (TrainingAthlete $record) => [
                'id' => $record->id,
                'user_id' => $record->user_id,
                'user_name' => $this->displayName($record->user),
                'title' => $record->training?->numero_treino,
                'date' => $record->training?->data?->toDateString(),
                'time' => $record->training?->hora_inicio,
                'location' => $record->training?->local,
                'type' => $record->training?->tipo_treino,
            ])->values()->all(),
            'documentos_alerta' => $documentosAlerta->map(fn (UserDocument $document) => [
                'id' => $document->id,
                'user_id' => $document->user_id,
                'user_name' => $this->displayName($document->user),
                'name' => $document->name,
                'type' => $document->type,
                'expiry_date' => $document->expiry_date?->toDateString(),
            ])->values()->all(),
            'comunicados_relevantes' => [],
            'perfil_tipos' => $this->resolveProfileTypes($user, $accessControl),
            'athlete_portal_url' => $this->userIsAtleta($user, $accessControl) ? route('dashboard') : null,
            'modulos_visiveis' => $accessControl['visibleMenuModules'] ?? [],
        ]);
    }

    private function renderBasePortal(User $user, array $accessControl): Response
    {
            $familyService = app(FamilyService::class);

        return Inertia::render('Portal/Base', [
            'user' => $this->portalUserPayload($user),
            'perfil_tipos' => $this->resolveProfileTypes($user, $accessControl),
            'is_also_admin' => $this->userHasAdministratorProfile($user),
                'has_family' => $familyService->userHasFamily($user),
            'modulos_visiveis' => $accessControl['visibleMenuModules'] ?? [],
        ]);
    }

    private function formatSwimTime(float $centiseconds): string
    {
        $total = (int) round($centiseconds);
        $cs    = $total % 100;
        $total = intdiv($total, 100);
        $s     = $total % 60;
        $total = intdiv($total, 60);
        $m     = $total % 60;
        $h     = intdiv($total, 60);

        if ($h > 0) {
            return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
        }
        return sprintf('%d:%02d.%02d', $m, $s, $cs);
    }

    private function renderAthleteView(User $user, array $accessControl, array $overrides = []): Response
    {
        $familyService = app(FamilyService::class);
        $uid = $user->id;

        $trainingRecords = Cache::remember("athlete_dashboard:{$uid}:training_records", 60, function () use ($user) {
            return $this->dashboardTrainingRecordsForUser($user);
        });

        $upcomingTrainings = $trainingRecords
            ->filter(fn (TrainingAthlete $record) => $record->training?->data?->isToday() || $record->training?->data?->isFuture())
            ->values();

        $proximo_treino = Cache::remember("athlete_dashboard:{$uid}:next_training", 60, function () use ($upcomingTrainings) {
            $record = $upcomingTrainings->first();

            if (! $record || ! $record->training) {
                return null;
            }

            $training = $record->training;
            $escaloes = $training->ageGroups?->pluck('nome')
                ?? collect($training->escaloes ?? []);
            $escaloes = collect($escaloes)->filter()->values()->all();

            return [
                'id' => $training->id,
                'numero_treino' => $training->numero_treino,
                'data' => $training->data?->toDateString(),
                'hora_inicio' => $training->hora_inicio,
                'hora_fim' => $training->hora_fim,
                'local' => $training->local,
                'tipo_treino' => $training->tipo_treino,
                'escaloes' => $escaloes,
                'grupo_label' => $escaloes[0] ?? null,
            ];
        });

        $proximos_eventos = Cache::remember("athlete_dashboard:{$uid}:events:v2", 60, function () use ($user) {
            return $this->dashboardUpcomingEventItemsForUser($user)
                ->sortBy([
                    ['data_inicio', 'asc'],
                    ['hora_inicio', 'asc'],
                    ['titulo', 'asc'],
                ])
                ->take(5)
                ->values()
                ->all();
        });

        $attendanceSummary = Cache::remember("athlete_dashboard:{$uid}:attendance", 60, function () use ($trainingRecords) {
            $monthStart = now()->startOfMonth()->toDateString();
            $monthEnd = now()->endOfMonth()->toDateString();

            $monthRecords = $trainingRecords->filter(function (TrainingAthlete $record) use ($monthStart, $monthEnd) {
                $trainingDate = $record->training?->data?->toDateString();

                return $trainingDate !== null && $trainingDate >= $monthStart && $trainingDate <= $monthEnd;
            });

            $scheduled = $monthRecords->count();
            $present = $monthRecords->where('presente', true)->count();

            return [
                'scheduled' => $scheduled,
                'present' => $present,
                'percentage' => $scheduled > 0 ? (int) round(($present / $scheduled) * 100) : null,
            ];
        });

        $ultimos_resultados = Cache::remember("athlete_dashboard:{$uid}:results", 120, function () use ($user) {
            return Result::where('user_id', $user->id)
                ->with(['prova.competition'])
                ->latest()
                ->take(5)
                ->get()
                ->map(function (Result $r) {
                    $prova = $r->prova;
                    $comp  = $prova?->competition;
                    return [
                        'id'              => $r->id,
                        'competicao'      => $comp?->nome,
                        'data'            => $comp?->data_inicio?->toDateString(),
                        'estilo'          => $prova?->estilo,
                        'distancia_m'     => $prova?->distancia_m,
                        'tempo_formatado' => $r->tempo_oficial !== null
                            ? $this->formatSwimTime((float) $r->tempo_oficial)
                            : null,
                        'posicao'         => $r->posicao,
                        'desclassificado' => $r->desclassificado,
                    ];
                })
                ->values()
                ->all();
        });

        $treinos_mes = Cache::remember("athlete_dashboard:{$uid}:presences", 60, function () use ($attendanceSummary) {
            return $attendanceSummary['scheduled'];
        });

        $accountSummary = Cache::remember("athlete_dashboard:{$uid}:current_account", 60, function () use ($user) {
            return app(CurrentAccountService::class)->summarize([
                'user_id' => $user->id,
            ]);
        });
        $contaCorrente = (float) ($accountSummary['net_debt'] ?? 0);

        $proxima_mensalidade_pendente = Cache::remember("athlete_dashboard:{$uid}:pending_invoice", 60, function () use ($user) {
            $invoice = Invoice::where('user_id', $user->id)
                ->where('oculta', false)
                ->where('estado_pagamento', '!=', 'pago')
                ->orderByRaw('CASE WHEN data_vencimento IS NULL THEN 1 ELSE 0 END')
                ->orderBy('data_vencimento')
                ->orderByDesc('data_emissao')
                ->first(['id', 'mes', 'valor_total', 'estado_pagamento', 'data_vencimento']);

            if (! $invoice) {
                return null;
            }

            return [
                'id' => $invoice->id,
                'mes' => $invoice->mes,
                'valor' => $invoice->valor_total,
                'estado' => $invoice->estado_pagamento,
                'data_vencimento' => $invoice->data_vencimento?->toDateString(),
            ];
        });

        $mensalidades = Cache::remember("athlete_dashboard:{$uid}:invoices", 60, function () use ($user) {
            return Invoice::where('user_id', $user->id)
                ->where('oculta', false)
                ->latest('data_emissao')
                ->take(6)
                ->get()
                ->map(fn (Invoice $inv) => [
                    'id'     => $inv->id,
                    'mes'    => $inv->mes,
                    'valor'  => $inv->valor_total,
                    'estado' => $inv->estado_pagamento,
                ])
                ->values()
                ->all();
        });

        $escaloes = $user->escalao ?? [];
        $escalaoId = $escaloes[0] ?? null;
        $escalao = $escalaoId
            ? AgeGroup::query()->whereKey($escalaoId)->value('nome') ?? $escalaoId
            : null;

        $athlete = [
            'name'          => $this->displayName($user),
            'escalao'       => $escalao,
            'numero_socio'  => $user->numero_socio,
            'foto_perfil'   => $user->foto_perfil,
            'estado'        => $user->estado,
            'conta_corrente' => $contaCorrente,
        ];

        $isAlsoAdmin = (bool) ($overrides['is_also_admin'] ?? $this->userHasAdministratorProfile($user));
        $isAtleta = (bool) ($overrides['is_atleta'] ?? $this->userIsAtleta($user, $accessControl));
        $portalContextLabel = $overrides['portal_context_label'] ?? null;
        $hasFamily = $familyService->userHasFamily($user);

        return Inertia::render('Dashboard/Atleta', [
            'user'               => $this->portalUserPayload($user),
            'athlete'            => $athlete,
            'proximo_treino'     => $proximo_treino,
            'proximos_eventos'   => $proximos_eventos,
            'ultimos_resultados' => $ultimos_resultados,
            'treinos_mes'        => $treinos_mes,
            'mensalidades'       => $mensalidades,
            'proxima_mensalidade_pendente' => $proxima_mensalidade_pendente,
            'resumo'             => [
                'treinos_mes' => $treinos_mes,
                'eventos_proximos' => count($proximos_eventos),
                'conta_corrente' => $athlete['conta_corrente'],
                'divida_bruta' => (float) ($accountSummary['gross_debt'] ?? 0),
                'credito_disponivel' => (float) ($accountSummary['available_credit'] ?? 0),
                'assiduidade_percent' => $attendanceSummary['percentage'],
                'treinos_agendados_mes' => $attendanceSummary['scheduled'],
            ],
            'modulos_visiveis'   => $accessControl['visibleMenuModules'] ?? [],
            'is_also_admin'      => $isAlsoAdmin,
            'is_atleta'          => $isAtleta,
            'has_family'         => $hasFamily,
            'family_summary'     => $hasFamily ? $familyService->familySummary($user) : null,
            'family_portal_url'  => $hasFamily ? route('portal.family') : null,
            'perfil_tipos'       => $this->resolveProfileTypes($user, $accessControl),
            'portal_context_label' => $portalContextLabel,
        ]);
    }

    private function userHasAdministratorProfile(User $user): bool
    {
        return $this->normalizeTypeIdentifier((string) $user->perfil) === 'admin';
    }

    /**
     * @return Collection<int, TrainingAthlete>
     */
    private function dashboardTrainingRecordsForUser(User $user): Collection
    {
        $this->ensureDashboardTrainingRecordsForUser($user);

        return TrainingAthlete::query()
            ->with([
                'training:id,numero_treino,data,hora_inicio,hora_fim,local,tipo_treino,descricao_treino,escaloes',
                'training.ageGroups:id,nome',
            ])
            ->where('user_id', $user->id)
            ->get()
            ->filter(fn (TrainingAthlete $record) => $record->training !== null)
            ->sortBy(fn (TrainingAthlete $record) => sprintf(
                '%s %s',
                $record->training?->data?->toDateString() ?? '9999-12-31',
                $record->training?->hora_inicio ?? '23:59'
            ))
            ->values();
    }

    private function ensureDashboardTrainingRecordsForUser(User $user): void
    {
        $ageGroupIds = collect(is_array($user->escalao) ? $user->escalao : [$user->escalao])
            ->push($user->athleteSportsData?->escalao_id)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values();

        if ($ageGroupIds->isEmpty()) {
            return;
        }

        $eligibleTrainings = Training::query()
            ->select('id', 'criado_por')
            ->whereHas('ageGroups', fn ($query) => $query->whereIn('age_groups.id', $ageGroupIds))
            ->whereDoesntHave('athleteRecords', fn ($query) => $query->where('user_id', $user->id))
            ->get();

        if ($eligibleTrainings->isEmpty()) {
            return;
        }

        foreach ($eligibleTrainings as $training) {
            TrainingAthlete::query()->firstOrCreate(
                [
                    'treino_id' => $training->id,
                    'user_id' => $user->id,
                ],
                [
                    'presente' => false,
                    'estado' => 'ausente',
                    'volume_real_m' => null,
                    'rpe' => null,
                    'observacoes_tecnicas' => null,
                    'registado_por' => $training->criado_por,
                    'registado_em' => now(),
                ],
            );
        }
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function dashboardUpcomingEventItemsForUser(User $user): Collection
    {
        $today = now()->toDateString();
        $convocations = EventConvocation::query()
            ->with(['event:id,titulo,data_inicio,hora_inicio,local,tipo,estado'])
            ->where('user_id', $user->id)
            ->whereHas('event', function ($query) use ($today) {
                $query->whereDate('data_inicio', '>=', $today)
                    ->where('estado', '!=', 'cancelado');
            })
            ->get()
            ->filter(fn (EventConvocation $convocation) => $convocation->event !== null)
            ->values();

        $convocationEventIds = $convocations->pluck('evento_id')->filter()->unique()->values()->all();
        $memberAgeGroupIds = collect(is_array($user->escalao) ? $user->escalao : [$user->escalao])
            ->push($user->athleteSportsData?->escalao_id)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value)
            ->unique()
            ->values()
            ->all();

        $informativeEvents = empty($memberAgeGroupIds)
            ? collect()
            : Event::query()
                ->with(['ageGroups:id,nome'])
                ->where('estado', '!=', 'cancelado')
                ->whereDate('data_inicio', '>=', $today)
                ->whereNotIn('id', $convocationEventIds)
                ->where(function ($query) use ($memberAgeGroupIds) {
                    foreach ($memberAgeGroupIds as $ageGroupId) {
                        $query->orWhereHas('ageGroups', fn ($ageGroupQuery) => $ageGroupQuery->where('age_groups.id', $ageGroupId));
                    }
                })
                ->orderBy('data_inicio')
                ->orderBy('hora_inicio')
                ->get();

        return collect($convocations
            ->map(fn (EventConvocation $convocation) => [
                'id' => 'event-' . $convocation->event->id,
                'titulo' => $convocation->event->titulo,
                'data_inicio' => $convocation->event->data_inicio?->toDateString(),
                'hora_inicio' => $convocation->event->hora_inicio,
                'local' => $convocation->event->local,
                'estado' => $convocation->event->estado,
                'tipo' => $convocation->event->tipo,
            ])
            ->all())
            ->merge($informativeEvents->map(fn (Event $event) => [
                'id' => 'informative-event-' . $event->id,
                'titulo' => $event->titulo,
                'data_inicio' => $event->data_inicio?->toDateString(),
                'hora_inicio' => $event->hora_inicio,
                'local' => $event->local,
                'estado' => $event->estado,
                'tipo' => $event->tipo,
            ])->all())
            ->unique('id')
            ->values();
    }
}
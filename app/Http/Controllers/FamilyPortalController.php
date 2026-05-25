<?php

namespace App\Http\Controllers;

use App\Models\EventConvocation;
use App\Models\Familia;
use App\Models\Invoice;
use App\Models\TrainingAthlete;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Family\FamilyService;
use App\Services\Financeiro\CurrentAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class FamilyPortalController extends Controller
{
    /**
     * @var array<string>
     */
    private const ALLOWED_FAMILY_ROLES = [
        'educando',
        'familiar',
        'encarregado_educacao',
    ];

    public function show(FamilyService $familyService, UserTypeAccessControlService $accessControlService): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $accessControl = $accessControlService->getCurrentUserAccess($user);
        $families = $familyService->familiesForPortal($user);

        abort_if($families->isEmpty(), 403);

        $visibleFamilyMemberIds = $families
            ->flatMap(fn (array $family) => collect($family['members'] ?? []))
            ->filter(fn (array $member) => ($member['can_view'] ?? false) && filled($member['id'] ?? null))
            ->pluck('id')
            ->unique()
            ->values();

        $educandos = $families
            ->flatMap(fn (array $family) => collect($family['members'] ?? []))
            ->filter(function (array $member) use ($user) {
                return ($member['id'] ?? null) !== $user->id
                    && ($member['can_view'] ?? false)
                    && $this->normalizeRole($member['papel_na_familia'] ?? null) === 'educando';
            })
            ->unique('id')
            ->values();

        $educandoIds = $educandos->pluck('id')->filter()->values();
        $visibleMembers = $visibleFamilyMemberIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $visibleFamilyMemberIds)
                ->get(['id', 'name', 'nome_completo'])
                ->keyBy('id');
        $currentAccountService = app(CurrentAccountService::class);
        $currentAccountByMember = $visibleFamilyMemberIds
            ->mapWithKeys(fn ($memberId) => [(string) $memberId => $currentAccountService->summarize(['user_id' => $memberId])]);

        $pendingPayments = $currentAccountByMember
            ->flatMap(function (array $summary, string $memberId) use ($visibleMembers) {
                $member = $visibleMembers->get($memberId);

                return collect($summary['breakdown']['invoices'] ?? [])
                    ->map(function (array $invoice) use ($memberId, $member) {
                        return [
                            'id' => (string) ($invoice['id'] ?? ''),
                            'user_id' => $memberId,
                            'user_name' => $member instanceof User ? $this->displayName($member) : 'Utilizador',
                            'mes' => $invoice['mes'] ?? $invoice['tipo'] ?? 'Pagamento pendente',
                            'valor' => round((float) ($invoice['valor_em_aberto'] ?? 0), 2),
                            'valor_nominal' => round((float) ($invoice['valor_total'] ?? 0), 2),
                            'valor_pago' => round((float) ($invoice['valor_pago'] ?? 0), 2),
                            'valor_label' => 'Em aberto',
                            'estado' => $invoice['estado_pagamento'] ?? 'pendente',
                            'tipo_label' => 'Fatura',
                            'data_vencimento' => $invoice['data_vencimento'] ?? null,
                        ];
                    })
                    ->concat(collect($summary['breakdown']['movements'] ?? [])->map(function (array $movement) use ($memberId, $member) {
                        return [
                            'id' => (string) ($movement['id'] ?? ''),
                            'user_id' => $memberId,
                            'user_name' => $member instanceof User ? $this->displayName($member) : 'Utilizador',
                            'mes' => $movement['description'] ?? 'Movimento pendente',
                            'valor' => round((float) ($movement['open_amount'] ?? 0), 2),
                            'valor_nominal' => null,
                            'valor_pago' => null,
                            'valor_label' => 'Em aberto',
                            'estado' => $movement['estado_pagamento'] ?? 'pendente',
                            'tipo_label' => 'Movimento',
                            'data_vencimento' => $movement['data_vencimento'] ?? null,
                        ];
                    }));
            })
            ->sortBy([
                fn (array $payment) => $payment['data_vencimento'] === null ? 1 : 0,
                'data_vencimento',
                'mes',
            ])
            ->values();

        $familyGrossDebt = round((float) $currentAccountByMember->sum('gross_debt'), 2);
        $familyAvailableCredit = round((float) $currentAccountByMember->sum('available_credit'), 2);
        $familyNetDebt = round((float) $currentAccountByMember->sum('net_debt'), 2);

        $upcomingTrainings = $educandoIds->isEmpty()
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
                ->values();

        $pendingConvocations = $visibleFamilyMemberIds->isEmpty()
            ? collect()
            : EventConvocation::query()
                ->with([
                    'user:id,name,nome_completo',
                    'event:id,titulo,data_inicio,hora_inicio,local,tipo,estado',
                ])
                ->whereIn('user_id', $visibleFamilyMemberIds)
                ->where('estado_confirmacao', 'pendente')
                ->whereNull('data_resposta')
                ->whereHas('event', function ($query) {
                    $query->whereDate('data_inicio', '>=', now()->toDateString())
                        ->where('tipo', 'treino')
                        ->where('estado', '!=', 'cancelado');
                })
                ->get()
                ->filter(fn (EventConvocation $convocation) => $convocation->event !== null)
                ->sortBy(fn (EventConvocation $convocation) => sprintf(
                    '%s %s',
                    $convocation->event?->data_inicio?->toDateString() ?? '9999-12-31',
                    $convocation->event?->hora_inicio ?? '23:59'
                ))
                ->values();

        $documentAlerts = $visibleFamilyMemberIds->isEmpty()
            ? collect()
            : UserDocument::query()
                ->with(['user:id,name,nome_completo'])
            ->whereIn('user_id', $visibleFamilyMemberIds)
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
                ->orderBy('expiry_date')
                ->get();

        $pagamentos = $pendingPayments->take(6)->values();
        $proximosTreinos = $upcomingTrainings->take(6)->values();
        $convocatoriasPendentes = $pendingConvocations->take(6)->values();
        $documentosAlerta = $documentAlerts->take(6)->values();

        $pagamentosPorEducando = $pendingPayments->groupBy('user_id');
        $treinosPorEducando = $upcomingTrainings->groupBy('user_id');
        $convocatoriasPorEducando = $pendingConvocations->groupBy('user_id');
        $documentosPorEducando = $documentAlerts->groupBy('user_id');

        $educandosPayload = $educandos->map(function (array $educando) use (
            $pagamentosPorEducando,
            $treinosPorEducando,
            $convocatoriasPorEducando,
            $documentosPorEducando
        ) {
            $nextTraining = $treinosPorEducando->get($educando['id'])?->first()?->training;

            return [
                'id' => $educando['id'],
                'name' => $educando['name'],
                'email' => $educando['email'] ?? null,
                'numero_socio' => $educando['numero_socio'] ?? null,
                'escalao' => collect($educando['escalao'] ?? [])->filter()->first(),
                'estado' => $educando['estado'] ?? null,
                'foto_perfil' => $educando['foto_perfil'] ?? null,
                'member_url' => route('portal.profile', ['member' => $educando['id']]),
                'pending_payments' => $pagamentosPorEducando->get($educando['id'], collect())->count(),
                'pending_documents' => $documentosPorEducando->get($educando['id'], collect())->count(),
                'pending_convocations' => $convocatoriasPorEducando->get($educando['id'], collect())->count(),
                'next_training' => $nextTraining ? [
                    'title' => $nextTraining->numero_treino ?: 'Treino agendado',
                    'date' => $nextTraining->data?->toDateString(),
                    'time' => $nextTraining->hora_inicio,
                    'location' => $nextTraining->local,
                ] : null,
            ];
        })->values()->all();

        return Inertia::render('Portal/Family', [
            'user' => [
                'id' => $user->id,
                'name' => $this->displayName($user),
                'email' => $user->email,
            ],
            'familyMember' => [
                'id' => $user->id,
                'name' => $this->displayName($user),
                'email' => $user->email,
                'numero_socio' => $user->numero_socio,
                'foto_perfil' => $user->foto_perfil,
                'estado' => $user->estado,
            ],
            'families' => $families->values()->all(),
            'is_also_admin' => $familyService->userHasAdministratorProfile($user),
            'is_encarregado_educacao' => $familyService->userHasEducandos($user),
            'is_also_athlete' => $this->userIsAtleta($user, $accessControl),
            'educandos' => $educandosPayload,
            'familySummary' => [
                'total_educandos' => (int) $familyService->familySummary($user)['educandos'],
                'pagamentos_pendentes' => $pendingPayments->count(),
                'pagamentos_pendentes_valor' => $familyNetDebt,
                'gross_debt' => $familyGrossDebt,
                'available_credit' => $familyAvailableCredit,
                'net_debt' => $familyNetDebt,
                'convocatorias_pendentes' => $pendingConvocations->count(),
                'proximos_treinos' => $upcomingTrainings->count(),
                'documentos_alerta' => $documentAlerts->count(),
                'deprecated' => [
                    'manual_account_balance' => round((float) $currentAccountByMember->sum('manual_account_balance'), 2),
                ],
            ],
            'pagamentos' => $pagamentos->all(),
            'convocatorias_pendentes' => $convocatoriasPendentes->map(fn (EventConvocation $convocation) => [
                'id' => $convocation->id,
                'user_id' => $convocation->user_id,
                'user_name' => $this->displayName($convocation->user),
                'title' => $convocation->event?->titulo,
                'date' => $convocation->event?->data_inicio?->toDateString(),
                'time' => $convocation->event?->hora_inicio,
                'location' => $convocation->event?->local,
                'type' => $convocation->event?->tipo,
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
            'has_family' => $familyService->userHasFamily($user),
            'modulos_visiveis' => $accessControl['visibleMenuModules'] ?? [],
        ]);
    }

    public function searchMembers(Request $request, FamilyService $familyService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManagePortalFamily($user, $familyService), 403);

        $search = trim((string) $request->string('search')->value());

        if (mb_strlen($search) < 2) {
            return response()->json([
                'results' => [],
            ]);
        }

        $excludedIds = $familyService->portalMembers($user)
            ->pluck('id')
            ->push($user->id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $results = User::query()
            ->whereNotIn('id', $excludedIds)
            ->where(function ($query) use ($search) {
                $query->where('nome_completo', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('numero_socio', 'like', "%{$search}%");
            })
            ->orderByRaw('COALESCE(nome_completo, name) asc')
            ->limit(8)
            ->get(['id', 'nome_completo', 'name', 'email', 'numero_socio', 'foto_perfil', 'estado', 'tipo_membro'])
            ->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $this->displayName($member),
                'email' => $member->email,
                'numero_socio' => $member->numero_socio,
                'foto_perfil' => $member->foto_perfil,
                'estado' => $member->estado,
                'tipo_membro' => is_array($member->tipo_membro) ? $member->tipo_membro : (array) $member->tipo_membro,
            ])
            ->values()
            ->all();

        return response()->json([
            'results' => $results,
        ]);
    }

    public function storeMember(Request $request, FamilyService $familyService): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->canManagePortalFamily($user, $familyService), 403);

        $validated = $request->validate([
            'member_id' => ['required', 'exists:users,id'],
            'papel_na_familia' => ['required', Rule::in(self::ALLOWED_FAMILY_ROLES)],
        ]);

        $family = $familyService->ensureFamilyForManager($user);
        abort_unless($familyService->userCanManageFamily($user, $family), 403);

        if ($family->members()->whereKey($validated['member_id'])->exists()) {
            return back()->withErrors([
                'member_id' => 'Este membro já está associado à família.',
            ]);
        }

        $role = $this->normalizeRole($validated['papel_na_familia']);

        $family->members()->attach($validated['member_id'], [
            'id' => (string) Str::uuid(),
            'papel_na_familia' => $role,
            'pode_editar' => in_array($role, ['responsavel', 'encarregado_educacao'], true),
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
        ]);

        $this->syncLegacyGuardianLinks($family->fresh('members'), $user, $validated['member_id'], $role);

        return redirect()->route('portal.family');
    }

    private function displayName(User $user): string
    {
        return trim((string) ($user->nome_completo ?: $user->name)) ?: 'Utilizador';
    }

    private function canManagePortalFamily(User $user, FamilyService $familyService): bool
    {
        return $familyService->userCanAccessAdmin($user)
            || $familyService->userHasFamily($user)
            || $familyService->userHasEducandos($user);
    }

    private function normalizeRole(?string $value): string
    {
        return (string) str($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }

    private function syncLegacyGuardianLinks(Familia $family, User $manager, string $memberId, string $role): void
    {
        if (! in_array($role, ['educando', 'encarregado_educacao'], true)) {
            return;
        }

        $educandoIds = $family->members
            ->filter(fn (User $member) => $this->normalizeRole($member->pivot?->papel_na_familia) === 'educando')
            ->pluck('id')
            ->filter()
            ->unique()
            ->values();

        if ($role === 'educando') {
            DB::table('user_guardian')->updateOrInsert([
                'user_id' => $memberId,
                'guardian_id' => $manager->id,
            ], [
                'id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        $educandoIds->each(function (string $educandoId) use ($memberId) {
            DB::table('user_guardian')->updateOrInsert([
                'user_id' => $educandoId,
                'guardian_id' => $memberId,
            ], [
                'id' => (string) Str::uuid(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
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

    private function userIsAtleta(User $user, array $accessControl): bool
    {
        $aliases = collect(['atleta'])
            ->map(fn (string $alias) => $this->normalizeRole($alias))
            ->filter()
            ->unique()
            ->values();

        $currentUserType = $accessControl['currentUserType'] ?? [];
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
            ->map(fn ($value) => $this->normalizeRole((string) $value))
            ->filter()
            ->unique();

        return $candidates->intersect($aliases)->isNotEmpty();
    }
}
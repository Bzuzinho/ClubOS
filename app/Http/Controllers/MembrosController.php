<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMembroRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\User;
use App\Notifications\MemberAccessSetupNotification;
use App\Models\UserType;
use App\Models\AgeGroup;
use App\Models\CostCenter;
use App\Models\MonthlyFee;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\PaymentAllocation;
use App\Services\Communication\InternalCommunicationService;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Family\FamilyService;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberDocumentDataResolver;
use App\Services\Members\MemberDataWriteService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Carbon\Carbon;

class MembrosController extends Controller
{
    public function __construct(
        private readonly InternalCommunicationService $internalCommunicationService,
        private readonly MemberDataWriteService $memberDataWriteService,
        private readonly MemberDocumentDataResolver $memberDocumentDataResolver,
    )
    {
    }

    public function index(Request $request): Response
    {
        // members list — 60s TTL, invalidated on store/update/destroy
        $members = Cache::remember('membros:list', 60, fn () =>
            User::query()
                ->with(['dadosPessoais:id,user_id,nome_completo'])
                ->select([
                    'id',
                    'numero_socio',
                    'nome_completo',
                    'name',
                    'email_utilizador',
                    'foto_perfil',
                    'estado',
                    'tipo_membro',
                    'ativo_desportivo',
                    'escalao',
                    'created_at',
                ])
                ->get()
                ->map(function (User $member): User {
                    $canonicalName = trim((string) ($member->dadosPessoais?->nome_completo ?? ''));

                    if ($canonicalName !== '') {
                        $member->setAttribute('nome_completo', $canonicalName);
                    } elseif (blank($member->nome_completo) && filled($member->name)) {
                        $member->setAttribute('nome_completo', $member->name);
                    }

                    unset($member->dadosPessoais, $member->name);

                    return $member;
                })
                ->sortBy(static fn (User $member) => mb_strtolower((string) ($member->nome_completo ?? '')))
                ->values()
        );

        $userTypes = Cache::remember('membros:user_types', 300, fn () =>
            UserType::where('ativo', true)->select('id', 'nome')->get()
        );

        $ageGroups = Cache::remember('membros:age_groups', 300, fn () =>
            AgeGroup::select('id', 'nome')->get()
        );

        $currentUser = $request->user();

        // All stats in a single cache entry — avoids 8+ separate roundtrips
        $stats = Cache::remember('membros:stats', 60, function () use ($members, $userTypes, $ageGroups) {
            $membersByUserType = [];
            $athletesByAgeGroup = [];

            foreach ($members as $member) {
                $memberTypes = collect($member->tipo_membro ?? [])->map(static fn ($type) => (string) $type);

                foreach ($memberTypes as $typeId) {
                    $membersByUserType[$typeId] = ($membersByUserType[$typeId] ?? 0) + 1;
                }

                if ($memberTypes->contains('atleta')) {
                    foreach (collect($member->escalao ?? [])->map(static fn ($ageGroupId) => (string) $ageGroupId) as $ageGroupId) {
                        $athletesByAgeGroup[$ageGroupId] = ($athletesByAgeGroup[$ageGroupId] ?? 0) + 1;
                    }
                }
            }

            $tipoMembrosStats = [];
            foreach ($userTypes as $tipo) {
                $count = $membersByUserType[(string) $tipo->id] ?? 0;
                if ($count > 0) {
                    $tipoMembrosStats[] = ['tipo' => $tipo->nome, 'count' => $count];
                }
            }

            $escaloesStats = [];
            foreach ($ageGroups as $escalao) {
                $count = $athletesByAgeGroup[(string) $escalao->id] ?? 0;
                if ($count > 0) {
                    $escaloesStats[] = ['escalao' => $escalao->nome, 'count' => $count];
                }
            }

            $createdThreshold = now()->subDays(30);
            $athletes = $members->filter(static fn ($member) => in_array('atleta', $member->tipo_membro ?? [], true));

            return [
                'counts' => [
                    'totalMembros'      => $members->count(),
                    'membrosAtivos'     => $members->where('estado', 'ativo')->count(),
                    'membrosInativos'   => $members->where('estado', 'inativo')->count(),
                    'totalAtletas'      => $athletes->count(),
                    'atletasAtivos'     => $athletes->where('ativo_desportivo', true)->count(),
                    'encarregados'      => $members->filter(static fn ($member) => in_array('encarregado_educacao', $member->tipo_membro ?? [], true))->count(),
                    'treinadores'       => $members->filter(static fn ($member) => in_array('treinador', $member->tipo_membro ?? [], true))->count(),
                    'novosUltimos30Dias' => $members->filter(static fn ($member) => optional($member->created_at)?->greaterThanOrEqualTo($createdThreshold))->count(),
                ],
                'tipoMembrosStats' => $tipoMembrosStats,
                'escaloesStats'    => $escaloesStats,
            ];
        });

        $internalCommunications = ['received' => [], 'sent' => []];
        if ($currentUser) {
            $internalCommunications = Cache::remember(
                'membros:communications:' . $currentUser->id,
                30,
                fn () => [
                    'received' => $this->internalCommunicationService->receivedFeed($currentUser->id),
                    'sent' => $this->internalCommunicationService->sentFeed($currentUser->id),
                ]
            );
        }

        return Inertia::render('Membros/Index', [
            'members' => $members,
            'userTypes' => $userTypes,
            'ageGroups' => $ageGroups,
            'internalCommunications' => $internalCommunications,
            'communicationState' => [
                'initialTab' => $request->string('tab')->value() ?: 'dashboard',
                'initialFolder' => $request->string('folder')->value() ?: 'received',
                'initialMessageId' => $request->string('message')->value() ?: null,
            ],
            'stats' => [
                'totalMembros'      => $stats['counts']['totalMembros'],
                'membrosAtivos'     => $stats['counts']['membrosAtivos'],
                'membrosInativos'   => $stats['counts']['membrosInativos'],
                'totalAtletas'      => $stats['counts']['totalAtletas'],
                'atletasAtivos'     => $stats['counts']['atletasAtivos'],
                'encarregados'      => $stats['counts']['encarregados'],
                'treinadores'       => $stats['counts']['treinadores'],
                'novosUltimos30Dias' => $stats['counts']['novosUltimos30Dias'],
                'atestadosACaducar' => 0, // TODO: implement when health data is available
            ],
            'tipoMembrosStats' => $stats['tipoMembrosStats'],
            'escaloesStats' => $stats['escaloesStats'],
        ]);
    }

    public function create(): Response
    {
        $allUsers = User::with(['userTypes', 'ageGroup'])->get();
        $userTypes = UserType::where('ativo', true)->get();
        $ageGroups = AgeGroup::all();
        $nextMemberNumber = $this->generateMemberNumber();
        
        return Inertia::render('Membros/Create', [
            'allUsers' => $allUsers,
            'userTypes' => $userTypes,
            'ageGroups' => $ageGroups,
            'nextMemberNumber' => $nextMemberNumber,
            'monthlyFees' => MonthlyFee::where('ativo', true)
                ->select('id', 'designacao', 'valor', 'ativo')
                ->get()
                ->map(function ($fee) {
                    $fee->valor = (float) $fee->valor;
                    return $fee;
                }),
            'costCenters' => CostCenter::where('ativo', true)
                ->select('id', 'nome', 'ativo')
                ->get(),
        ]);
    }

    public function store(StoreMembroRequest $request): RedirectResponse
    {
        try {
            $data = $request->validated();

            if (array_key_exists('escalao_id', $data) && !array_key_exists('escalao', $data)) {
                $data['escalao'] = $data['escalao_id'] ? [(string) $data['escalao_id']] : [];
            }

            if (array_key_exists('escalao', $data) && !is_array($data['escalao'])) {
                $data['escalao'] = $data['escalao'] ? [(string) $data['escalao']] : [];
            }

            unset($data['escalao_id']);
            
            // Auto-generate numero_socio if not provided
            if (empty($data['numero_socio'])) {
                $data['numero_socio'] = $this->generateMemberNumber();
            }

            $data = $this->syncAuthIdentityFields($data);
            
            // Auto-calculate menor field (age < 18)
            if (isset($data['data_nascimento'])) {
                $birthDate = Carbon::parse($data['data_nascimento']);
                $data['menor'] = $birthDate->age < 18;
            }
            
            // Hash password
            $data['password'] = Hash::make($data['password'] ?? 'password123');
            
            // Handle file uploads
            if (isset($data['foto_perfil']) && $this->isBase64($data['foto_perfil'])) {
                $data['foto_perfil'] = $this->storeBase64Image($data['foto_perfil'], 'members/photos');
            } elseif (array_key_exists('foto_perfil', $data)) {
                unset($data['foto_perfil']);
            }
            
            if (isset($data['cartao_federacao']) && $this->isBase64($data['cartao_federacao'])) {
                $data['cartao_federacao'] = $this->storeFile($data['cartao_federacao'], 'members/federation_cards');
            }
            
            if (isset($data['arquivo_rgpd']) && $this->isBase64($data['arquivo_rgpd'])) {
                $data['arquivo_rgpd'] = $this->storeFile($data['arquivo_rgpd'], 'members/gdpr');
            }
            
            if (isset($data['arquivo_consentimento']) && $this->isBase64($data['arquivo_consentimento'])) {
                $data['arquivo_consentimento'] = $this->storeFile($data['arquivo_consentimento'], 'members/consent');
            }
            
            if (isset($data['arquivo_afiliacao']) && $this->isBase64($data['arquivo_afiliacao'])) {
                $data['arquivo_afiliacao'] = $this->storeFile($data['arquivo_afiliacao'], 'members/affiliation');
            }
            
            if (isset($data['declaracao_transporte']) && $this->isBase64($data['declaracao_transporte'])) {
                $data['declaracao_transporte'] = $this->storeFile($data['declaracao_transporte'], 'members/transport');
            }
            
            $member = User::create($this->legacyUserPayloadForMemberWrite($data));
            $member->refresh();

            $this->memberDataWriteService->persistFromMemberRequest($member, $data, (string) $member->id);

            if ($this->hasFinancialDataPayload($data)) {
                $financeData = DadosFinanceiros::firstOrNew(['user_id' => $member->id]);
                $this->fillFinancialData($financeData, $data);
                $financeData->save();
            }
            
            // Sync relationships
            if (isset($data['user_types'])) {
                $member->userTypes()->sync($data['user_types']);
            }
            
            // Sync guardian relationship (with reciprocal update)
            if ($request->boolean('sync_encarregado_educacao')) {
                $this->syncGuardianRelations($member, is_array($data['encarregado_educacao'] ?? null) ? $data['encarregado_educacao'] : []);
            }

            // Sync educandos relationship (with reciprocal update)
            if ($request->boolean('sync_educandos')) {
                $this->syncEducandoRelations($member, is_array($data['educandos'] ?? null) ? $data['educandos'] : []);
            }

            if (array_key_exists('centro_custo', $data) && is_array($data['centro_custo'])) {
                $centros = $data['centro_custo'];
                $syncData = [];

                foreach ($centros as $center) {
                    if (is_array($center)) {
                        $centerId = $center['id'] ?? null;
                        $peso = isset($center['peso']) ? (float) $center['peso'] : 1;
                    } else {
                        $centerId = $center;
                        $peso = 1;
                    }

                    if ($centerId) {
                        $syncData[$centerId] = ['peso' => $peso];
                    }
                }

                $member->centrosCusto()->sync($syncData);
                $member->centro_custo = array_values(array_keys($syncData));
                $member->save();
            }

            Cache::forget('membros:list');
            Cache::forget('membros:stats');
            if ($request->user()) {
                Cache::forget('membros:communications:' . $request->user()->id);
            }

            return redirect()->route('membros.index')
                ->with('success', 'Membro criado com sucesso!');
                
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Erro ao criar membro: ' . $e->getMessage());
        }
    }

    public function show(User $member): Response
    {
        $viewer = request()->user();
        $accessControlService = app(UserTypeAccessControlService::class);
        $familyService = app(FamilyService::class);

        $memberKey = (string) (
            $member->getRouteKey()
            ?? $member->getOriginal('id')
            ?? $member->getAttribute('id')
            ?? request()->route('member')
            ?? request()->segment(2)
        );

        $this->reconcileLegacyRelationState($memberKey);

        $member = User::query()->whereKey($memberKey)->firstOrFail();

        $member->load([
            'userTypes',
            'ageGroup',
            'encarregados',
            'educandos',
            'eventsCreated',
            'eventAttendances',
            'documents',
            'relationships.relatedUser',
            'dadosFinanceiros',
            'centrosCusto',
        ]);

        $memberData = $member->toArray();

        // M2.3 — camada de leitura canónica com fallback (não altera escrita)
        $member->loadMissing(['dadosPessoais', 'dadosConfiguracao']);
        $memberData = app(MemberDataReadService::class)->mergedMemberPayload($member, $memberData);
        $memberData = array_merge($memberData, $this->memberDocumentDataResolver->memberDocumentPayload($member));

        $legacyEducandoIds = $this->normalizeRelationIds($member->getAttribute('educandos'));
        $legacyGuardianIds = $this->normalizeRelationIds($member->getAttribute('encarregado_educacao'));

        if (($memberData['educandos'] ?? []) === [] && $legacyEducandoIds !== []) {
            $memberData['educandos'] = User::query()
                ->whereIn('id', $legacyEducandoIds)
                ->select('id', 'nome_completo', 'name', 'numero_socio', 'foto_perfil', 'estado', 'tipo_membro', 'menor')
                ->get()
                ->sortBy(fn (User $user) => array_search((string) $user->getKey(), $legacyEducandoIds, true))
                ->values();
        }

        if (($memberData['encarregados'] ?? []) === [] && $legacyGuardianIds !== []) {
            $memberData['encarregados'] = User::query()
                ->whereIn('id', $legacyGuardianIds)
                ->select('id', 'nome_completo', 'name', 'numero_socio', 'foto_perfil', 'estado', 'tipo_membro', 'menor')
                ->get()
                ->sortBy(fn (User $user) => array_search((string) $user->getKey(), $legacyGuardianIds, true))
                ->values();
        }
        $memberDataReadService = app(MemberDataReadService::class);

        $allUsers = User::query()
            ->with(['dadosPessoais:id,user_id,nome_completo,data_nascimento'])
            ->select(
                'id',
                'name',
                'numero_socio',
                'tipo_membro',
                'foto_perfil',
                'menor'
            )
            ->selectRaw('data_nascimento as legacy_data_nascimento')
            ->get()
            ->map(function (User $user) use ($memberDataReadService): array {
                $personal = $memberDataReadService->personalPayload($user);
                $canonicalName = trim((string) ($personal['nome_completo'] ?? ''));

                if ($canonicalName === '') {
                    $canonicalName = trim((string) $user->name);
                }

                return [
                    'id' => $user->id,
                    'nome_completo' => $canonicalName,
                    'numero_socio' => $user->numero_socio,
                    'tipo_membro' => $user->tipo_membro,
                    'foto_perfil' => $user->foto_perfil,
                    'menor' => $user->menor,
                    'data_nascimento' => $personal['data_nascimento']
                        ?? $this->normalizedBirthDate($user->getAttribute('legacy_data_nascimento')),
                ];
            })
            ->values();

        $currentAccountService = app(CurrentAccountService::class);

        $faturas = Invoice::where('user_id', $memberKey)
            ->withSum([
                'paymentAllocations as confirmed_payment_allocations_sum' => function ($paymentAllocationQuery): void {
                    $paymentAllocationQuery->where('status', PaymentAllocation::STATUS_CONFIRMED);
                },
            ], 'amount')
            ->orderBy('data_emissao', 'desc')
            ->get()
            ->map(function ($fatura) use ($currentAccountService) {
                $fatura = $currentAccountService->normalizeInvoiceFinancialAmounts($fatura);
                $fatura->valor_total = (float) $fatura->valor_total;
                $fatura->valor_pago = (float) ($fatura->valor_pago ?? 0);
                $fatura->valor_em_aberto = (float) ($fatura->valor_em_aberto ?? 0);
                return $fatura;
            });

        $movimentos = Movement::where('user_id', $memberKey)
            ->orderBy('data_emissao', 'desc')
            ->get()
            ->map(function ($movimento) {
                $movimento->valor_total = (float) $movimento->valor_total;
                return $movimento;
            });

        $accountSummary = $currentAccountService->summarize([
            'user_id' => $memberKey,
        ]);
        $openInvoices = collect($accountSummary['breakdown']['invoices'] ?? []);
        $openMovements = collect($accountSummary['breakdown']['movements'] ?? []);

        $memberData['conta_corrente'] = (float) ($accountSummary['net_debt'] ?? 0);
        $memberData['current_account_summary'] = [
            'gross_debt' => (float) ($accountSummary['gross_debt'] ?? 0),
            'available_credit' => (float) ($accountSummary['available_credit'] ?? 0),
            'manual_account_balance' => (float) ($accountSummary['manual_account_balance'] ?? 0),
            'net_debt' => (float) ($accountSummary['net_debt'] ?? 0),
            'overdue_debt' => (float) ($accountSummary['overdue_debt'] ?? 0),
            'monthly_fees_open_amount' => (float) $openInvoices
                ->filter(fn (array $invoice): bool => ($invoice['tipo'] ?? null) === 'mensalidade')
                ->sum('valor_em_aberto'),
            'revenue_movements_open_amount' => (float) $openMovements->sum('open_amount'),
        ];
        $memberData['tipo_mensalidade'] = $member->dadosFinanceiros?->mensalidade_id ?? $member->tipo_mensalidade;
        $memberData['discount_type'] = $member->dadosFinanceiros?->discount_type;
        $memberData['discount_value'] = $member->dadosFinanceiros?->discount_value !== null
            ? (float) $member->dadosFinanceiros->discount_value
            : null;
        $memberData['discount_reason'] = $member->dadosFinanceiros?->discount_reason;
        $legacyCentros = collect($member->centro_custo ?? [])
            ->map(function ($center) {
                if (is_array($center) && isset($center['id'])) {
                    return $center['id'];
                }
                return $center;
            })
            ->filter()
            ->values();

        if ($member->centrosCusto->isNotEmpty()) {
            $memberData['centro_custo'] = $member->centrosCusto->pluck('id')->values();
            $memberData['centro_custo_pesos'] = $member->centrosCusto->map(function ($center) {
                return [
                    'id' => $center->id,
                    'peso' => (float) ($center->pivot->peso ?? 1),
                ];
            })->values();
        } else {
            $memberData['centro_custo'] = $legacyCentros;
            $memberData['centro_custo_pesos'] = $legacyCentros->map(function ($id) {
                return [
                    'id' => $id,
                    'peso' => 1.0,
                ];
            })->values();
        }

        Log::info('membros.show relation snapshot', [
            'member_key' => $memberKey,
            'route_member' => request()->route('member'),
            'member_data_educandos' => collect($memberData['educandos'] ?? [])->map(fn ($entry) => is_array($entry) ? ($entry['id'] ?? $entry) : $entry)->values()->all(),
            'member_data_guardians' => collect($memberData['encarregados'] ?? [])->map(fn ($entry) => is_array($entry) ? ($entry['id'] ?? $entry) : $entry)->values()->all(),
            'legacy_educandos' => $member->educandos ?? null,
            'legacy_guardians' => $member->encarregado_educacao ?? null,
            'pivot_educandos' => $member->educandos()->pluck('users.id')->all(),
            'pivot_guardians' => $member->encarregados()->pluck('users.id')->all(),
        ]);

        $canManageFamilyRelations = $viewer
            ? $accessControlService->canAccessPermission($viewer, 'membros.ficha', 'edit')
            : false;

        return Inertia::render('Membros/Show', [
            'member' => $memberData,
            'permissions' => [
                'can_view' => $viewer ? $accessControlService->canAccessPermission($viewer, 'membros.ficha', 'view') : false,
                'can_edit' => $viewer ? $accessControlService->canAccessPermission($viewer, 'membros.ficha', 'edit') : false,
                'can_delete' => $viewer ? $accessControlService->canAccessPermission($viewer, 'membros.ficha', 'delete') : false,
            ],
            'family_context' => $this->buildFamilyContext(
                $member,
                $canManageFamilyRelations,
                $familyService,
            ),
            'allUsers' => $allUsers,
            'internalCommunications' => [
                'received' => $this->internalCommunicationService->receivedFeed($memberKey),
                'sent' => $this->internalCommunicationService->sentFeed($memberKey),
            ],
            'userTypes' => UserType::where('ativo', true)->get(),
            'ageGroups' => AgeGroup::all(),
            'faturas' => $faturas,
            'movimentos' => $movimentos,
            'monthlyFees' => MonthlyFee::where('ativo', true)
                ->select('id', 'designacao', 'valor', 'ativo')
                ->get()
                ->map(function ($fee) {
                    $fee->valor = (float) $fee->valor;
                    return $fee;
                }),
            'costCenters' => CostCenter::where('ativo', true)
                ->select('id', 'nome', 'ativo')
                ->get(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFamilyContext(User $member, bool $canManageFamilyRelations, FamilyService $familyService): array
    {
        $guardians = collect($member->encarregados ?? [])
            ->map(fn ($guardian) => $this->resolveRelatedUser($guardian))
            ->filter(fn ($guardian) => $guardian instanceof User)
            ->map(function (User $guardian) {
                $guardian->loadMissing('dadosPessoais');

                return [
                    'id' => $guardian->id,
                    'nome_completo' => $this->canonicalMemberName($guardian),
                    'numero_socio' => $guardian->numero_socio,
                    'estado' => $guardian->estado,
                    'tipo_membro' => is_array($guardian->tipo_membro) ? $guardian->tipo_membro : (array) $guardian->tipo_membro,
                    'email' => $guardian->email_utilizador ?: $guardian->email,
                    'contacto' => $this->canonicalMemberContact($guardian),
                ];
            })
            ->values();

        $dependents = collect($member->educandos ?? [])
            ->map(fn ($educando) => $this->resolveRelatedUser($educando))
            ->filter(fn ($educando) => $educando instanceof User)
            ->map(function (User $educando) {
                $educando->loadMissing('dadosPessoais');

                return [
                    'id' => $educando->id,
                    'nome_completo' => $this->canonicalMemberName($educando),
                    'numero_socio' => $educando->numero_socio,
                    'estado' => $educando->estado,
                    'tipo_membro' => is_array($educando->tipo_membro) ? $educando->tipo_membro : (array) $educando->tipo_membro,
                ];
            })
            ->values();

        $families = collect($familyService->actualFamiliesForUser($member) ?? [])
            ->map(function ($family) {
                $members = collect($family->members ?? [])
                    ->map(function (User $familyMember) {
                        $familyMember->loadMissing('dadosPessoais');

                        return [
                            'id' => $familyMember->id,
                            'nome_completo' => $this->canonicalMemberName($familyMember),
                            'numero_socio' => $familyMember->numero_socio,
                            'estado' => $familyMember->estado,
                            'papel_na_familia' => $familyMember->pivot?->papel_na_familia,
                            'tipo_membro' => is_array($familyMember->tipo_membro) ? $familyMember->tipo_membro : (array) $familyMember->tipo_membro,
                        ];
                    })
                    ->values();

                return [
                    'id' => $family->id,
                    'nome' => $family->nome,
                    'ativo' => (bool) $family->ativo,
                    'papel_do_membro' => optional(
                        collect($family->members ?? [])->firstWhere('id', $family->responsavel_user_id)
                    )->pivot?->papel_na_familia,
                    'members' => $members,
                ];
            })
            ->values();

        $memberTypeCodes = collect(is_array($member->tipo_membro) ? $member->tipo_membro : (array) $member->tipo_membro)
            ->map(fn ($value) => strtolower((string) $value))
            ->values();

        $isGuardianProfile = $memberTypeCodes->contains('encarregado_educacao') || $dependents->isNotEmpty();
        $isDependentProfile = $memberTypeCodes->contains('atleta') || $guardians->isNotEmpty() || (bool) $member->menor;

        return [
            'is_guardian_profile' => $isGuardianProfile,
            'is_dependent_profile' => $isDependentProfile,
            'guardians' => $guardians->all(),
            'dependents' => $dependents->all(),
            'families' => $families->all(),
            'summary' => [
                'guardians_count' => $guardians->count(),
                'dependents_count' => $dependents->count(),
                'families_count' => $families->count(),
                'family_members_count' => (int) $families->sum(fn (array $family) => count($family['members'] ?? [])),
            ],
            'can_manage_family_relations' => $canManageFamilyRelations,
        ];
    }

    private function canonicalMemberName(User $user): string
    {
        $personalName = trim((string) ($user->dadosPessoais?->nome_completo ?? ''));

        if ($personalName !== '') {
            return $personalName;
        }

        $legacyName = trim((string) ($user->nome_completo ?: $user->name));

        return $legacyName !== '' ? $legacyName : 'Sem nome';
    }

    private function canonicalMemberContact(User $user): ?string
    {
        $contact = app(MemberDataReadService::class)->valueFromPersonal(
            $user,
            'contacto',
            ['contacto', 'telemovel', 'contacto_telefonico'],
        );

        $normalized = trim((string) $contact);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizedBirthDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return $normalized !== '' ? $normalized : null;
    }

    private function resolveRelatedUser(mixed $candidate): ?User
    {
        if ($candidate instanceof User) {
            return $candidate;
        }

        if (is_array($candidate)) {
            $candidate = $candidate['id'] ?? null;
        }

        if (!is_string($candidate) && !is_int($candidate)) {
            return null;
        }

        return User::query()->find($candidate);
    }

    public function edit(User $member): Response
    {
        // M2.3 — carregar relações de leitura + restantes relações
        $member->load(['dadosPessoais', 'dadosConfiguracao', 'dadosFinanceiros', 'centrosCusto', 'userTypes', 'ageGroup', 'encarregados', 'educandos']);

        $member->tipo_mensalidade = $member->dadosFinanceiros?->mensalidade_id ?? $member->tipo_mensalidade;
        $member->discount_type = $member->dadosFinanceiros?->discount_type;
        $member->discount_value = $member->dadosFinanceiros?->discount_value !== null
            ? (float) $member->dadosFinanceiros->discount_value
            : null;
        $member->discount_reason = $member->dadosFinanceiros?->discount_reason;
        $legacyCentros = collect($member->centro_custo ?? [])
            ->map(function ($center) {
                if (is_array($center) && isset($center['id'])) {
                    return $center['id'];
                }
                return $center;
            })
            ->filter()
            ->values();

        if ($member->centrosCusto->isNotEmpty()) {
            $member->centro_custo = $member->centrosCusto->pluck('id')->values();
            $member->centro_custo_pesos = $member->centrosCusto->map(function ($center) {
                return [
                    'id' => $center->id,
                    'peso' => (float) ($center->pivot->peso ?? 1),
                ];
            })->values();
        } else {
            $member->centro_custo = $legacyCentros;
            $member->centro_custo_pesos = $legacyCentros->map(function ($id) {
                return [
                    'id' => $id,
                    'peso' => 1.0,
                ];
            })->values();
        }
        $memberDocumentPayload = $this->memberDocumentDataResolver->memberDocumentPayload($member);

        return Inertia::render('Membros/Edit', [
            'member' => array_merge(
                app(MemberDataReadService::class)->mergedMemberPayload($member, $member->toArray()),
                $memberDocumentPayload,
            ),
            'userTypes' => UserType::where('ativo', true)->get(),
            'ageGroups' => AgeGroup::all(),
            'guardians' => User::whereJsonContains('tipo_membro', 'encarregado_educacao')
                ->where('id', '!=', $member->id)
                ->get(),
            'monthlyFees' => MonthlyFee::where('ativo', true)
                ->select('id', 'designacao', 'valor', 'ativo')
                ->get()
                ->map(function ($fee) {
                    $fee->valor = (float) $fee->valor;
                    return $fee;
                }),
            'costCenters' => CostCenter::where('ativo', true)
                ->select('id', 'nome', 'ativo')
                ->get(),
        ]);
    }

    public function update(UpdateMemberRequest $request, User $member): RedirectResponse
    {
        try {
            $memberKey = (string) (
                $member->getRouteKey()
                ?? $member->getOriginal('id')
                ?? $member->getAttribute('id')
                ?? $request->route('member')
                ?? $request->segment(2)
            );
            $data = $request->validated();

            Log::info('membros.update incoming relations', [
                'member_key' => $memberKey,
                'request_educandos' => $request->input('educandos'),
                'validated_educandos' => $data['educandos'] ?? null,
                'request_guardians' => $request->input('encarregado_educacao'),
                'validated_guardians' => $data['encarregado_educacao'] ?? null,
                'sync_educandos' => $request->boolean('sync_educandos'),
                'sync_guardians' => $request->boolean('sync_encarregado_educacao'),
            ]);

            if (array_key_exists('escalao_id', $data) && !array_key_exists('escalao', $data)) {
                $data['escalao'] = $data['escalao_id'] ? [(string) $data['escalao_id']] : [];
            }

            if (array_key_exists('escalao', $data) && !is_array($data['escalao'])) {
                $data['escalao'] = $data['escalao'] ? [(string) $data['escalao']] : [];
            }

            unset($data['escalao_id']);
            
            // Auto-calculate menor field if data_nascimento changes
            if (isset($data['data_nascimento'])) {
                $birthDate = Carbon::parse($data['data_nascimento']);
                $data['menor'] = $birthDate->age < 18;
            }
            
            // Hash password only if provided
            if (isset($data['password']) && $data['password']) {
                $data['password'] = Hash::make($data['password']);
            } else {
                unset($data['password']);
            }
            
            // Handle file uploads
            if (isset($data['foto_perfil']) && $this->isBase64($data['foto_perfil'])) {
                $this->deleteFile($member->foto_perfil);
                $data['foto_perfil'] = $this->storeBase64Image($data['foto_perfil'], 'members/photos');
            } elseif (array_key_exists('foto_perfil', $data)) {
                unset($data['foto_perfil']);
            }

            $memberDocumentPayload = $this->memberDocumentDataResolver->memberDocumentPayload($member);
            
            if (isset($data['cartao_federacao']) && $this->isBase64($data['cartao_federacao'])) {
                $this->deleteFile($memberDocumentPayload['cartao_federacao']);
                $data['cartao_federacao'] = $this->storeFile($data['cartao_federacao'], 'members/federation_cards');
            }
            
            if (isset($data['arquivo_rgpd']) && $this->isBase64($data['arquivo_rgpd'])) {
                $this->deleteFile($memberDocumentPayload['arquivo_rgpd']);
                $data['arquivo_rgpd'] = $this->storeFile($data['arquivo_rgpd'], 'members/gdpr');
            }
            
            if (isset($data['arquivo_consentimento']) && $this->isBase64($data['arquivo_consentimento'])) {
                $this->deleteFile($memberDocumentPayload['arquivo_consentimento']);
                $data['arquivo_consentimento'] = $this->storeFile($data['arquivo_consentimento'], 'members/consent');
            }
            
            if (isset($data['arquivo_afiliacao']) && $this->isBase64($data['arquivo_afiliacao'])) {
                $this->deleteFile($memberDocumentPayload['arquivo_afiliacao']);
                $data['arquivo_afiliacao'] = $this->storeFile($data['arquivo_afiliacao'], 'members/affiliation');
            }
            
            if (isset($data['declaracao_transporte']) && $this->isBase64($data['declaracao_transporte'])) {
                $this->deleteFile($memberDocumentPayload['declaracao_transporte']);
                $data['declaracao_transporte'] = $this->storeFile($data['declaracao_transporte'], 'members/transport');
            }
            
            $data = $this->syncAuthIdentityFields($data, $member);

            $member->update($this->legacyUserPayloadForMemberWrite($data));
            $member->refresh();

            $this->memberDataWriteService->persistFromMemberRequest($member, $data, $memberKey);

            if ($this->hasFinancialDataPayload($data)) {
                $financeData = DadosFinanceiros::firstOrNew(['user_id' => $member->id]);
                $this->fillFinancialData($financeData, $data);
                $financeData->save();
            }

            if (array_key_exists('centro_custo', $data) && is_array($data['centro_custo'])) {
                $centros = $data['centro_custo'];
                $syncData = [];

                foreach ($centros as $center) {
                    if (is_array($center)) {
                        $centerId = $center['id'] ?? null;
                        $peso = isset($center['peso']) ? (float) $center['peso'] : 1;
                    } else {
                        $centerId = $center;
                        $peso = 1;
                    }

                    if ($centerId) {
                        $syncData[$centerId] = ['peso' => $peso];
                    }
                }

                $member->centrosCusto()->sync($syncData);
                $member->centro_custo = array_values(array_keys($syncData));
                $member->save();
            }
            
            // Sync relationships
            if (isset($data['user_types'])) {
                $member->userTypes()->sync($data['user_types']);
            }
            
            // Explicit sync flags let the frontend clear relations by sending empty arrays.
            if ($request->boolean('sync_encarregado_educacao')) {
                $this->syncGuardianRelations(
                    $member,
                    is_array($data['encarregado_educacao'] ?? null) ? $data['encarregado_educacao'] : [],
                    $memberKey
                );
            }

            if ($request->boolean('sync_educandos')) {
                $this->syncEducandoRelations(
                    $member,
                    is_array($data['educandos'] ?? null) ? $data['educandos'] : [],
                    $memberKey
                );
            }

            Log::info('membros.update persisted relations', [
                'member_key' => $memberKey,
                'legacy_educandos' => User::query()->whereKey($memberKey)->value('educandos'),
                'legacy_guardians' => User::query()->whereKey($memberKey)->value('encarregado_educacao'),
                'pivot_educandos' => DB::table('user_guardian')->where('guardian_id', $memberKey)->pluck('user_id')->all(),
                'pivot_guardians' => DB::table('user_guardian')->where('user_id', $memberKey)->pluck('guardian_id')->all(),
            ]);

            Cache::forget('membros:list');
            Cache::forget('membros:stats');
            if ($request->user()) {
                Cache::forget('membros:communications:' . $request->user()->id);
            }

            return redirect()->route('membros.show', ['member' => $memberKey])
                ->with('success', 'Membro atualizado com sucesso!');
                
        } catch (\Exception $e) {
            return redirect()->back()
                ->withInput()
                ->with('error', 'Erro ao atualizar membro: ' . $e->getMessage());
        }
    }

    public function destroy(User $member): RedirectResponse
    {
        try {
            // Detach from all guardian/educando relationships
            $member->encarregados()->detach();
            $member->educandos()->detach();

            $memberDocumentPayload = $this->memberDocumentDataResolver->memberDocumentPayload($member);
            
            // Delete all associated files
            $this->deleteFile($member->foto_perfil);
            $this->deleteFile($memberDocumentPayload['cartao_federacao']);
            $this->deleteFile($memberDocumentPayload['arquivo_rgpd']);
            $this->deleteFile($memberDocumentPayload['arquivo_consentimento']);
            $this->deleteFile($memberDocumentPayload['arquivo_afiliacao']);
            $this->deleteFile($memberDocumentPayload['declaracao_transporte']);
            
            $member->delete();

            Cache::forget('membros:list');
            Cache::forget('membros:stats');
            Cache::forget('dashboard:stats');
            if (request()->user()) {
                Cache::forget('membros:communications:' . request()->user()->id);
            }

            return redirect()->route('membros.index')
                ->with('success', 'Membro eliminado com sucesso!');
                
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'Erro ao eliminar membro: ' . $e->getMessage());
        }
    }

    public function sendAccessEmail(Request $request, User $member): RedirectResponse
    {
        $validated = $request->validate([
            'email_utilizador' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email_utilizador')->ignore($member->id),
                Rule::unique('users', 'email')->ignore($member->id),
            ],
        ], [
            'email_utilizador.required' => 'O email de autenticação é obrigatório para enviar o acesso.',
            'email_utilizador.email' => 'O email de autenticação deve ser válido.',
            'email_utilizador.unique' => 'Este email já está em uso por outro utilizador.',
        ]);

        $member->forceFill(
            $this->syncAuthIdentityFields([
                'nome_completo' => $member->nome_completo,
                'email_utilizador' => $validated['email_utilizador'],
            ], $member)
        )->save();

        $token = Password::broker()->createToken($member);
        $member->notify(new MemberAccessSetupNotification($token));

        return back()->with('success', 'Email de acesso enviado com sucesso.');
    }
    
    // Helper methods

    /**
     * Normalize relation ids to a unique string array.
     */
    private function normalizeRelationIds(?array $ids): array
    {
        $normalized = array_map('strval', $ids ?? []);
        $normalized = array_filter($normalized, fn ($id) => $id !== '');
        return array_values(array_unique($normalized));
    }

    private function syncAuthIdentityFields(array $data, ?User $member = null): array
    {
        if (!empty($data['nome_completo'])) {
            $data['name'] = $data['nome_completo'];
        } elseif (!$member && empty($data['name'])) {
            $data['name'] = 'Membro';
        }

        if (array_key_exists('email_utilizador', $data)) {
            $emailUtilizador = trim((string) ($data['email_utilizador'] ?? ''));
            $data['email_utilizador'] = $emailUtilizador !== '' ? $emailUtilizador : null;
            $data['email'] = $emailUtilizador !== ''
                ? $emailUtilizador
                : ('member+' . Str::uuid() . '@local.test');
        } elseif (!$member && empty($data['email'])) {
            $data['email'] = 'member+' . Str::uuid() . '@local.test';
        }

        return $data;
    }

    private function legacyUserPayloadForMemberWrite(array $data): array
    {
        return array_filter([
            'name' => $data['name'] ?? null,
            'email' => $data['email'] ?? null,
            'email_utilizador' => $data['email_utilizador'] ?? null,
            'password' => $data['password'] ?? null,
            'foto_perfil' => $data['foto_perfil'] ?? null,
            'cartao_federacao' => $data['cartao_federacao'] ?? null,
            'arquivo_rgpd' => $data['arquivo_rgpd'] ?? null,
            'arquivo_consentimento' => $data['arquivo_consentimento'] ?? null,
            'arquivo_afiliacao' => $data['arquivo_afiliacao'] ?? null,
            'declaracao_transporte' => $data['declaracao_transporte'] ?? null,
            'menor' => $data['menor'] ?? null,
            'estado' => $data['estado'] ?? null,
            'perfil' => $data['perfil'] ?? null,
            'numero_socio' => $data['numero_socio'] ?? null,
            'tipo_membro' => $data['tipo_membro'] ?? null,
            'escalao' => $data['escalao'] ?? null,
            'ativo_desportivo' => $data['ativo_desportivo'] ?? null,
            'data_inscricao' => $data['data_inscricao'] ?? null,
            'data_atestado_medico' => $data['data_atestado_medico'] ?? null,
            'informacoes_medicas' => $data['informacoes_medicas'] ?? null,
            'telefone' => $data['telefone'] ?? null,
            'estado_civil' => $data['estado_civil'] ?? null,
            'notas' => $data['notas'] ?? null,
            'ocupacao' => $data['ocupacao'] ?? null,
            'empresa' => $data['empresa'] ?? null,
            'escola' => $data['escola'] ?? null,
            'numero_irmaos' => $data['numero_irmaos'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    private function hasFinancialDataPayload(array $data): bool
    {
        foreach (['tipo_mensalidade', 'discount_type', 'discount_value', 'discount_reason'] as $field) {
            if (array_key_exists($field, $data)) {
                return true;
            }
        }

        return false;
    }

    private function fillFinancialData(DadosFinanceiros $financeData, array $data): void
    {
        if (array_key_exists('tipo_mensalidade', $data)) {
            $financeData->mensalidade_id = $data['tipo_mensalidade'] ?: null;
        }

        if (array_key_exists('discount_type', $data)) {
            $financeData->discount_type = $data['discount_type'] ?: null;
        }

        if (array_key_exists('discount_value', $data)) {
            $financeData->discount_value = $data['discount_value'] !== null && $data['discount_value'] !== ''
                ? $data['discount_value']
                : null;
        }

        if (array_key_exists('discount_reason', $data)) {
            $reason = trim((string) ($data['discount_reason'] ?? ''));
            $financeData->discount_reason = $reason !== '' ? $reason : null;
        }

        if (!$financeData->discount_type || $financeData->discount_value === null || (float) $financeData->discount_value <= 0) {
            $financeData->discount_type = null;
            $financeData->discount_value = null;

            if (array_key_exists('discount_reason', $data) && blank($financeData->discount_reason)) {
                $financeData->discount_reason = null;
            }
        }
    }

    /**
     * Sync guardians and mirror the relationship on each guardian.
     */
    private function syncGuardianRelations(User $member, array $guardianIds, ?string $memberKey = null): void
    {
        $memberKey = $memberKey ?: $this->resolveUserKey($member);
        $guardianIds = $this->normalizeRelationIds($guardianIds);
        $currentGuardianIds = $this->normalizeRelationIds(
            User::query()->whereKey($memberKey)->value('encarregado_educacao')
        );
        $this->persistRelationAttributeById($memberKey, 'encarregado_educacao', $guardianIds);

        $added = array_diff($guardianIds, $currentGuardianIds);
        $removed = array_diff($currentGuardianIds, $guardianIds);

        $this->replaceGuardianPivotRowsForMember($memberKey, $guardianIds);

        if (!empty($added) || !empty($removed)) {
            $affectedIds = array_values(array_unique(array_merge($added, $removed)));
            $guardians = User::whereIn('id', $affectedIds)->get()->keyBy('id');

            foreach ($added as $guardianId) {
                if ($guardians->has($guardianId)) {
                    $guardian = $guardians[$guardianId];
                    $educandos = $this->normalizeRelationIds($guardian->educandos);
                    if (!in_array($memberKey, $educandos, true)) {
                        $educandos[] = $memberKey;
                    }
                    $this->persistRelationAttribute(
                        $guardianId,
                        'educandos',
                        array_values(array_unique($educandos))
                    );
                }
            }

            foreach ($removed as $guardianId) {
                if ($guardians->has($guardianId)) {
                    $guardian = $guardians[$guardianId];
                    $this->persistRelationAttribute(
                        $guardianId,
                        'educandos',
                        array_values(array_filter(
                            $this->normalizeRelationIds($guardian->educandos),
                            fn (string $educandoId) => $educandoId !== $memberKey
                        ))
                    );
                }
            }
        }

        if ($guardianIds !== []) {
            $currentGuardians = User::whereIn('id', $guardianIds)->get()->keyBy('id');
            foreach ($guardianIds as $guardianId) {
                $guardian = $currentGuardians->get($guardianId);
                if (!$guardian) {
                    continue;
                }

                $educandos = $this->normalizeRelationIds($guardian->educandos);
                if (!in_array($memberKey, $educandos, true)) {
                    $educandos[] = $memberKey;
                    $this->persistRelationAttribute(
                        $guardianId,
                        'educandos',
                        array_values(array_unique($educandos))
                    );
                }
            }
        }
    }

    /**
     * Sync educandos and mirror the relationship on each educando.
     */
    private function syncEducandoRelations(User $member, array $educandoIds, ?string $memberKey = null): void
    {
        $memberKey = $memberKey ?: $this->resolveUserKey($member);
        $educandoIds = $this->normalizeRelationIds($educandoIds);
        $currentEducandoIds = $this->normalizeRelationIds(
            User::query()->whereKey($memberKey)->value('educandos')
        );
        $this->persistRelationAttributeById($memberKey, 'educandos', $educandoIds);

        $added = array_diff($educandoIds, $currentEducandoIds);
        $removed = array_diff($currentEducandoIds, $educandoIds);

        $this->replaceEducandoPivotRowsForGuardian($memberKey, $educandoIds);

        if (!empty($added) || !empty($removed)) {
            $affectedIds = array_values(array_unique(array_merge($added, $removed)));
            $educandos = User::whereIn('id', $affectedIds)->get()->keyBy('id');

            foreach ($added as $educandoId) {
                if ($educandos->has($educandoId)) {
                    $educando = $educandos[$educandoId];
                    $guardianIds = $this->normalizeRelationIds($educando->encarregado_educacao);
                    if (!in_array($memberKey, $guardianIds, true)) {
                        $guardianIds[] = $memberKey;
                    }
                    $this->persistRelationAttribute(
                        $educandoId,
                        'encarregado_educacao',
                        array_values(array_unique($guardianIds))
                    );
                }
            }

            foreach ($removed as $educandoId) {
                if ($educandos->has($educandoId)) {
                    $educando = $educandos[$educandoId];
                    $this->persistRelationAttribute(
                        $educandoId,
                        'encarregado_educacao',
                        array_values(array_filter(
                            $this->normalizeRelationIds($educando->encarregado_educacao),
                            fn (string $guardianId) => $guardianId !== $memberKey
                        ))
                    );
                }
            }
        }

        if ($educandoIds !== []) {
            $currentEducandos = User::whereIn('id', $educandoIds)->get()->keyBy('id');
            foreach ($educandoIds as $educandoId) {
                $educando = $currentEducandos->get($educandoId);
                if (!$educando) {
                    continue;
                }

                $guardianIds = $this->normalizeRelationIds($educando->encarregado_educacao);
                if (!in_array($memberKey, $guardianIds, true)) {
                    $guardianIds[] = $memberKey;
                    $this->persistRelationAttribute(
                        $educandoId,
                        'encarregado_educacao',
                        array_values(array_unique($guardianIds))
                    );
                }
            }
        }
    }

    private function persistRelationAttribute(string $userId, string $attribute, array $ids): void
    {
        $this->persistRelationAttributeById($userId, $attribute, $ids);
    }

    private function persistRelationAttributeById(string $userId, string $attribute, array $ids): void
    {
        User::whereKey($userId)->update([$attribute => $ids]);
    }

    private function resolveUserKey(User $user): string
    {
        $key = $user->getKey() ?? $user->getOriginal('id') ?? $user->getAttribute('id');

        if (!$key) {
            throw new \RuntimeException('User key is missing during member relationship sync.');
        }

        return (string) $key;
    }

    private function replaceGuardianPivotRowsForMember(string $memberKey, array $guardianIds): void
    {
        DB::table('user_guardian')->where('user_id', $memberKey)->delete();

        if ($guardianIds === []) {
            return;
        }

        $timestamp = now();
        DB::table('user_guardian')->insert(array_map(
            fn (string $guardianId) => [
                'id' => (string) Str::uuid(),
                'user_id' => $memberKey,
                'guardian_id' => $guardianId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            $guardianIds
        ));
    }

    private function replaceEducandoPivotRowsForGuardian(string $guardianKey, array $educandoIds): void
    {
        DB::table('user_guardian')->where('guardian_id', $guardianKey)->delete();

        if ($educandoIds === []) {
            return;
        }

        $timestamp = now();
        DB::table('user_guardian')->insert(array_map(
            fn (string $educandoId) => [
                'id' => (string) Str::uuid(),
                'user_id' => $educandoId,
                'guardian_id' => $guardianKey,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            $educandoIds
        ));
    }

    private function reconcileLegacyRelationState(string $memberKey): void
    {
        $legacyEducandoIds = $this->normalizeRelationIds(
            User::query()->whereKey($memberKey)->value('educandos')
        );
        $legacyGuardianIds = $this->normalizeRelationIds(
            User::query()->whereKey($memberKey)->value('encarregado_educacao')
        );

        $pivotEducandoIds = DB::table('user_guardian')
            ->where('guardian_id', $memberKey)
            ->pluck('user_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        $pivotGuardianIds = DB::table('user_guardian')
            ->where('user_id', $memberKey)
            ->pluck('guardian_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($legacyEducandoIds !== $pivotEducandoIds) {
            $this->replaceEducandoPivotRowsForGuardian($memberKey, $legacyEducandoIds);
        }

        if ($legacyGuardianIds !== $pivotGuardianIds) {
            $this->replaceGuardianPivotRowsForMember($memberKey, $legacyGuardianIds);
        }
    }
    
    /**
     * Check if string is base64 encoded data
     */
    private function isBase64(string $data): bool
    {
        // Check if it starts with a data URI scheme and has base64 encoding
        return preg_match('/^data:[\w\/\-\.+]+;base64,/', $data) === 1;
    }
    
    /**
     * Store base64 encoded image to storage
     */
    private function storeBase64Image(string $base64, string $path): string
    {
        // Extract the base64 data
        if (preg_match('/^data:image\/([a-zA-Z0-9\+\-\.]+);base64,/', $base64, $type)) {
            $base64 = substr($base64, strpos($base64, ',') + 1);
            $extension = strtolower($type[1]);
            if ($extension === 'svg+xml') {
                $extension = 'svg';
            }
            
            // Validate image type
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
            if (!in_array($extension, $allowedExtensions)) {
                throw new \InvalidArgumentException('Tipo de imagem inválido. Permitidos: ' . implode(', ', $allowedExtensions));
            }
        } else {
            throw new \InvalidArgumentException('Formato de imagem inválido.');
        }
        
        $data = base64_decode($base64, true);
        if ($data === false) {
            throw new \InvalidArgumentException('Falha ao decodificar imagem base64.');
        }
        
        // Validate file size (max 5MB)
        if (strlen($data) > 5 * 1024 * 1024) {
            throw new \InvalidArgumentException('Tamanho da imagem excede 5MB.');
        }
        
        $filename = Str::uuid() . '.' . $extension;
        $filePath = $path . '/' . $filename;
        
        Storage::disk('public')->put($filePath, $data);
        
        return $filePath;
    }
    
    /**
     * Store file (base64 or path) to storage
     */
    private function storeFile(string $base64OrPath, string $path): string
    {
        // Extract the base64 data
        if (preg_match('/^data:([^;]+);base64,/', $base64OrPath, $type)) {
            $base64 = substr($base64OrPath, strpos($base64OrPath, ',') + 1);
            $mimeType = $type[1];
            
            // Determine extension from mime type
            $mimeToExt = [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/jpg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            ];
            
            if (!isset($mimeToExt[$mimeType])) {
                throw new \InvalidArgumentException('Tipo de arquivo não suportado: ' . $mimeType);
            }
            
            $extension = $mimeToExt[$mimeType];
        } else {
            throw new \InvalidArgumentException('Formato de arquivo inválido.');
        }
        
        $data = base64_decode($base64, true);
        if ($data === false) {
            throw new \InvalidArgumentException('Falha ao decodificar arquivo base64.');
        }
        
        // Validate file size (max 10MB)
        if (strlen($data) > 10 * 1024 * 1024) {
            throw new \InvalidArgumentException('Tamanho do arquivo excede 10MB.');
        }
        
        $filename = Str::uuid() . '.' . $extension;
        $filePath = $path . '/' . $filename;
        
        Storage::disk('public')->put($filePath, $data);
        
        return $filePath;
    }
    
    /**
     * Delete file from storage if exists
     */
    private function deleteFile(?string $path): void
    {
        if (!$path) {
            return;
        }

        $normalizedPath = $path;
        if (str_starts_with($normalizedPath, 'http')) {
            $parsed = parse_url($normalizedPath, PHP_URL_PATH);
            $normalizedPath = $parsed ? $parsed : $normalizedPath;
        }
        if (str_starts_with($normalizedPath, '/storage/')) {
            $normalizedPath = substr($normalizedPath, strlen('/storage/'));
        }

        if (Storage::disk('public')->exists($normalizedPath)) {
            Storage::disk('public')->delete($normalizedPath);
        }
    }
    
    /**
     * Generate sequential member number
     */
    private function generateMemberNumber(): string
    {
        $currentYear = now()->format('Y');
        $yearPrefix = $currentYear . '-';

        $yearNumbers = User::whereNotNull('numero_socio')
            ->where('numero_socio', 'like', $yearPrefix . '%')
            ->pluck('numero_socio');

        $maxYearSuffix = 0;
        foreach ($yearNumbers as $numero) {
            if (preg_match('/^' . $currentYear . '-(\d+)$/', $numero, $matches)) {
                $maxYearSuffix = max($maxYearSuffix, (int) $matches[1]);
            }
        }

        if ($yearNumbers->isNotEmpty()) {
            $nextSuffix = $maxYearSuffix + 1;
            return $currentYear . '-' . str_pad((string) $nextSuffix, 4, '0', STR_PAD_LEFT);
        }

        $lastNumeric = User::whereNotNull('numero_socio')
            ->where('numero_socio', 'not like', '%-%')
            ->orderByRaw('CAST(numero_socio as INTEGER) desc')
            ->first();

        $nextNumber = $lastNumeric && $lastNumeric->numero_socio
            ? ((int) $lastNumeric->numero_socio) + 1
            : 0;

        return $currentYear . '-' . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}

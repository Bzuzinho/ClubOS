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
use App\Services\Family\FamilyRelationshipService;
use App\Services\Family\FamilyService;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Financeiro\MemberCostCenterResolver;
use App\Services\Financeiro\MemberCostCenterSyncService;
use App\Services\Financeiro\MemberMonthlyFeeEligibilityService;
use App\Services\Financeiro\MemberMonthlyFeeLifecycleService;
use App\Services\Financeiro\MemberMonthlyFeeResolver;
use App\Services\Financeiro\MemberMonthlyFeeSyncService;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberDocumentDataResolver;
use App\Services\Members\MemberIdentityDisplayResolver;
use App\Services\Members\MemberTypeResolver;
use App\Services\Members\MemberDataWriteService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Carbon\Carbon;

class MembrosController extends Controller
{
    public function __construct(
        private readonly InternalCommunicationService $internalCommunicationService,
        private readonly MemberDataWriteService $memberDataWriteService,
        private readonly MemberDocumentDataResolver $memberDocumentDataResolver,
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
        private readonly MemberCostCenterSyncService $memberCostCenterSyncService,
        private readonly MemberMonthlyFeeEligibilityService $memberMonthlyFeeEligibilityService,
        private readonly MemberMonthlyFeeLifecycleService $memberMonthlyFeeLifecycleService,
        private readonly MemberMonthlyFeeResolver $memberMonthlyFeeResolver,
        private readonly MemberMonthlyFeeSyncService $memberMonthlyFeeSyncService,
        private readonly MemberTypeResolver $memberTypeResolver,
    )
    {
    }

    public function index(Request $request): Response
    {
        $perPage = min(max((int) $request->integer('per_page', 50), 10), 100);
        $search = trim((string) $request->string('search')->value());
        $status = trim((string) $request->string('status')->value());
        $sportsStatus = trim((string) $request->string('sports_status')->value());
        $monthlyFeeStatus = trim((string) $request->string('monthly_fee_status')->value());
        $type = $this->memberTypeResolver->normalizeType(
            trim((string) $request->string('type')->value())
        );
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $userTypes = Cache::remember('membros:user_types', 300, fn () =>
            UserType::where('ativo', true)->select('id', 'codigo', 'nome')->get()
        );
        $selectedUserType = $userTypes->first(function (UserType $userType) use ($type): bool {
            $candidate = (string) ($userType->codigo ?: $userType->nome);

            return $type !== '' && $this->memberTypeResolver->normalizeType($candidate) === $type;
        });

        $membersPaginator = User::query()
            ->with(['dadosPessoais:id,user_id,nome_completo', 'userTypes:id,codigo,nome'])
            ->select([
                'id',
                'numero_socio',
                'name',
                'email_utilizador',
                'foto_perfil',
                'estado',
                'tipo_membro',
                'ativo_desportivo',
                'escalao',
                'created_at',
            ])
            ->when($search !== '', function ($query) use ($search, $operator) {
                $query->where(function ($searchQuery) use ($search, $operator) {
                    $searchQuery
                        ->where('name', $operator, '%'.$search.'%')
                        ->orWhere('nome_completo', $operator, '%'.$search.'%')
                        ->orWhere('numero_socio', $operator, '%'.$search.'%')
                        ->orWhere('email_utilizador', $operator, '%'.$search.'%')
                        ->orWhere('nif', $operator, '%'.$search.'%')
                        ->orWhereHas('dadosPessoais', function ($personalQuery) use ($search, $operator): void {
                            $personalQuery
                                ->where('nome_completo', $operator, '%'.$search.'%')
                                ->orWhere('nif', $operator, '%'.$search.'%');
                        });
                });
            })
            ->when(in_array($status, ['ativo', 'inativo', 'suspenso'], true), fn ($query) => $query->where('estado', $status))
            ->when(in_array($sportsStatus, ['ativo', 'inativo'], true), fn ($query) => $query->where('ativo_desportivo', $sportsStatus === 'ativo'))
            ->when($monthlyFeeStatus === 'defined', function ($query): void {
                $query->whereHas('dadosFinanceiros', fn ($financeQuery) => $financeQuery->whereNotNull('mensalidade_id'));
            })
            ->when($monthlyFeeStatus === 'undefined', function ($query): void {
                $query->whereDoesntHave('dadosFinanceiros', fn ($financeQuery) => $financeQuery->whereNotNull('mensalidade_id'));
            })
            ->when($selectedUserType, function ($query) use ($selectedUserType, $type): void {
                $query->where(function ($typeQuery) use ($selectedUserType, $type): void {
                    $typeQuery->whereHas('userTypes', fn ($userTypeQuery) => $userTypeQuery->whereKey($selectedUserType->id));

                    collect([$type, $selectedUserType->nome, $selectedUserType->codigo])
                        ->filter(fn ($candidate): bool => is_string($candidate) && trim($candidate) !== '')
                        ->unique()
                        ->each(fn (string $candidate) => $typeQuery->orWhereJsonContains('tipo_membro', $candidate));
                });
            })
            ->orderByRaw('COALESCE(nome_completo, name)')
            ->paginate($perPage)
            ->withQueryString();

        $membersPaginator->getCollection()->transform(function (User $member): User {
            $member->setAttribute('nome_completo', $this->memberIdentityDisplayResolver->displayName($member));
            $member->setAttribute('tipo_membro', $this->memberTypeResolver->typesFor($member));

            unset($member->dadosPessoais, $member->userTypes, $member->name);

            return $member;
        });

        $members = $membersPaginator->getCollection()->values();

        $ageGroups = Cache::remember('membros:age_groups', 300, fn () =>
            AgeGroup::select('id', 'nome')->get()
        );

        // All stats in a single cache entry — avoids 8+ separate roundtrips
        $stats = Cache::remember('membros:stats', 60, function () use ($userTypes, $ageGroups) {
            $statsMembers = User::query()
                ->select(['id', 'estado', 'tipo_membro', 'ativo_desportivo', 'escalao', 'created_at'])
                ->get();
            $membersByUserType = [];
            $athletesByAgeGroup = [];
            $canonicalTypeLabels = $userTypes
                ->mapWithKeys(function (UserType $type): array {
                    $normalized = $this->memberTypeResolver->normalizeType((string) ($type->codigo ?: $type->nome));
                    if ($normalized === '') {
                        return [];
                    }

                    return [$normalized => $type->nome];
                })
                ->all();

            foreach ($statsMembers as $member) {
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
            foreach ($canonicalTypeLabels as $typeCode => $typeLabel) {
                $count = $membersByUserType[$typeCode] ?? 0;
                if ($count > 0) {
                    $tipoMembrosStats[] = ['tipo' => $typeLabel, 'count' => $count];
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
            $athletes = $statsMembers->filter(fn (User $member) => $this->memberTypeResolver->isAthlete($member));

            return [
                'counts' => [
                    'totalMembros'      => $statsMembers->count(),
                    'membrosAtivos'     => $statsMembers->where('estado', 'ativo')->count(),
                    'membrosInativos'   => $statsMembers->where('estado', 'inativo')->count(),
                    'totalAtletas'      => $athletes->count(),
                    'atletasAtivos'     => $athletes->where('ativo_desportivo', true)->count(),
                    'encarregados'      => $statsMembers->filter(fn (User $member) => $this->memberTypeResolver->isGuardian($member))->count(),
                    'treinadores'       => $statsMembers->filter(fn (User $member) => $this->memberTypeResolver->isTrainer($member))->count(),
                    'novosUltimos30Dias' => $statsMembers->filter(static fn ($member) => optional($member->created_at)?->greaterThanOrEqualTo($createdThreshold))->count(),
                ],
                'tipoMembrosStats' => $tipoMembrosStats,
                'escaloesStats'    => $escaloesStats,
            ];
        });

        return Inertia::render('Membros/Index', [
            'members' => $members,
            'membersPagination' => [
                'current_page' => $membersPaginator->currentPage(),
                'per_page' => $membersPaginator->perPage(),
                'total' => $membersPaginator->total(),
                'last_page' => $membersPaginator->lastPage(),
                'from' => $membersPaginator->firstItem(),
                'to' => $membersPaginator->lastItem(),
                'links' => $membersPaginator->linkCollection()->toArray(),
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'sports_status' => in_array($sportsStatus, ['ativo', 'inativo'], true) ? $sportsStatus : null,
                'monthly_fee_status' => in_array($monthlyFeeStatus, ['defined', 'undefined'], true) ? $monthlyFeeStatus : null,
                'type' => $selectedUserType ? $type : null,
            ],
            'userTypes' => $userTypes,
            'ageGroups' => $ageGroups,
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

            if (array_key_exists('tipo_mensalidade', $data)) {
                try {
                    $this->memberMonthlyFeeSyncService->sync($member, $data['tipo_mensalidade']);
                } catch (InvalidArgumentException $exception) {
                    return redirect()->back()
                        ->withInput()
                        ->withErrors(['tipo_mensalidade' => 'A mensalidade selecionada nao e valida.']);
                }
            }
            
            if (isset($data['user_types'])) {
                $member->userTypes()->sync($data['user_types']);
            }

            $familyRelationshipService = app(FamilyRelationshipService::class);

            if ($request->boolean('sync_encarregado_educacao')) {
                $familyRelationshipService->replaceGuardiansForMember(
                    $member,
                    is_array($data['encarregado_educacao'] ?? null) ? $data['encarregado_educacao'] : [],
                );
            }

            if ($request->boolean('sync_educandos')) {
                $familyRelationshipService->replaceDependentsForGuardian(
                    $member,
                    is_array($data['educandos'] ?? null) ? $data['educandos'] : [],
                );
            }

            if (array_key_exists('centro_custo', $data) && is_array($data['centro_custo'])) {
                $this->syncMemberCostCenters($member, $data['centro_custo']);
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

        // Canonical member data and relationships are read from relational sources.
        $member->loadMissing(['dadosPessoais', 'dadosConfiguracao']);
        $memberData = app(MemberDataReadService::class)->mergedMemberPayload($member, $memberData);
        $memberData = array_merge($memberData, $this->memberDocumentDataResolver->memberDocumentPayload($member));
        $memberData['memberTypes'] = $this->memberTypeResolver->typesFor($member);

        $memberDataReadService = app(MemberDataReadService::class);

        $allUsers = User::query()
            ->with([
                'dadosPessoais:id,user_id,nome_completo,data_nascimento',
                'userTypes:id,codigo,nome',
            ])
            ->select(
                'id',
                'name',
                'numero_socio',
                'tipo_membro',
                'foto_perfil',
                'menor',
                'estado'
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
                    'tipo_membro' => $this->memberTypeResolver->typesFor($user),
                    'foto_perfil' => $user->foto_perfil,
                    'menor' => $user->menor,
                    'estado' => $user->estado,
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
        $memberData['tipo_mensalidade'] = $this->memberMonthlyFeeResolver->resolveForUser($member);
        $memberData['discount_type'] = $member->dadosFinanceiros?->discount_type;
        $memberData['discount_value'] = $member->dadosFinanceiros?->discount_value !== null
            ? (float) $member->dadosFinanceiros->discount_value
            : null;
        $memberData['discount_reason'] = $member->dadosFinanceiros?->discount_reason;
        $resolvedCostCenters = $this->memberCostCenterResolver->resolveForUser($member);
        $memberData['centro_custo'] = collect($resolvedCostCenters['centro_custo'] ?? [])->values();
        $memberData['centro_custo_pesos'] = collect($resolvedCostCenters['centro_custo_pesos'] ?? [])->values();

        $canManageFamilyRelations = $viewer
            ? $accessControlService->canAccessPermission($viewer, 'membros.ficha', 'edit')
            : false;
        $internalCommunications = ['received' => [], 'sent' => []];
        if ($this->shouldLoadMemberCommunications()) {
            $internalCommunications = [
                'received' => $this->internalCommunicationService->receivedFeed($memberKey),
                'sent' => $this->internalCommunicationService->sentFeed($memberKey),
            ];
        }

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
            'internalCommunications' => $internalCommunications,
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
        $guardianUsers = $member->relationLoaded('encarregados')
            ? $member->getRelation('encarregados')
            : $member->encarregados()->get();
        $dependentUsers = $member->relationLoaded('educandos')
            ? $member->getRelation('educandos')
            : $member->educandos()->get();

        $guardians = collect($guardianUsers)
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

        $dependents = collect($dependentUsers)
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
            ->map(function ($family) use ($member) {
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
                        collect($family->members ?? [])->firstWhere('id', $member->id)
                    )->pivot?->papel_na_familia,
                    'members' => $members,
                ];
            })
            ->values();

        $memberTypeCodes = collect($this->memberTypeResolver->typesFor($member))->values();

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
        return $this->memberIdentityDisplayResolver->displayNameOrFallback($user, 'Sem nome');
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

        $member->tipo_mensalidade = $this->memberMonthlyFeeResolver->resolveForUser($member);
        $member->discount_type = $member->dadosFinanceiros?->discount_type;
        $member->discount_value = $member->dadosFinanceiros?->discount_value !== null
            ? (float) $member->dadosFinanceiros->discount_value
            : null;
        $member->discount_reason = $member->dadosFinanceiros?->discount_reason;
        $resolvedCostCenters = $this->memberCostCenterResolver->resolveForUser($member);
        $member->centro_custo = collect($resolvedCostCenters['centro_custo'] ?? [])->values();
        $member->centro_custo_pesos = collect($resolvedCostCenters['centro_custo_pesos'] ?? [])->values();
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
            $memberBeforeEligibilityWrite = User::query()
                ->with(['userTypes', 'dadosFinanceiros', 'centrosCusto'])
                ->whereKey($memberKey)
                ->firstOrFail();
            $previouslyEligibleForMonthlyFee = $this->memberMonthlyFeeEligibilityService
                ->shouldHaveMonthlyFee($memberBeforeEligibilityWrite);
            $previousMonthlyTerms = $this->monthlyFeeTermsSnapshot($memberBeforeEligibilityWrite);

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

            $member = DB::transaction(function () use ($member, $data, $memberKey, $previouslyEligibleForMonthlyFee, $previousMonthlyTerms): User {
                $member->update($this->legacyUserPayloadForMemberWrite($data));
                $member->refresh();

                $this->memberDataWriteService->persistFromMemberRequest($member, $data, $memberKey);

                if ($this->hasFinancialDataPayload($data)) {
                    $financeData = DadosFinanceiros::firstOrNew(['user_id' => $member->id]);
                    $this->fillFinancialData($financeData, $data);
                    $financeData->save();
                }

                if (array_key_exists('tipo_mensalidade', $data)) {
                    $this->memberMonthlyFeeSyncService->sync($member, $data['tipo_mensalidade']);
                }

                if (array_key_exists('centro_custo', $data) && is_array($data['centro_custo'])) {
                    $this->syncMemberCostCenters($member, $data['centro_custo']);
                }

                if (isset($data['user_types'])) {
                    $member->userTypes()->sync($data['user_types']);
                }

                $memberAfterEligibilityWrite = User::query()
                    ->with(['userTypes', 'dadosFinanceiros', 'centrosCusto'])
                    ->whereKey($memberKey)
                    ->firstOrFail();
                $currentlyEligibleForMonthlyFee = $this->memberMonthlyFeeEligibilityService
                    ->shouldHaveMonthlyFee($memberAfterEligibilityWrite);
                $currentMonthlyTerms = $this->monthlyFeeTermsSnapshot($memberAfterEligibilityWrite);

                $this->memberMonthlyFeeLifecycleService->reconcileEligibilityTransition(
                    $memberAfterEligibilityWrite,
                    $previouslyEligibleForMonthlyFee,
                    $currentlyEligibleForMonthlyFee,
                );

                if (
                    $previouslyEligibleForMonthlyFee
                    && $currentlyEligibleForMonthlyFee
                    && $this->monthlyFeeTermsChanged($previousMonthlyTerms, $currentMonthlyTerms)
                ) {
                    $this->memberMonthlyFeeLifecycleService->reconcileFutureMonthlyTerms($memberAfterEligibilityWrite);
                }

                return $memberAfterEligibilityWrite;
            });

            $familyRelationshipService = app(FamilyRelationshipService::class);

            if ($request->boolean('sync_encarregado_educacao')) {
                $familyRelationshipService->replaceGuardiansForMember(
                    $member,
                    is_array($data['encarregado_educacao'] ?? null) ? $data['encarregado_educacao'] : [],
                );
            }

            if ($request->boolean('sync_educandos')) {
                $familyRelationshipService->replaceDependentsForGuardian(
                    $member,
                    is_array($data['educandos'] ?? null) ? $data['educandos'] : [],
                );
            }

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
                'nome_completo' => $this->memberIdentityDisplayResolver->displayNameOrFallback($member, 'Membro'),
                'email_utilizador' => $validated['email_utilizador'],
            ], $member)
        )->save();

        $token = Password::broker()->createToken($member);
        $member->notify(new MemberAccessSetupNotification($token));

        return back()->with('success', 'Email de acesso enviado com sucesso.');
    }
    
    // Helper methods

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
            'notas' => $data['notas'] ?? null,
            'ocupacao' => $data['ocupacao'] ?? null,
            'empresa' => $data['empresa'] ?? null,
            'escola' => $data['escola'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    private function shouldLoadMemberCommunications(): bool
    {
        $request = request();
        $partialData = collect(explode(',', (string) $request->headers->get('X-Inertia-Partial-Data')))
            ->map(static fn (string $value): string => trim($value))
            ->filter()
            ->values();

        return $request->string('tab')->value() === 'communications'
            || $request->filled('message')
            || $partialData->contains('internalCommunications');
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

    /**
     * @return array<string, mixed>
     */
    private function monthlyFeeTermsSnapshot(User $member): array
    {
        $member->loadMissing(['dadosFinanceiros', 'centrosCusto']);
        $costCenters = collect($this->memberCostCenterResolver->resolveForUser($member)['centro_custo_pesos'] ?? [])
            ->map(fn (array $row): array => [
                'id' => (string) ($row['id'] ?? ''),
                'peso' => round((float) ($row['peso'] ?? 1), 6),
            ])
            ->filter(fn (array $row): bool => $row['id'] !== '')
            ->sortBy('id')
            ->values()
            ->all();

        return [
            'mensalidade_id' => $this->memberMonthlyFeeResolver->resolveForUser($member),
            'discount_type' => $member->dadosFinanceiros?->discount_type ?: null,
            'discount_value' => $member->dadosFinanceiros?->discount_value !== null
                ? round((float) $member->dadosFinanceiros->discount_value, 2)
                : null,
            'cost_centers' => $costCenters,
        ];
    }

    private function monthlyFeeTermsChanged(array $previous, array $current): bool
    {
        return $previous !== $current;
    }

    /**
     * @param array<int, mixed> $centros
     */
    private function syncMemberCostCenters(User $member, array $centros): void
    {
        $this->memberCostCenterSyncService->sync($member, $centros);
    }

    private function fillFinancialData(DadosFinanceiros $financeData, array $data): void
    {
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

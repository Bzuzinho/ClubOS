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
use App\Services\Communication\InternalCommunicationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Carbon\Carbon;

class MembrosController extends Controller
{
    public function __construct(private readonly InternalCommunicationService $internalCommunicationService)
    {
    }

    public function index(Request $request): Response
    {
        // members list — 60s TTL, invalidated on store/update/destroy
        $members = Cache::remember('membros:list', 60, fn () =>
            User::select([
                'id', 'numero_socio', 'nome_completo', 'email_utilizador',
                'foto_perfil', 'estado', 'tipo_membro', 'ativo_desportivo', 'escalao', 'created_at',
            ])->orderBy('nome_completo')->get()
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
            
            $member = User::create($data);

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
        if ($member->data_nascimento) {
            $memberData['data_nascimento'] = $member->data_nascimento->format('Y-m-d');
        }
        $allUsers = User::select(
            'id',
            'nome_completo',
            'numero_socio',
            'tipo_membro',
            'foto_perfil',
            'menor',
            'data_nascimento'
        )->get();

        $faturas = Invoice::where('user_id', $member->id)
            ->orderBy('data_emissao', 'desc')
            ->get()
            ->map(function ($fatura) {
                $fatura->valor_total = (float) $fatura->valor_total;
                return $fatura;
            });

        $movimentos = Movement::where('user_id', $member->id)
            ->orderBy('data_emissao', 'desc')
            ->get()
            ->map(function ($movimento) {
                $movimento->valor_total = (float) $movimento->valor_total;
                return $movimento;
            });

        $contaCorrente = (float) $faturas
            ->filter(function ($fatura) {
                if (!in_array($fatura->estado_pagamento, ['pendente', 'vencido'], true)) {
                    return false;
                }

                if (!$fatura->data_fatura) {
                    return true;
                }

                return $fatura->data_fatura->startOfDay()->lte(now()->startOfDay());
            })
            ->sum('valor_total');

        $contaCorrenteManual = $member->dadosFinanceiros?->conta_corrente_manual ?? 0;
        $memberData['conta_corrente'] = $contaCorrente + (float) $contaCorrenteManual;
        $memberData['conta_corrente_manual'] = (float) $contaCorrenteManual;
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

        return Inertia::render('Membros/Show', [
            'member' => $memberData,
            'allUsers' => $allUsers,
            'internalCommunications' => [
                'received' => $this->internalCommunicationService->receivedFeed($member->id),
                'sent' => $this->internalCommunicationService->sentFeed($member->id),
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

    public function edit(User $member): Response
    {
        if ($member->data_nascimento) {
            $member->data_nascimento = $member->data_nascimento->format('Y-m-d');
        }
        $member->load(['dadosFinanceiros', 'centrosCusto']);
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
        return Inertia::render('Membros/Edit', [
            'member' => $member->load(['userTypes', 'ageGroup', 'encarregados', 'educandos', 'dadosFinanceiros', 'centrosCusto']),
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
            $data = $request->validated();

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
            
            if (isset($data['cartao_federacao']) && $this->isBase64($data['cartao_federacao'])) {
                $this->deleteFile($member->cartao_federacao);
                $data['cartao_federacao'] = $this->storeFile($data['cartao_federacao'], 'members/federation_cards');
            }
            
            if (isset($data['arquivo_rgpd']) && $this->isBase64($data['arquivo_rgpd'])) {
                $this->deleteFile($member->arquivo_rgpd);
                $data['arquivo_rgpd'] = $this->storeFile($data['arquivo_rgpd'], 'members/gdpr');
            }
            
            if (isset($data['arquivo_consentimento']) && $this->isBase64($data['arquivo_consentimento'])) {
                $this->deleteFile($member->arquivo_consentimento);
                $data['arquivo_consentimento'] = $this->storeFile($data['arquivo_consentimento'], 'members/consent');
            }
            
            if (isset($data['arquivo_afiliacao']) && $this->isBase64($data['arquivo_afiliacao'])) {
                $this->deleteFile($member->arquivo_afiliacao);
                $data['arquivo_afiliacao'] = $this->storeFile($data['arquivo_afiliacao'], 'members/affiliation');
            }
            
            if (isset($data['declaracao_transporte']) && $this->isBase64($data['declaracao_transporte'])) {
                $this->deleteFile($member->declaracao_transporte);
                $data['declaracao_transporte'] = $this->storeFile($data['declaracao_transporte'], 'members/transport');
            }
            
            $data = $this->syncAuthIdentityFields($data, $member);

            $member->update($data);

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
                    is_array($data['encarregado_educacao'] ?? null) ? $data['encarregado_educacao'] : []
                );
            }

            if ($request->boolean('sync_educandos')) {
                $this->syncEducandoRelations(
                    $member,
                    is_array($data['educandos'] ?? null) ? $data['educandos'] : []
                );
            }

            Cache::forget('membros:list');
            Cache::forget('membros:stats');
            if ($request->user()) {
                Cache::forget('membros:communications:' . $request->user()->id);
            }

            return redirect()->route('membros.show', $member)
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
            
            // Delete all associated files
            $this->deleteFile($member->foto_perfil);
            $this->deleteFile($member->cartao_federacao);
            $this->deleteFile($member->arquivo_rgpd);
            $this->deleteFile($member->arquivo_consentimento);
            $this->deleteFile($member->arquivo_afiliacao);
            $this->deleteFile($member->declaracao_transporte);
            
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

    private function hasFinancialDataPayload(array $data): bool
    {
        foreach (['tipo_mensalidade', 'conta_corrente_manual', 'discount_type', 'discount_value', 'discount_reason'] as $field) {
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

        if (array_key_exists('conta_corrente_manual', $data)) {
            $financeData->conta_corrente_manual = $data['conta_corrente_manual'] ?? 0;
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
    private function syncGuardianRelations(User $member, array $guardianIds): void
    {
        $guardianIds = $this->normalizeRelationIds($guardianIds);
        $currentGuardianIds = $this->normalizeRelationIds(
            $member->encarregados()->pluck('users.id')->all()
        );

        $member->encarregados()->sync($guardianIds);
        $member->encarregado_educacao = $guardianIds;
        $member->saveQuietly();

        $added = array_diff($guardianIds, $currentGuardianIds);
        $removed = array_diff($currentGuardianIds, $guardianIds);

        if (!empty($added) || !empty($removed)) {
            $affectedIds = array_values(array_unique(array_merge($added, $removed)));
            $guardians = User::whereIn('id', $affectedIds)->get()->keyBy('id');

            foreach ($added as $guardianId) {
                if ($guardians->has($guardianId)) {
                    $guardian = $guardians[$guardianId];
                    $guardian->educandos()->syncWithoutDetaching([$member->id]);
                    $educandos = $this->normalizeRelationIds($guardian->educandos);
                    if (!in_array((string) $member->id, $educandos, true)) {
                        $educandos[] = (string) $member->id;
                    }
                    $guardian->educandos = array_values(array_unique($educandos));
                    $guardian->saveQuietly();
                }
            }

            foreach ($removed as $guardianId) {
                if ($guardians->has($guardianId)) {
                    $guardian = $guardians[$guardianId];
                    $guardian->educandos()->detach($member->id);
                    $guardian->educandos = array_values(array_filter(
                        $this->normalizeRelationIds($guardian->educandos),
                        fn (string $educandoId) => $educandoId !== (string) $member->id
                    ));
                    $guardian->saveQuietly();
                }
            }
        }

        if ($guardianIds !== []) {
            $currentGuardians = User::whereIn('id', $guardianIds)->get();
            foreach ($currentGuardians as $guardian) {
                $educandos = $this->normalizeRelationIds($guardian->educandos);
                if (!in_array((string) $member->id, $educandos, true)) {
                    $educandos[] = (string) $member->id;
                    $guardian->educandos = array_values(array_unique($educandos));
                    $guardian->saveQuietly();
                }
            }
        }
    }

    /**
     * Sync educandos and mirror the relationship on each educando.
     */
    private function syncEducandoRelations(User $member, array $educandoIds): void
    {
        $educandoIds = $this->normalizeRelationIds($educandoIds);
        $currentEducandoIds = $this->normalizeRelationIds(
            $member->educandos()->pluck('users.id')->all()
        );

        $member->educandos()->sync($educandoIds);
        $member->educandos = $educandoIds;
        $member->saveQuietly();

        $added = array_diff($educandoIds, $currentEducandoIds);
        $removed = array_diff($currentEducandoIds, $educandoIds);

        if (!empty($added) || !empty($removed)) {
            $affectedIds = array_values(array_unique(array_merge($added, $removed)));
            $educandos = User::whereIn('id', $affectedIds)->get()->keyBy('id');

            foreach ($added as $educandoId) {
                if ($educandos->has($educandoId)) {
                    $educando = $educandos[$educandoId];
                    $educando->encarregados()->syncWithoutDetaching([$member->id]);
                    $guardianIds = $this->normalizeRelationIds($educando->encarregado_educacao);
                    if (!in_array((string) $member->id, $guardianIds, true)) {
                        $guardianIds[] = (string) $member->id;
                    }
                    $educando->encarregado_educacao = array_values(array_unique($guardianIds));
                    $educando->saveQuietly();
                }
            }

            foreach ($removed as $educandoId) {
                if ($educandos->has($educandoId)) {
                    $educando = $educandos[$educandoId];
                    $educando->encarregados()->detach($member->id);
                    $educando->encarregado_educacao = array_values(array_filter(
                        $this->normalizeRelationIds($educando->encarregado_educacao),
                        fn (string $guardianId) => $guardianId !== (string) $member->id
                    ));
                    $educando->saveQuietly();
                }
            }
        }

        if ($educandoIds !== []) {
            $currentEducandos = User::whereIn('id', $educandoIds)->get();
            foreach ($currentEducandos as $educando) {
                $guardianIds = $this->normalizeRelationIds($educando->encarregado_educacao);
                if (!in_array((string) $member->id, $guardianIds, true)) {
                    $guardianIds[] = (string) $member->id;
                    $educando->encarregado_educacao = array_values(array_unique($guardianIds));
                    $educando->saveQuietly();
                }
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

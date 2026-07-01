<?php

namespace App\Http\Controllers;

use App\Models\AgeGroup;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Models\UserType;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Family\FamilyService;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Loja\StoreProfileResolver;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberDocumentDataResolver;
use App\Services\Members\MemberIdentityDisplayResolver;
use App\Services\Members\MemberDataWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PortalProfileController extends Controller
{
    /**
     * @var array<string>
     */
    private const ADMIN_TYPE_CODES = [
        'admin', 'administrador', 'direcao', 'gestor', 'staff', 'tecnico',
    ];

    public function show(
        Request $request,
        StoreProfileResolver $profileResolver,
        FamilyService $familyService,
        UserTypeAccessControlService $accessControlService,
        MemberDataReadService $memberDataReadService,
        MemberDocumentDataResolver $memberDocumentDataResolver,
    ): Response {
        /** @var User $viewer */
        $viewer = $request->user();
        $requestedMemberId = $request->string('member')->trim()->value() ?: $viewer->id;

        $allowedProfiles = $profileResolver->allowedProfiles($viewer)->keyBy('id');

        abort_unless($allowedProfiles->has($requestedMemberId), 403);

        $targetMember = User::query()
            ->with([
                'encarregados:id,nome_completo,name,numero_socio,foto_perfil,estado,tipo_membro,menor',
                'educandos:id,nome_completo,name,numero_socio,foto_perfil,estado,tipo_membro,menor',
                'userTypes:id,codigo,nome,ativo',
                'athleteSportsData:id,user_id,escalao_id',
                'athleteSportsData.escalao:id,nome',
                'dadosFinanceiros:id,user_id,mensalidade_id',
                'dadosFinanceiros.mensalidade:id,designacao',
                'dadosPessoais',
                'dadosConfiguracao',
            ])
            ->findOrFail($requestedMemberId);

        // M2.3 — aplicar camada de leitura canónica com fallback (sem escrita)
        // false booleano é valor válido — não filtrar com array_filter simples
        $mergedReadData = $memberDataReadService->mergedMemberPayload($targetMember, []);
        $targetMember->forceFill(array_filter($mergedReadData, static fn ($v) => $v !== null));

        $accessControl = $accessControlService->getCurrentUserAccess($viewer);

        return Inertia::render('Portal/Profile', [
            'profile' => $this->buildProfilePayload(
                $targetMember,
                $viewer,
                $this->canEditPortalProfile($viewer, $targetMember, $profileResolver, $familyService, $accessControlService),
                $memberDataReadService,
                $memberDocumentDataResolver,
            ),
            'viewer' => [
                'id' => $viewer->id,
                'name' => $this->displayName($viewer),
                'type' => $this->memberTypeLabel($viewer),
            ],
            'viewer_dependents' => $allowedProfiles
                ->reject(fn (User $member) => $member->id === $viewer->id)
                ->sortBy(fn (User $member) => $this->displayName($member))
                ->map(fn (User $dependent) => $this->mapRelatedMember($dependent, $viewer))
                ->values()
                ->all(),
            'allowed_profiles' => $allowedProfiles
                ->values()
                ->map(function (User $member) use ($viewer) {
                    return [
                        'id' => $member->id,
                        'name' => $this->displayName($member),
                        'portal_href' => $member->id === $viewer->id
                            ? route('portal.profile')
                            : route('portal.profile', ['member' => $member->id]),
                    ];
                })
                ->all(),
            'modulos_visiveis' => $accessControl['visibleMenuModules'] ?? [],
            'is_also_admin' => $familyService->userHasAdministratorProfile($viewer),
            'has_family' => $familyService->userHasFamily($viewer),
        ]);
    }

    public function update(
        Request $request,
        StoreProfileResolver $profileResolver,
        FamilyService $familyService,
        UserTypeAccessControlService $accessControlService,
        MemberDataWriteService $memberDataWriteService,
    ): RedirectResponse {
        /** @var User $viewer */
        $viewer = $request->user();
        $requestedMemberId = $request->string('member')->trim()->value() ?: $viewer->id;

        $allowedProfiles = $profileResolver->allowedProfiles($viewer)->keyBy('id');

        abort_unless($allowedProfiles->has($requestedMemberId), 403);

        $targetMember = User::query()->findOrFail($requestedMemberId);

        abort_unless(
            $this->canEditPortalProfile($viewer, $targetMember, $profileResolver, $familyService, $accessControlService),
            403,
        );

        $data = $request->validate([
            'nome_completo' => ['required', 'string', 'max:255'],
            'data_nascimento' => ['nullable', 'date'],
            'nif' => ['nullable', 'string', 'max:50'],
            'cc' => ['nullable', 'string', 'max:50'],
            'morada' => ['nullable', 'string', 'max:255'],
            'codigo_postal' => ['nullable', 'string', 'max:20'],
            'localidade' => ['nullable', 'string', 'max:255'],
            'nacionalidade' => ['nullable', 'string', 'max:255'],
            'sexo' => ['nullable', 'in:masculino,feminino'],
            'contacto' => ['nullable', 'string', 'max:30'],
            'email_secundario' => ['nullable', 'email', 'max:255'],
            'num_federacao' => ['nullable', 'string', 'max:100'],
            'numero_pmb' => ['nullable', 'string', 'max:100'],
            'data_inscricao' => ['nullable', 'date'],
            'photo' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('photo')) {
            $this->deleteFile($targetMember->getRawOriginal('foto_perfil'));
            $data['foto_perfil'] = $request->file('photo')->store('members/photos', 'public');
        }

        unset($data['photo']);

        if (array_key_exists('data_nascimento', $data) && $data['data_nascimento']) {
            $data['menor'] = now()->parse((string) $data['data_nascimento'])->age < 18;
        }

        if (array_key_exists('nome_completo', $data) && $data['nome_completo']) {
            $data['name'] = $data['nome_completo'];
        }

        $targetMember->refresh();
        $memberDataWriteService->persistFromMemberRequest($targetMember, $data, (string) $targetMember->id);

        $legacyUserPayload = [];

        if (array_key_exists('name', $data)) {
            $legacyUserPayload['name'] = $data['name'];
        }

        if (array_key_exists('foto_perfil', $data)) {
            $legacyUserPayload['foto_perfil'] = $data['foto_perfil'];
        }

        if (array_key_exists('menor', $data)) {
            $legacyUserPayload['menor'] = $data['menor'];
        }

        if ($legacyUserPayload !== []) {
            $targetMember->fill($legacyUserPayload);
            $targetMember->save();
        }

        return redirect()->route('portal.profile', $targetMember->id === $viewer->id ? [] : ['member' => $targetMember->id]);
    }

    private function buildProfilePayload(
        User $member,
        User $viewer,
        bool $canEdit,
        MemberDataReadService $memberDataReadService,
        MemberDocumentDataResolver $memberDocumentDataResolver,
    ): array
    {
        $isAthlete = $this->hasMemberType($member, 'atleta');
        $isSocio = $this->hasMemberType($member, 'socio');
        $isGuardian = $this->hasMemberType($member, 'encarregado_educacao') || $this->hasMemberType($member, 'encarregado');
        $memberTypeLabels = $this->memberTypeLabels($member);
        $memberTypeLabel = implode(' · ', $memberTypeLabels);
        $personal = $memberDataReadService->personalPayload($member);
        $profileDocuments = $memberDocumentDataResolver->profileDocuments($member);
        $attestationStatus = $this->dateStatus($profileDocuments['atestado']['valid_until'] ?? null, true);
        $ageGroup = $this->resolveAgeGroupLabel($member);
        $accountSummary = app(CurrentAccountService::class)->summarize([
            'user_id' => $member->id,
        ]);
        $nextInvoice = collect($accountSummary['breakdown']['invoices'] ?? [])->first();
        $planName = $member->dadosFinanceiros?->mensalidade?->designacao
            ?: $this->resolveMonthlyFeeName($member->tipo_mensalidade)
            ?: $this->displayValue(optional($nextInvoice)->tipo);

        return [
            'id' => $member->id,
            'name' => $this->displayName($member),
            'member_number' => $member->numero_socio,
            'type' => $memberTypeLabel,
            'state' => $this->humanizeState($member->estado),
            'photo_url' => $member->foto_perfil,
            'is_minor' => (bool) $member->menor,
            'viewing_self' => $member->id === $viewer->id,
            'can_edit' => $canEdit,
            'portal_href' => $member->id === $viewer->id
                ? route('portal.profile')
                : route('portal.profile', ['member' => $member->id]),
            'editable' => [
                'nome_completo' => $personal['nome_completo'] ?? $this->displayName($member),
                'data_nascimento' => $personal['data_nascimento'] ?? null,
                'nif' => $personal['nif'] ?? null,
                'cc' => $personal['documento_identificacao'] ?? null,
                'morada' => $personal['morada'] ?? null,
                'codigo_postal' => $personal['codigo_postal'] ?? null,
                'localidade' => $personal['localidade'] ?? null,
                'nacionalidade' => $personal['nacionalidade'] ?? null,
                'sexo' => in_array($personal['sexo'] ?? null, ['masculino', 'feminino'], true) ? $personal['sexo'] : null,
                'contacto' => $personal['contacto'] ?? null,
                'email_secundario' => $personal['email_secundario'] ?? null,
                'num_federacao' => $profileDocuments['federacao']['numero'] ?? null,
                'numero_pmb' => $member->numero_pmb,
                'data_inscricao' => $member->data_inscricao?->format('Y-m-d'),
            ],
            'summary_badges' => array_values(array_filter([
                ['label' => $this->humanizeState($member->estado), 'tone' => $this->normalizeStateTone($member->estado)],
                ['label' => $memberTypeLabel, 'tone' => 'info'],
                $member->menor ? ['label' => 'Menor', 'tone' => 'warning'] : null,
                $attestationStatus['code'] === 'expiring' ? ['label' => 'Atestado a caducar', 'tone' => 'warning'] : null,
            ])),
            'personal' => [
                ['label' => 'Nome completo', 'value' => $this->displayValue($personal['nome_completo'] ?? $this->displayName($member))],
                ['label' => 'Data de nascimento', 'value' => $this->displayValue($this->formatDate($personal['data_nascimento'] ?? null))],
                ['label' => 'NIF', 'value' => $this->displayValue($personal['nif'] ?? null)],
                ['label' => 'CC', 'value' => $this->displayValue($personal['documento_identificacao'] ?? null)],
                ['label' => 'Morada', 'value' => $this->displayValue($personal['morada'] ?? null)],
                ['label' => 'Código postal', 'value' => $this->displayValue($personal['codigo_postal'] ?? null)],
                ['label' => 'Localidade', 'value' => $this->displayValue($personal['localidade'] ?? null)],
                ['label' => 'Nacionalidade', 'value' => $this->displayValue($personal['nacionalidade'] ?? null)],
                ['label' => 'Sexo', 'value' => $this->displayValue($this->humanizeSex($personal['sexo'] ?? null))],
                ['label' => 'Contacto', 'value' => $this->displayValue($personal['contacto'] ?? null)],
                ['label' => 'Email secundário', 'value' => $this->displayValue($personal['email_secundario'] ?? null)],
            ],
            'status' => [
                ['label' => 'Estado', 'value' => $this->humanizeState($member->estado)],
                ['label' => 'Número de sócio', 'value' => $this->displayValue($member->numero_socio)],
                ['label' => 'Tipos de membro', 'value' => $this->displayValue($memberTypeLabel)],
            ],
            'guardians' => $member->encarregados
                ->map(fn (User $guardian) => $this->mapRelatedMember($guardian, $viewer))
                ->values()
                ->all(),
            'documents' => array_values(array_filter([
                $this->buildBooleanDocument(
                    'RGPD',
                    (bool) ($profileDocuments['rgpd']['is_validated'] ?? false),
                    $profileDocuments['rgpd']['validated_at'] ?? null,
                    'Consentimento RGPD registado'
                ),
                $this->buildBooleanDocument(
                    'Consentimento imagem/transporte',
                    (bool) ($profileDocuments['consentimento']['is_validated'] ?? false),
                    $profileDocuments['consentimento']['validated_at'] ?? null,
                    'Imagem e transporte autorizados'
                ),
                $this->buildExpiringDocument(
                    'Atestado médico',
                    $profileDocuments['atestado']['valid_until'] ?? null,
                    'Validade do atestado médico'
                ),
                $isAthlete ? $this->buildBooleanDocument(
                    'Cartão federação',
                    (bool) ($profileDocuments['federacao']['is_validated'] ?? false),
                    $profileDocuments['federacao']['validated_at'] ?? null,
                    'Identificação federativa disponível'
                ) : null,
            ])),
            'sports' => [
                ['label' => 'N.º federação', 'value' => $this->displayValue($profileDocuments['federacao']['numero'] ?? null)],
                ['label' => 'Número PMB', 'value' => $this->displayValue($member->numero_pmb)],
                ['label' => 'Data de inscrição', 'value' => $this->displayValue($this->formatDate($member->data_inscricao))],
                ['label' => 'Escalão', 'value' => $this->displayValue($ageGroup)],
                ['label' => 'Estado desportivo', 'value' => $member->ativo_desportivo ? 'Ativo' : 'Inativo'],
            ],
            'financial' => [
                'account_balance' => $this->formatCurrency($accountSummary['net_debt'] ?? 0),
                'outstanding_value' => $this->formatCurrency($accountSummary['net_debt'] ?? 0),
                'gross_debt' => $this->formatCurrency($accountSummary['gross_debt'] ?? 0),
                'available_credit' => $this->formatCurrency($accountSummary['available_credit'] ?? 0),
                'net_debt' => $this->formatCurrency($accountSummary['net_debt'] ?? 0),
                'next_payment' => $nextInvoice ? [
                    'label' => $this->displayValue(($nextInvoice['mes'] ?? null) ?: ($nextInvoice['tipo'] ?? null) ?: 'Próximo pagamento'),
                    'due_date' => $this->formatDate($nextInvoice['data_vencimento'] ?? null),
                    'amount' => $this->formatCurrency($nextInvoice['valor_em_aberto'] ?? 0),
                    'state' => $this->humanizeInvoiceState($nextInvoice['estado_pagamento'] ?? null),
                ] : null,
                'plan' => $this->displayValue($planName),
            ],
            'flags' => [
                'is_athlete' => $isAthlete,
                'is_socio' => $isSocio,
                'is_guardian' => $isGuardian,
                'show_guardians' => (bool) $member->menor || $member->encarregados->isNotEmpty(),
            ],
        ];
    }

    private function mapRelatedMember(User $member, User $viewer): array
    {
        return [
            'id' => $member->id,
            'name' => $this->displayName($member),
            'member_number' => $member->numero_socio,
            'type' => $this->memberTypeLabel($member),
            'state' => $this->humanizeState($member->estado),
            'is_minor' => (bool) $member->menor,
            'photo_url' => $member->foto_perfil,
            'portal_href' => $member->id === $viewer->id
                ? route('portal.profile')
                : route('portal.profile', ['member' => $member->id]),
        ];
    }

    private function buildBooleanDocument(string $label, bool $isValid, mixed $referenceDate, string $helper): array
    {
        $status = $isValid ? 'valid' : 'pending';

        return [
            'label' => $label,
            'status' => $status,
            'state_label' => $this->documentStateLabel($status),
            'helper' => $helper,
            'meta' => $referenceDate ? $this->formatDate($referenceDate) : 'Sem registo',
        ];
    }

    private function buildExpiringDocument(string $label, mixed $referenceDate, string $helper): array
    {
        $status = $this->dateStatus($referenceDate, true);

        return [
            'label' => $label,
            'status' => $status['code'],
            'state_label' => $status['label'],
            'helper' => $helper,
            'meta' => $referenceDate ? $this->formatDate($referenceDate) : 'Sem registo',
        ];
    }

    private function dateStatus(mixed $date, bool $allowPending = false): array
    {
        if (! $date) {
            return [
                'code' => $allowPending ? 'pending' : 'valid',
                'label' => $allowPending ? 'Pendente' : 'Válido',
            ];
        }

        $value = $date instanceof \DateTimeInterface ? $date : now()->parse((string) $date);
        $today = now()->startOfDay();
        $diffInDays = $today->diffInDays($value, false);

        if ($diffInDays < 0) {
            return ['code' => 'expired', 'label' => 'Expirado'];
        }

        if ($diffInDays <= 30) {
            return ['code' => 'expiring', 'label' => 'A caducar'];
        }

        return ['code' => 'valid', 'label' => 'Válido'];
    }

    private function displayName(User $member): string
    {
        return app(MemberIdentityDisplayResolver::class)->displayNameOrFallback($member, 'Utilizador');
    }

    private function displayValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = $this->primaryValue($value);
        }

        $value = trim((string) ($value ?? ''));

        return $value !== '' ? $value : 'Sem informação';
    }

    private function primaryValue(mixed $value): ?string
    {
        if (is_array($value)) {
            $first = collect($value)->filter()->first();

            return $first ? (string) $first : null;
        }

        return $value ? (string) $value : null;
    }

    private function memberTypeLabel(User $member): string
    {
        return implode(' · ', $this->memberTypeLabels($member));
    }

    /**
     * @return array<int, string>
     */
    private function memberTypeLabels(User $member): array
    {
        $relationLabels = $member->relationLoaded('userTypes')
            ? $member->userTypes
                ->filter(fn (UserType $userType) => $userType->ativo)
                ->map(fn (UserType $userType) => trim((string) ($userType->nome ?: $userType->codigo)))
                ->filter()
                ->values()
                ->all()
            : [];

        $fallbackLabels = collect($member->tipo_membro ?? [])
            ->filter()
            ->map(fn ($type) => $this->humanizeMemberType($type))
            ->values()
            ->all();

        $labels = collect([...$relationLabels, ...$fallbackLabels])
            ->map(fn (string $label) => trim($label))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $labels !== [] ? $labels : ['Membro'];
    }

    private function humanizeMemberType(mixed $value): string
    {
        $normalized = Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();

        return match ($normalized) {
            'atleta' => 'Atleta',
            'encarregado_educacao', 'encarregado' => 'Encarregado',
            'socio' => 'Sócio',
            default => $value ? Str::headline((string) $value) : 'Membro',
        };
    }

    private function resolveAgeGroupLabel(User $member): ?string
    {
        $sportsAgeGroup = $member->athleteSportsData?->escalao?->nome;
        if (filled($sportsAgeGroup)) {
            return trim((string) $sportsAgeGroup);
        }

        $rawAgeGroupIds = collect(is_array($member->escalao) ? $member->escalao : [$member->escalao])
            ->filter()
            ->map(fn ($value) => (string) $value)
            ->values();

        if ($rawAgeGroupIds->isEmpty()) {
            return null;
        }

        $resolvedNames = AgeGroup::query()
            ->whereIn('id', $rawAgeGroupIds->all())
            ->pluck('nome')
            ->filter()
            ->values();

        if ($resolvedNames->isNotEmpty()) {
            return $resolvedNames->implode(' · ');
        }

        return $this->primaryValue($member->escalao);
    }

    private function resolveMonthlyFeeName(mixed $value): ?string
    {
        $rawValue = $this->primaryValue($value);
        if (! filled($rawValue)) {
            return null;
        }

        $monthlyFeeName = MonthlyFee::query()
            ->where('id', $rawValue)
            ->value('designacao');

        return $monthlyFeeName ?: $rawValue;
    }

    private function hasMemberType(User $member, string $expected): bool
    {
        return collect($member->tipo_membro ?? [])
            ->map(fn ($type) => Str::of((string) $type)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value())
            ->contains($expected);
    }

    private function humanizeState(?string $state): string
    {
        $normalized = Str::of((string) $state)->trim()->lower()->ascii()->value();

        return match ($normalized) {
            'ativo', 'active' => 'Ativo',
            'inativo', 'inactive' => 'Inativo',
            'suspenso', 'suspended' => 'Suspenso',
            default => $state ? Str::headline($state) : 'Ativo',
        };
    }

    private function normalizeStateTone(?string $state): string
    {
        return match (Str::of((string) $state)->trim()->lower()->ascii()->value()) {
            'inativo', 'inactive', 'suspenso', 'suspended' => 'neutral',
            default => 'success',
        };
    }

    private function humanizeSex(?string $sex): ?string
    {
        return match (Str::of((string) $sex)->trim()->lower()->ascii()->value()) {
            'm', 'masculino', 'male' => 'Masculino',
            'f', 'feminino', 'female' => 'Feminino',
            default => $sex ? Str::headline($sex) : null,
        };
    }

    private function formatDate(mixed $date): ?string
    {
        if (! $date) {
            return null;
        }

        $value = $date instanceof \DateTimeInterface ? $date : now()->parse((string) $date);

        return $value->format('d/m/Y');
    }

    private function formatCurrency(mixed $value): string
    {
        $amount = is_numeric($value) ? (float) $value : 0.0;

        return number_format($amount, 2, ',', ' ') . ' €';
    }

    private function humanizeInvoiceState(?string $state): string
    {
        return match (Str::of((string) $state)->trim()->lower()->ascii()->value()) {
            'pago' => 'Regularizado',
            'pendente' => 'Pendente',
            'em_atraso', 'atraso' => 'Em atraso',
            default => $state ? Str::headline($state) : 'Sem atualização',
        };
    }

    private function documentStateLabel(string $status): string
    {
        return match ($status) {
            'valid' => 'Válido',
            'expiring' => 'A caducar',
            'expired' => 'Expirado',
            default => 'Pendente',
        };
    }

    private function canAccessAdminDashboard(User $user, array $accessControl): bool
    {
        $currentUserType = $accessControl['currentUserType'] ?? null;

        return $this->matchesAdminType([
            'codigo' => $currentUserType['codigo'] ?? null,
            'nome' => $currentUserType['nome'] ?? null,
        ]) || $this->userHasAdminType($user);
    }

    /**
     * @param array{codigo?: mixed, nome?: mixed} $userType
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
            ->contains(fn (UserType $userType) => $this->matchesAdminType([
                'codigo' => $userType->codigo,
                'nome' => $userType->nome,
            ]));
    }

    private function canEditPortalProfile(
        User $viewer,
        User $targetMember,
        StoreProfileResolver $profileResolver,
        FamilyService $familyService,
        UserTypeAccessControlService $accessControlService,
    ): bool {
        $allowedIds = $profileResolver->allowedProfiles($viewer)->pluck('id')->all();

        if (! in_array($targetMember->id, $allowedIds, true)) {
            return false;
        }

        if ($targetMember->id === $viewer->id) {
            return $this->isPortalSelfEditableUser($viewer, $accessControlService)
                || $accessControlService->canAccessPermission($viewer, 'membros.ficha', 'edit');
        }

        if ($familyService->userCanEditFamilyMember($viewer, $targetMember)) {
            return true;
        }

        return $accessControlService->canAccessPermission($viewer, 'membros.ficha', 'edit');
    }

    private function isPortalSelfEditableUser(User $viewer, UserTypeAccessControlService $accessControlService): bool
    {
        if ($this->hasMemberType($viewer, 'atleta')
            || $this->hasMemberType($viewer, 'socio')
            || $this->hasMemberType($viewer, 'encarregado_educacao')
            || $this->hasMemberType($viewer, 'encarregado')) {
            return true;
        }

        $perfil = Str::of((string) $viewer->perfil)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();

        if (in_array($perfil, ['atleta', 'socio', 'encarregado', 'encarregado_educacao', 'user'], true)) {
            return true;
        }

        $accessControl = $accessControlService->getCurrentUserAccess($viewer);
        $currentUserType = $accessControl['currentUserType'] ?? null;

        $codigo = Str::of((string) ($currentUserType['codigo'] ?? ''))->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
        $nome = Str::of((string) ($currentUserType['nome'] ?? ''))->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();

        return in_array($codigo, ['atleta', 'socio', 'encarregado', 'encarregado_educacao'], true)
            || in_array($nome, ['atleta', 'socio', 'encarregado', 'encarregado_educacao'], true);
    }

    private function deleteFile(?string $path): void
    {
        if (! $path) {
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
}
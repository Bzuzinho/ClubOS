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
            ])
            ->findOrFail($requestedMemberId);

        $accessControl = $accessControlService->getCurrentUserAccess($viewer);

        return Inertia::render('Portal/Profile', [
            'profile' => $this->buildProfilePayload(
                $targetMember,
                $viewer,
                $this->canEditPortalProfile($viewer, $targetMember, $profileResolver, $familyService, $accessControlService),
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

        $targetMember->fill($data);
        $targetMember->save();

        return redirect()->route('portal.profile', $targetMember->id === $viewer->id ? [] : ['member' => $targetMember->id]);
    }

    private function buildProfilePayload(User $member, User $viewer, bool $canEdit): array
    {
        $isAthlete = $this->hasMemberType($member, 'atleta');
        $isSocio = $this->hasMemberType($member, 'socio');
        $isGuardian = $this->hasMemberType($member, 'encarregado_educacao') || $this->hasMemberType($member, 'encarregado');
        $memberTypeLabels = $this->memberTypeLabels($member);
        $memberTypeLabel = implode(' · ', $memberTypeLabels);
        $attestationStatus = $this->dateStatus($member->data_atestado_medico, true);
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
                'nome_completo' => $this->displayName($member),
                'data_nascimento' => $member->data_nascimento?->format('Y-m-d'),
                'nif' => $member->nif,
                'cc' => $member->cc,
                'morada' => $member->morada,
                'codigo_postal' => $member->codigo_postal,
                'localidade' => $member->localidade,
                'nacionalidade' => $member->nacionalidade,
                'sexo' => in_array($member->sexo, ['masculino', 'feminino'], true) ? $member->sexo : null,
                'contacto' => $member->contacto ?: $member->telemovel ?: $member->contacto_telefonico,
                'email_secundario' => $member->email_secundario,
                'num_federacao' => $member->num_federacao,
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
                ['label' => 'Nome completo', 'value' => $this->displayValue($this->displayName($member))],
                ['label' => 'Data de nascimento', 'value' => $this->displayValue($this->formatDate($member->data_nascimento))],
                ['label' => 'NIF', 'value' => $this->displayValue($member->nif)],
                ['label' => 'CC', 'value' => $this->displayValue($member->cc)],
                ['label' => 'Morada', 'value' => $this->displayValue($member->morada)],
                ['label' => 'Código postal', 'value' => $this->displayValue($member->codigo_postal)],
                ['label' => 'Localidade', 'value' => $this->displayValue($member->localidade)],
                ['label' => 'Nacionalidade', 'value' => $this->displayValue($member->nacionalidade)],
                ['label' => 'Sexo', 'value' => $this->displayValue($this->humanizeSex($member->sexo))],
                ['label' => 'Contacto', 'value' => $this->displayValue($member->contacto ?: $member->telemovel ?: $member->contacto_telefonico)],
                ['label' => 'Email secundário', 'value' => $this->displayValue($member->email_secundario)],
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
                $this->buildBooleanDocument('RGPD', (bool) $member->rgpd, $member->data_rgpd, 'Consentimento RGPD registado'),
                $this->buildBooleanDocument(
                    'Consentimento imagem/transporte',
                    (bool) ($member->consentimento || $member->declaracao_de_transporte),
                    $member->data_consentimento,
                    'Imagem e transporte autorizados'
                ),
                $this->buildExpiringDocument('Atestado médico', $member->data_atestado_medico, 'Validade do atestado médico'),
                $isAthlete ? $this->buildBooleanDocument(
                    'Cartão federação',
                    filled($member->cartao_federacao) || filled($member->num_federacao),
                    $member->data_afiliacao,
                    'Identificação federativa disponível'
                ) : null,
            ])),
            'sports' => [
                ['label' => 'N.º federação', 'value' => $this->displayValue($member->num_federacao)],
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
        return trim((string) ($member->nome_completo ?: $member->name ?: 'Utilizador'));
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
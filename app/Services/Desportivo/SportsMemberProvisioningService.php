<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Models\AgeGroup;
use App\Models\AthleteSportsData;
use App\Models\User;
use App\Models\UserType;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberTypeResolver;
use Carbon\Carbon;

final class SportsMemberProvisioningService
{
    public function __construct(
        private readonly MemberTypeResolver $memberTypeResolver,
        private readonly MemberDataReadService $memberDataReadService,
    ) {
    }

    /**
     * Sincroniza a ficha desportiva canónica a partir de uma escrita de Membros.
     *
     * athlete_sports_data.ativo representa apenas a atividade desportiva.
     * O conceito de "atleta ativo" resulta da combinação estado do membro + tipo
     * Atleta + atividade desportiva e é resolvido por SportsMemberStatusResolver.
     *
     * @param array<string, mixed> $payload
     */
    public function sync(User $user, array $payload): ?AthleteSportsData
    {
        $user->refresh();
        $user->loadMissing([
            'userTypes:id,codigo,nome',
            'dadosPessoais:id,user_id,data_nascimento',
            'dadosConfiguracao',
            'athleteSportsData',
        ]);

        $existing = $user->athleteSportsData;
        $isAthlete = $this->resolveAthleteDecision($user, $payload);

        if (! $isAthlete) {
            // Um membro sem ficha desportiva não deve perder informação legacy
            // apenas porque a tipologia atual não o resolve como atleta. Quando
            // já existe ficha, preservamos o histórico e desativamo-la.
            if ($existing === null) {
                return null;
            }

            if ((bool) $existing->ativo) {
                $existing->forceFill(['ativo' => false])->save();
            }

            $this->syncLegacyCompatibilityFields(
                $user,
                false,
                $existing->escalao_id,
            );

            return $existing->fresh(['escalao', 'escalaoCalculado']);
        }

        $sportsFallback = $this->memberDataReadService->sportsPayload($user);

        $sportsActivityActive = array_key_exists('ativo_desportivo', $payload)
            ? (bool) $payload['ativo_desportivo']
            : (bool) ($existing?->ativo ?? $user->ativo_desportivo ?? false);

        $birthDate = $this->resolveBirthDate($user, $payload);
        $calculatedAgeGroupId = $this->resolveAgeGroupId($birthDate);
        $explicitAgeGroupId = $this->explicitAgeGroupId($payload);
        $manualOverride = $this->resolveManualOverride($payload, $existing, $explicitAgeGroupId);

        if ($manualOverride && $explicitAgeGroupId === null && $existing?->escalao_id) {
            $explicitAgeGroupId = (string) $existing->escalao_id;
        }

        if ($manualOverride && $explicitAgeGroupId === null) {
            $manualOverride = false;
        }

        $officialAgeGroupId = $manualOverride
            ? $explicitAgeGroupId
            : ($calculatedAgeGroupId ?? $existing?->escalao_id);

        $profile = $existing ?? new AthleteSportsData();
        $profile->user_id = $user->id;
        $profile->num_federacao = $this->sportsValue(
            $payload,
            'num_federacao',
            $existing?->num_federacao,
            $sportsFallback['num_federacao'] ?? null,
        );
        $profile->cartao_federacao = $this->sportsValue(
            $payload,
            'cartao_federacao',
            $existing?->cartao_federacao,
            $sportsFallback['cartao_federacao'] ?? null,
        );
        $profile->numero_pmb = $this->sportsValue(
            $payload,
            'numero_pmb',
            $existing?->numero_pmb,
            $sportsFallback['numero_pmb'] ?? null,
        );
        $profile->data_inscricao = $this->sportsValue(
            $payload,
            'data_inscricao',
            $existing?->data_inscricao,
            $sportsFallback['data_inscricao'] ?? null,
        );
        $profile->escalao_id = $officialAgeGroupId;
        $profile->escalao_calculado_id = $calculatedAgeGroupId;
        $profile->escalao_manual_override = $manualOverride;
        $profile->data_atestado_medico = $this->sportsValue(
            $payload,
            'data_atestado_medico',
            $existing?->data_atestado_medico,
            $sportsFallback['data_atestado_medico'] ?? null,
        );
        $profile->arquivo_atestado_medico = $this->sportsValue(
            $payload,
            'arquivo_atestado_medico',
            $existing?->arquivo_atestado_medico,
            $sportsFallback['arquivo_atestado_medico'] ?? null,
        );
        $profile->informacoes_medicas = $this->sportsValue(
            $payload,
            'informacoes_medicas',
            $existing?->informacoes_medicas,
            $sportsFallback['informacoes_medicas'] ?? null,
        );
        $profile->ativo = $sportsActivityActive;
        $profile->save();

        $this->syncLegacyCompatibilityFields(
            $user,
            $sportsActivityActive,
            $officialAgeGroupId,
        );

        return $profile->fresh(['escalao', 'escalaoCalculado']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveAthleteDecision(User $user, array $payload): bool
    {
        if (array_key_exists('user_types', $payload) && is_array($payload['user_types'])) {
            $types = UserType::query()
                ->whereIn('id', $payload['user_types'])
                ->get(['codigo', 'nome'])
                ->map(fn (UserType $type): string => $this->memberTypeResolver->normalizeType((string) ($type->codigo ?: $type->nome)))
                ->filter()
                ->values();

            return $types->contains('atleta');
        }

        if (array_key_exists('tipo_membro', $payload)) {
            return collect(is_array($payload['tipo_membro']) ? $payload['tipo_membro'] : (array) $payload['tipo_membro'])
                ->map(fn (mixed $type): string => $this->memberTypeResolver->normalizeType((string) $type))
                ->contains('atleta');
        }

        return $this->memberTypeResolver->isAthlete($user);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveManualOverride(
        array $payload,
        ?AthleteSportsData $existing,
        ?string $explicitAgeGroupId,
    ): bool {
        if (array_key_exists('escalao_manual_override', $payload)) {
            return (bool) $payload['escalao_manual_override'];
        }

        if ($existing !== null) {
            return (bool) $existing->escalao_manual_override;
        }

        // Compatibilidade com clientes antigos que enviam um escalão explícito
        // mas ainda não conhecem a flag de override.
        return $explicitAgeGroupId !== null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function explicitAgeGroupId(array $payload): ?string
    {
        $direct = trim((string) ($payload['escalao_id'] ?? ''));
        if ($direct !== '') {
            return $direct;
        }

        return collect($payload['escalao'] ?? [])
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->first(static fn (string $value): bool => $value !== '') ?: null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveBirthDate(User $user, array $payload): ?Carbon
    {
        $personal = $this->memberDataReadService->personalPayload($user);
        $candidate = $payload['data_nascimento'] ?? ($personal['data_nascimento'] ?? null);

        if (blank($candidate)) {
            return null;
        }

        try {
            return Carbon::parse($candidate);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveAgeGroupId(?Carbon $birthDate): ?string
    {
        if ($birthDate === null) {
            return null;
        }

        $age = $birthDate->age;
        $birthYear = $birthDate->year;

        $byAge = AgeGroup::query()
            ->where('ativo', true)
            ->where(function ($query) use ($age): void {
                $query->whereNull('idade_minima')->orWhere('idade_minima', '<=', $age);
            })
            ->where(function ($query) use ($age): void {
                $query->whereNull('idade_maxima')->orWhere('idade_maxima', '>=', $age);
            })
            ->where(function ($query): void {
                $query->whereNotNull('idade_minima')->orWhereNotNull('idade_maxima');
            })
            ->orderByDesc('idade_minima')
            ->orderBy('idade_maxima')
            ->first();

        if ($byAge !== null) {
            return (string) $byAge->id;
        }

        $byYear = AgeGroup::query()
            ->where('ativo', true)
            ->where(function ($query) use ($birthYear): void {
                $query->whereNull('ano_minimo')->orWhere('ano_minimo', '<=', $birthYear);
            })
            ->where(function ($query) use ($birthYear): void {
                $query->whereNull('ano_maximo')->orWhere('ano_maximo', '>=', $birthYear);
            })
            ->where(function ($query): void {
                $query->whereNotNull('ano_minimo')->orWhereNotNull('ano_maximo');
            })
            ->orderByDesc('ano_minimo')
            ->orderBy('ano_maximo')
            ->first();

        return $byYear?->id ? (string) $byYear->id : null;
    }

    private function sportsValue(array $payload, string $key, mixed $canonical, mixed $fallback): mixed
    {
        if (array_key_exists($key, $payload)) {
            return $payload[$key];
        }

        return $canonical ?? $fallback;
    }

    private function syncLegacyCompatibilityFields(User $user, bool $sportsActivityActive, ?string $ageGroupId): void
    {
        $ageGroups = $ageGroupId ? [(string) $ageGroupId] : [];

        if (
            (bool) $user->ativo_desportivo === $sportsActivityActive
            && collect($user->escalao ?? [])->map('strval')->values()->all() === $ageGroups
        ) {
            return;
        }

        $user->forceFill([
            'ativo_desportivo' => $sportsActivityActive,
            'escalao' => $ageGroups,
        ])->saveQuietly();
    }
}

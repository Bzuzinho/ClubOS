<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Models\AgeGroup;
use App\Models\AthleteSportsData;
use App\Models\User;
use App\Models\UserType;
use App\Services\Members\MemberTypeResolver;
use Carbon\Carbon;

final class SportsMemberProvisioningService
{
    public function __construct(
        private readonly MemberTypeResolver $memberTypeResolver,
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
            'athleteSportsData',
        ]);

        $existing = $user->athleteSportsData;
        $isAthlete = $this->resolveAthleteDecision($user, $payload);

        if (! $isAthlete) {
            if ($existing !== null && (bool) $existing->ativo) {
                $existing->forceFill(['ativo' => false])->save();
            }

            $this->syncLegacyCompatibilityFields(
                $user,
                false,
                $existing?->escalao_id,
            );

            return $existing?->fresh(['escalao', 'escalaoCalculado']);
        }

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

        $profile = $existing ?? new AthleteSportsData(['user_id' => $user->id]);
        $profile->fill([
            'num_federacao' => $this->sportsValue($payload, 'num_federacao', $existing?->num_federacao, $user->getAttribute('num_federacao')),
            'cartao_federacao' => $this->sportsValue($payload, 'cartao_federacao', $existing?->cartao_federacao, $user->getAttribute('cartao_federacao')),
            'numero_pmb' => $this->sportsValue($payload, 'numero_pmb', $existing?->numero_pmb, $user->getAttribute('numero_pmb')),
            'data_inscricao' => $this->sportsValue($payload, 'data_inscricao', $existing?->data_inscricao, $user->getAttribute('data_inscricao')),
            'escalao_id' => $officialAgeGroupId,
            'escalao_calculado_id' => $calculatedAgeGroupId,
            'escalao_manual_override' => $manualOverride,
            'data_atestado_medico' => $this->sportsValue($payload, 'data_atestado_medico', $existing?->data_atestado_medico, $user->getAttribute('data_atestado_medico')),
            'arquivo_atestado_medico' => $this->sportsValue($payload, 'arquivo_atestado_medico', $existing?->arquivo_atestado_medico, $user->getAttribute('arquivo_atestado_medico')),
            'informacoes_medicas' => $this->sportsValue($payload, 'informacoes_medicas', $existing?->informacoes_medicas, $user->getAttribute('informacoes_medicas')),
            'ativo' => $sportsActivityActive,
        ]);
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
        $candidate = $payload['data_nascimento']
            ?? $user->dadosPessoais?->data_nascimento
            ?? $user->getAttribute('data_nascimento');

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

    private function sportsValue(array $payload, string $key, mixed $canonical, mixed $legacy): mixed
    {
        if (array_key_exists($key, $payload)) {
            return $payload[$key];
        }

        return $canonical ?? $legacy;
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

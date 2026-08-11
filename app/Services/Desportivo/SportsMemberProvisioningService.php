<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Models\AthleteSportsData;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsModality;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\Schema;

/**
 * Compatibility adapter kept for existing Membros write flows during F3.
 *
 * Once a canonical F3 participation history exists, generic Membros saves are
 * not allowed to drive Sports-owned activity or technical identifiers from a
 * stale page snapshot. Those mutations must use the explicit Sports contract.
 */
final class SportsMemberProvisioningService
{
    public function __construct(
        private readonly SportsMemberProfileService $sportsMemberProfileService,
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function sync(User $user, array $payload): ?AthleteSportsData
    {
        if (! Schema::hasTable('sports_athlete_participations')) {
            return $this->sportsMemberProfileService->syncFromMemberWrite(
                $user,
                $payload,
                auth()->id(),
            );
        }

        $hasHistory = SportsAthleteParticipation::query()
            ->where('club_id', $this->clubContext->id())
            ->where('user_id', $user->id)
            ->exists();

        if ($hasHistory) {
            // Membros may still carry these legacy values in its local form state,
            // but after F3 they are a projection, not a write authority.
            foreach ([
                'ativo_desportivo',
                'num_federacao',
                'cartao_federacao',
                'numero_pmb',
                'data_inscricao',
                'escalao',
                'escalao_id',
                'escalao_manual_override',
            ] as $sportsOwnedField) {
                unset($payload[$sportsOwnedField]);
            }
        } elseif ($this->hasAthleteType($user, $payload)) {
            $requestedActive = array_key_exists('ativo_desportivo', $payload)
                ? (bool) $payload['ativo_desportivo']
                : null;

            if ($requestedActive === null) {
                return $this->preserveLegacyProfile($user, $payload);
            }

            if ($requestedActive) {
                $activeModalities = SportsModality::query()
                    ->where('club_id', $this->clubContext->id())
                    ->where('active', true)
                    ->whereNull('archived_at')
                    ->count();

                // The legacy flag has no modality dimension. When more than one
                // modality is possible, preserve it for audit instead of guessing.
                if ($activeModalities !== 1) {
                    return $this->preserveLegacyProfile($user, $payload);
                }
            }
        }

        return $this->sportsMemberProfileService->syncFromMemberWrite(
            $user,
            $payload,
            auth()->id(),
        );
    }

    /** @param array<string,mixed> $payload */
    private function preserveLegacyProfile(User $user, array $payload): ?AthleteSportsData
    {
        $profile = AthleteSportsData::query()->firstOrNew(['user_id' => $user->id]);

        foreach ([
            'num_federacao',
            'cartao_federacao',
            'numero_pmb',
            'data_inscricao',
            'data_atestado_medico',
            'arquivo_atestado_medico',
            'informacoes_medicas',
        ] as $field) {
            if (array_key_exists($field, $payload)) {
                $profile->{$field} = $payload[$field];
            }
        }

        if (array_key_exists('ativo_desportivo', $payload)) {
            $profile->ativo = (bool) $payload['ativo_desportivo'];
        }

        if (! $profile->exists || $profile->isDirty()) {
            $profile->save();
        }

        return $profile->fresh();
    }

    /** @param array<string,mixed> $payload */
    private function hasAthleteType(User $user, array $payload): bool
    {
        if (array_key_exists('user_types', $payload) && is_array($payload['user_types'])) {
            return UserType::query()
                ->whereIn('id', $payload['user_types'])
                ->get(['codigo', 'nome'])
                ->contains(fn (UserType $type): bool =>
                    $this->normalizeType($type->codigo ?: $type->nome) === 'atleta'
                );
        }

        $payloadTypes = $payload['tipo_membro'] ?? null;
        if (is_array($payloadTypes)) {
            return collect($payloadTypes)
                ->map(fn (mixed $value): string => $this->normalizeType($value))
                ->contains('atleta');
        }

        $legacyTypes = collect($user->tipo_membro ?? [])
            ->map(fn (mixed $value): string => $this->normalizeType($value));
        if ($legacyTypes->contains('atleta')) {
            return true;
        }

        $user->loadMissing('userTypes:id,codigo,nome');

        return $user->userTypes->contains(function ($type): bool {
            return $this->normalizeType($type->codigo ?? $type->nome ?? '') === 'atleta';
        });
    }

    private function normalizeType(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return in_array($value, ['atleta', 'athlete'], true) ? 'atleta' : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Models\AthleteSportsData;
use App\Models\User;
use App\Services\Members\MemberTypeResolver;

final class SportsMemberStatusResolver
{
    public function __construct(
        private readonly MemberTypeResolver $memberTypeResolver,
    ) {
    }

    public function sportsProfile(User $user): ?AthleteSportsData
    {
        if ($user->relationLoaded('athleteSportsData')) {
            $profile = $user->getRelation('athleteSportsData');

            return $profile instanceof AthleteSportsData ? $profile : null;
        }

        return $user->athleteSportsData()->first();
    }

    public function sportsActivityActive(User $user): bool
    {
        $profile = $this->sportsProfile($user);

        if ($profile !== null) {
            return (bool) $profile->ativo;
        }

        return (bool) $user->ativo_desportivo;
    }

    public function officialAgeGroupId(User $user): ?string
    {
        $profile = $this->sportsProfile($user);

        if ($profile?->escalao_id) {
            return (string) $profile->escalao_id;
        }

        return collect($user->escalao ?? [])
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->first(static fn (string $value): bool => $value !== '') ?: null;
    }

    public function isActiveAthlete(User $user): bool
    {
        return (string) $user->estado === 'ativo'
            && $this->memberTypeResolver->isAthlete($user)
            && $this->sportsActivityActive($user);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Contracts\Members\MemberSportsIdentityProvider;
use App\Models\User;

final class MemberSportsIdentityService implements MemberSportsIdentityProvider
{
    public function __construct(
        private readonly MemberDataReadService $memberDataReadService,
        private readonly MemberTypeResolver $memberTypeResolver,
        private readonly MemberIdentityDisplayResolver $identityDisplay,
    ) {
    }

    public function forSports(User $user): array
    {
        $user->loadMissing(['dadosPessoais', 'userTypes']);
        $personal = $this->memberDataReadService->personalPayload($user);

        return [
            'user_id' => (string) $user->getKey(),
            'display_name' => $this->identityDisplay->displayName($user),
            'birth_date' => $personal['data_nascimento'] ?? null,
            'sex' => $personal['sexo'] ?? null,
            'is_athlete' => $this->memberTypeResolver->isAthlete($user),
            'member_state' => is_string($user->estado) ? $user->estado : null,
        ];
    }
}

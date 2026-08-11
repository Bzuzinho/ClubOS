<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Models\AthleteSportsData;
use App\Models\User;

/**
 * Compatibility adapter kept for existing Membros write flows during F3.
 *
 * It no longer reads Membros-owned data or calculates age groups itself; the
 * canonical SportsMemberProfileService consumes identity exclusively through
 * the MemberSportsIdentityProvider contract.
 */
final class SportsMemberProvisioningService
{
    public function __construct(
        private readonly SportsMemberProfileService $sportsMemberProfileService,
    ) {
    }

    /** @param array<string,mixed> $payload */
    public function sync(User $user, array $payload): ?AthleteSportsData
    {
        return $this->sportsMemberProfileService->syncFromMemberWrite(
            $user,
            $payload,
            auth()->id(),
        );
    }
}

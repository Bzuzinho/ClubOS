<?php

declare(strict_types=1);

namespace App\Contracts\Members;

use App\Models\User;

interface MemberSportsIdentityProvider
{
    /**
     * Facts owned by Membros/Pessoas that Desportivo may consume.
     *
     * @return array{user_id:string,display_name:string,birth_date:?string,sex:?string,is_athlete:bool,member_state:?string}
     */
    public function forSports(User $user): array;
}

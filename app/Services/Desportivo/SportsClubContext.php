<?php

namespace App\Services\Desportivo;

use RuntimeException;

final class SportsClubContext
{
    public function id(): string
    {
        $clubId = trim((string) config('sports.club_id', 'bscn'));

        if ($clubId === '') {
            throw new RuntimeException('SPORTS_CLUB_ID não pode estar vazio.');
        }

        return $clubId;
    }
}

<?php

declare(strict_types=1);

namespace App\Contracts\Desportivo;

interface SportsAudienceProvider
{
    /** @return list<string> */
    public function activeAthleteIds(): array;

    /** @return list<string> */
    public function activeCoachIds(): array;

    /** @return list<string> */
    public function trainingGroupMemberIds(string $trainingGroupId): array;

    /**
     * @param list<string> $ageGroupIds
     * @return list<string>
     */
    public function officialAgeGroupMemberIds(array $ageGroupIds, ?string $seasonId = null): array;
}

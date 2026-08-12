<?php

namespace App\Contracts\Logistica;

interface SportsLogisticsGateway
{
    /**
     * @param list<string> $articleIds
     * @return list<array<string,mixed>>
     */
    public function inspectAvailability(array $articleIds): array;

    public function requestClubEquipment(SportsLogisticsRequest $request): SportsLogisticsRequestResult;
}

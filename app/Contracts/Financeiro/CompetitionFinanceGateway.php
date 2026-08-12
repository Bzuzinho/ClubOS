<?php

declare(strict_types=1);

namespace App\Contracts\Financeiro;

interface CompetitionFinanceGateway
{
    public function ensureDefaultPolicy(string $clubId, string $competitionId): void;

    public function synchronize(CompetitionFinanceRequest $request): void;
}

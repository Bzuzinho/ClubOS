<?php

namespace App\Services\Logistica;

use App\Models\User;
use App\Services\Financeiro\MemberCostCenterResolver;

class LogisticsRequestCostCenterResolver
{
    public function __construct(
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
    ) {
    }

    public function resolveForRequester(?string $requesterUserId): ?string
    {
        if (!$requesterUserId) {
            return null;
        }

        $requester = User::query()->find($requesterUserId);
        if (!$requester) {
            return null;
        }

        $resolved = $this->memberCostCenterResolver->resolveForUser($requester);

        $shares = collect($resolved['centro_custo_pesos'] ?? [])
            ->filter(fn (array $share): bool => !empty($share['id']))
            ->map(fn (array $share): array => [
                'id' => (string) $share['id'],
                'peso' => (float) ($share['peso'] ?? 0),
            ])
            ->values();

        if ($shares->isEmpty()) {
            return null;
        }

        if ($shares->count() === 1) {
            return (string) $shares->first()['id'];
        }

        $maxWeight = (float) $shares->max('peso');

        if ($maxWeight <= 0) {
            return null;
        }

        $topShares = $shares
            ->filter(fn (array $share): bool => abs((float) $share['peso'] - $maxWeight) <= 0.0001)
            ->values();

        if ($topShares->count() !== 1) {
            return null;
        }

        return (string) $topShares->first()['id'];
    }
}

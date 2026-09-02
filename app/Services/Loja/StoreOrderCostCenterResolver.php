<?php

declare(strict_types=1);

namespace App\Services\Loja;

use App\Models\User;
use App\Services\Financeiro\MemberCostCenterResolver;

final class StoreOrderCostCenterResolver
{
    public function __construct(
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
    ) {
    }

    public function resolveForUser(?string $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        $user = User::query()->find($userId);
        if (! $user) {
            return null;
        }

        $resolved = $this->memberCostCenterResolver->resolveForUser($user);

        $shares = collect($resolved['centro_custo_pesos'] ?? [])
            ->filter(fn (array $share): bool => filled($share['id'] ?? null))
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

        return $topShares->count() === 1
            ? (string) $topShares->first()['id']
            : null;
    }
}

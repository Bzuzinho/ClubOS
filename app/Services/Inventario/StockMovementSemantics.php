<?php

declare(strict_types=1);

namespace App\Services\Inventario;

use Illuminate\Support\Facades\Schema;

final class StockMovementSemantics
{
    /**
     * @return array{physical:int,reserved:int,available:int}
     */
    public function deltas(object $movement): array
    {
        $type = (string) ($movement->movement_type ?? '');
        $quantity = (int) ($movement->quantity ?? 0);

        $physical = match ($type) {
            'entry', 'return', 'cancel_reservation', 'adjustment', 'ajuste', 'correction', 'correcao', 'import', 'importacao' => $quantity,
            'exit', 'sale', 'venda' => -abs($quantity),
            default => 0,
        };

        $reserved = match ($type) {
            'reservation' => $quantity,
            'deliver_reservation' => -abs($quantity),
            default => 0,
        };

        return [
            'physical' => $physical,
            'reserved' => $reserved,
            'available' => $physical - $reserved,
        ];
    }

    public function stockFieldSemantics(): string
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'stock')) {
            return 'unknown';
        }

        if (Schema::hasColumn('products', 'stock_reservado')) {
            return 'physical';
        }

        return 'unknown';
    }

    public function isPhysicalMovement(object $movement): bool
    {
        return in_array((string) ($movement->movement_type ?? ''), [
            'entry',
            'return',
            'cancel_reservation',
            'exit',
            'adjustment',
            'ajuste',
            'correction',
            'correcao',
            'import',
            'importacao',
            'sale',
            'venda',
        ], true);
    }

    public function isReservationMovement(object $movement): bool
    {
        return in_array((string) ($movement->movement_type ?? ''), ['reservation', 'deliver_reservation'], true);
    }

    public function isPhysicalDecrease(object $movement): bool
    {
        return $this->deltas($movement)['physical'] < 0;
    }
}

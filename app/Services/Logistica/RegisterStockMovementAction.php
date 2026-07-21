<?php

namespace App\Services\Logistica;

use App\Exceptions\Inventario\InsufficientStockException;
use App\Exceptions\Inventario\InvalidStockMovementException;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Validation\ValidationException;

class RegisterStockMovementAction
{
    public function __construct(
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    public function execute(array $data, ?User $actor = null): StockMovement
    {
        $product = Product::query()->findOrFail($data['article_id']);
        $movementType = (string) $data['movement_type'];
        $quantity = (int) $data['quantity'];
        $context = [
            'source_type' => $data['reference_type'] ?? null,
            'source_id' => $data['reference_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'unit_cost' => $data['unit_cost'] ?? null,
            'created_by' => $actor?->id ?? ($data['created_by'] ?? null),
            'occurred_at' => $data['occurred_at'] ?? null,
            'idempotency_key' => $data['idempotency_key'] ?? null,
        ];

        try {
            return match ($movementType) {
                'entry' => $this->stockLedger->registerEntry($product, $quantity, $context),
                'return' => $this->stockLedger->registerReturn($product, $quantity, $context),
                'exit' => $this->stockLedger->registerExit($product, $quantity, $context),
                'reservation' => $this->stockLedger->reserve($product, $quantity, $context),
                'cancel_reservation' => $this->stockLedger->releaseReservation($product, $quantity, $context),
                'deliver_reservation' => $this->stockLedger->convertReservationToExit($product, $quantity, $context)['exit'],
                default => throw ValidationException::withMessages(['movement_type' => 'Tipo de movimento inválido.']),
            };
        } catch (InsufficientStockException|InvalidStockMovementException $exception) {
            throw ValidationException::withMessages(['quantity' => $exception->getMessage()]);
        }
    }
}

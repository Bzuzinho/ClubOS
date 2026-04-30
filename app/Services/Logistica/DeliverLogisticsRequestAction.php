<?php

namespace App\Services\Logistica;

use App\Models\LogisticsRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliverLogisticsRequestAction
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction
    ) {
    }

    public function execute(LogisticsRequest $request, ?User $actor = null): LogisticsRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $request->refresh()->load('items');

            if (!in_array($request->status, ['approved', 'invoiced'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Apenas requisições aprovadas/faturadas podem ser entregues.',
                ]);
            }

            foreach ($request->items as $item) {
                $this->registerStockMovementAction->execute([
                    'article_id' => $item->article_id,
                    'movement_type' => 'deliver_reservation',
                    'quantity' => (int) $item->quantity,
                    'reference_type' => 'logistics_request',
                    'reference_id' => $request->id,
                    'notes' => 'Consumo da reserva na entrega da requisição',
                ], $actor);
            }

            $request->update([
                'status' => 'delivered',
                'delivered_at' => now(),
            ]);

            return $request->fresh(['items', 'requester', 'financialInvoice']);
        });
    }
}

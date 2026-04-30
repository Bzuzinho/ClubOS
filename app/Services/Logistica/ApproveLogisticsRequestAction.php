<?php

namespace App\Services\Logistica;

use App\Models\LogisticsRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveLogisticsRequestAction
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction
    ) {
    }

    public function execute(LogisticsRequest $request, ?User $actor = null): LogisticsRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $request->refresh()->load('items');

            if (!in_array($request->status, ['draft', 'pending'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Apenas requisições em draft/pendente podem ser aprovadas.',
                ]);
            }

            foreach ($request->items as $item) {
                $this->registerStockMovementAction->execute([
                    'article_id' => $item->article_id,
                    'movement_type' => 'reservation',
                    'quantity' => (int) $item->quantity,
                    'reference_type' => 'logistics_request',
                    'reference_id' => $request->id,
                    'notes' => 'Reserva de stock na aprovação da requisição',
                ], $actor);
            }

            $request->update([
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            return $request->fresh(['items', 'requester', 'financialInvoice']);
        });
    }
}

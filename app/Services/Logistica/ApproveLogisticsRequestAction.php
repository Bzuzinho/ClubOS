<?php

namespace App\Services\Logistica;

use App\Models\LogisticsRequest;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CanonicalProductStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveLogisticsRequestAction
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction,
        private readonly CanonicalProductStockService $stockService,
    ) {
    }

    public function execute(LogisticsRequest $request, ?User $actor = null): LogisticsRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $request = LogisticsRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->with('items')
                ->firstOrFail();

            if (in_array($request->status, ['approved', 'invoiced', 'delivered'], true)) {
                return $request->fresh(['items', 'requester', 'financialInvoice']);
            }

            if (!in_array($request->status, ['draft', 'pending'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Apenas requisições em draft/pendente podem ser aprovadas.',
                ]);
            }

            foreach ($request->items as $item) {
                $product = $item->article_id
                    ? Product::query()->lockForUpdate()->find($item->article_id)
                    : null;

                if (! $product) {
                    throw ValidationException::withMessages([
                        'items' => 'A requisição contém um artigo que já não existe.',
                    ]);
                }

                $this->stockService->ensureRequestable($product, 'items');

                $this->registerStockMovementAction->execute([
                    'article_id' => $item->article_id,
                    'movement_type' => 'reservation',
                    'quantity' => (int) $item->quantity,
                    'reference_type' => 'logistics_request',
                    'reference_id' => $request->id,
                    'notes' => 'Reserva de stock na aprovação da requisição',
                    'idempotency_key' => 'logistics-request-approve-'.$request->id.'-'.$item->id,
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

<?php

namespace App\Services\Logistica;

use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteLogisticsRequestAction
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction
    ) {
    }

    public function execute(LogisticsRequest $logisticsRequest, ?User $actor = null): void
    {
        if (!in_array($logisticsRequest->status, ['draft', 'pending', 'approved', 'invoiced', 'delivered'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Só é possível apagar requisições em estado rascunho, pendente, aprovada, faturada ou entregue.',
            ]);
        }

        DB::transaction(function () use ($logisticsRequest, $actor) {
            $logisticsRequest = LogisticsRequest::query()
                ->whereKey($logisticsRequest->id)
                ->lockForUpdate()
                ->with(['items', 'financialInvoice'])
                ->firstOrFail();

            foreach ($logisticsRequest->items as $item) {
                if (!$item->article_id) {
                    continue;
                }

                if (in_array($logisticsRequest->status, ['approved', 'invoiced'], true)) {
                    $this->registerStockMovementAction->execute([
                        'article_id' => $item->article_id,
                        'movement_type' => 'cancel_reservation',
                        'quantity' => (int) $item->quantity,
                        'reference_type' => 'logistics_request',
                        'reference_id' => $logisticsRequest->id,
                        'notes' => 'Libertação de reserva por eliminação da requisição',
                    ], $actor);
                }

                if ($logisticsRequest->status === 'delivered') {
                    $this->registerStockMovementAction->execute([
                        'article_id' => $item->article_id,
                        'movement_type' => 'return',
                        'quantity' => (int) $item->quantity,
                        'reference_type' => 'logistics_request',
                        'reference_id' => $logisticsRequest->id,
                        'notes' => 'Reposição de stock físico por eliminação da requisição entregue',
                    ], $actor);
                }
            }

            if ($logisticsRequest->financial_invoice_id) {
                Invoice::query()->whereKey($logisticsRequest->financial_invoice_id)->delete();
            }

            $logisticsRequest->items()->delete();
            $logisticsRequest->delete();
        });
    }
}

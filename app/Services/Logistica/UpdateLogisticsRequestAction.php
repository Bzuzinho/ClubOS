<?php

namespace App\Services\Logistica;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LogisticsRequest;
use App\Models\LogisticsRequestItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CanonicalProductStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class UpdateLogisticsRequestAction
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction,
        private readonly CanonicalProductStockService $stockService,
        private readonly LogisticsRequestFinancialGuardService $financialGuardService,
        private readonly LogisticsRequestCostCenterResolver $costCenterResolver,
    ) {
    }

    public function execute(LogisticsRequest $logisticsRequest, array $data, ?User $actor = null): LogisticsRequest
    {
        if (!in_array($logisticsRequest->status, ['draft', 'pending', 'approved', 'invoiced', 'delivered'], true)) {
            throw ValidationException::withMessages([
                'status' => 'Só é possível editar requisições em estado rascunho, pendente, aprovada, faturada ou entregue.',
            ]);
        }

        return DB::transaction(function () use ($logisticsRequest, $data, $actor) {
            $logisticsRequest = LogisticsRequest::query()
                ->whereKey($logisticsRequest->id)
                ->lockForUpdate()
                ->with(['items', 'financialInvoice'])
                ->firstOrFail();

            $items = $data['items'] ?? [];
            $total = 0.0;

            $oldQuantities = $logisticsRequest->items
                ->whereNotNull('article_id')
                ->groupBy('article_id')
                ->map(fn ($group) => (int) $group->sum('quantity'));

            $newQuantities = collect($items)
                ->groupBy(fn ($item) => $item['article_id'] ?? null)
                ->filter(fn ($_, $articleId) => !empty($articleId))
                ->map(fn ($group) => (int) collect($group)->sum(fn ($row) => (int) ($row['quantity'] ?? 0)));

            if ($logisticsRequest->financial_invoice_id) {
                if (empty($data['requester_user_id'])) {
                    throw ValidationException::withMessages([
                        'requester_user_id' => 'A requisição faturada precisa de utilizador associado.',
                    ]);
                }

                if (!$this->financialGuardService->canMutate($logisticsRequest)) {
                    throw ValidationException::withMessages([
                        'request' => 'Esta requisição já possui pagamento, conciliação ou documento financeiro associado e não pode ser alterada diretamente.',
                    ]);
                }
            }

            LogisticsRequestItem::query()
                ->where('logistics_request_id', $logisticsRequest->id)
                ->delete();

            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item['article_id']);
                $quantity = (int) $item['quantity'];

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Quantidade inválida nos itens da requisição.',
                    ]);
                }


                $oldQuantityForProduct = (int) ($oldQuantities->get($product->id) ?? 0);
                $newQuantityForProduct = (int) ($newQuantities->get($product->id) ?? 0);
                if ($newQuantityForProduct > $oldQuantityForProduct) {
                    $this->stockService->ensureRequestable($product, 'items');
                }

                $unitPrice = isset($item['unit_price'])
                    ? (float) $item['unit_price']
                    : $this->stockService->defaultUnitPrice($product);
                $lineTotal = $quantity * $unitPrice;

                LogisticsRequestItem::create([
                    'logistics_request_id' => $logisticsRequest->id,
                    'article_id' => $product->id,
                    'article_name_snapshot' => $product->nome,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]);

                $total += $lineTotal;
            }

            $logisticsRequest->update([
                'requester_user_id' => $data['requester_user_id'] ?? null,
                'requester_name_snapshot' => $data['requester_name_snapshot'],
                'requester_area' => $data['requester_area'] ?? null,
                'requester_type' => $data['requester_type'] ?? null,
                'notes' => $data['notes'] ?? null,
                'total_amount' => $total,
            ]);

            $articleIds = $oldQuantities->keys()
                ->merge($newQuantities->keys())
                ->unique()
                ->values();

            foreach ($articleIds as $articleId) {
                $oldQty = (int) ($oldQuantities->get($articleId) ?? 0);
                $newQty = (int) ($newQuantities->get($articleId) ?? 0);
                $delta = $newQty - $oldQty;

                if ($delta === 0) {
                    continue;
                }

                if (in_array($logisticsRequest->status, ['approved', 'invoiced'], true)) {
                    if ($delta > 0) {
                        $this->registerStockMovementAction->execute([
                            'article_id' => $articleId,
                            'movement_type' => 'reservation',
                            'quantity' => $delta,
                            'reference_type' => 'logistics_request',
                            'reference_id' => $logisticsRequest->id,
                            'notes' => 'Ajuste de reserva por aumento da requisição',
                        ], $actor);
                    }

                    if ($delta < 0) {
                        $this->registerStockMovementAction->execute([
                            'article_id' => $articleId,
                            'movement_type' => 'cancel_reservation',
                            'quantity' => abs($delta),
                            'reference_type' => 'logistics_request',
                            'reference_id' => $logisticsRequest->id,
                            'notes' => 'Libertação de reserva por redução da requisição',
                        ], $actor);
                    }
                }

                if ($logisticsRequest->status === 'delivered') {
                    if ($delta > 0) {
                        $this->registerStockMovementAction->execute([
                            'article_id' => $articleId,
                            'movement_type' => 'exit',
                            'quantity' => $delta,
                            'reference_type' => 'logistics_request',
                            'reference_id' => $logisticsRequest->id,
                            'notes' => 'Ajuste de stock físico por aumento da requisição entregue',
                        ], $actor);
                    }

                    if ($delta < 0) {
                        $this->registerStockMovementAction->execute([
                            'article_id' => $articleId,
                            'movement_type' => 'return',
                            'quantity' => abs($delta),
                            'reference_type' => 'logistics_request',
                            'reference_id' => $logisticsRequest->id,
                            'notes' => 'Reposição de stock físico por redução da requisição entregue',
                        ], $actor);
                    }
                }
            }

            if ($logisticsRequest->financial_invoice_id) {
                $invoice = Invoice::query()
                    ->whereKey($logisticsRequest->financial_invoice_id)
                    ->lockForUpdate()
                    ->first();

                if ($invoice) {
                    $centroCustoId = $this->costCenterResolver->resolveForRequester($data['requester_user_id']);
                    $invoiceTotal = round($total, 2);
                    $dueDate = $invoice->data_vencimento
                        ? Carbon::parse($invoice->data_vencimento)->startOfDay()
                        : null;
                    $status = $dueDate && $dueDate->lt(now()->startOfDay()) ? 'vencido' : 'pendente';

                    $invoice->update([
                        'user_id' => $data['requester_user_id'],
                        'valor_total' => $invoiceTotal,
                        'valor_pago' => 0,
                        'valor_em_aberto' => $invoiceTotal,
                        'estado_pagamento' => $status,
                        'data_pagamento' => null,
                        'metodo_pagamento' => null,
                        'pagamento_observacoes' => null,
                        'centro_custo_id' => $centroCustoId,
                        'origem_tipo' => 'logistics_request',
                        'origem_id' => $logisticsRequest->id,
                        'observacoes' => $data['notes'] ?? $invoice->observacoes,
                    ]);

                    InvoiceItem::query()->where('fatura_id', $invoice->id)->delete();

                    foreach ($logisticsRequest->items()->get() as $requestItem) {
                        $quantity = (int) $requestItem->quantity;
                        $unitPrice = (float) $requestItem->unit_price;

                        InvoiceItem::create([
                            'fatura_id' => $invoice->id,
                            'descricao' => $requestItem->article_name_snapshot,
                            'quantidade' => $quantity,
                            'valor_unitario' => $unitPrice,
                            'imposto_percentual' => 0,
                            'total_linha' => round((float) $requestItem->line_total, 2),
                            'produto_id' => $requestItem->article_id,
                        ]);
                    }
                }
            }

            return $logisticsRequest->fresh(['items']);
        });
    }
}

<?php

namespace App\Services\Logistica;

use App\Models\CostCenter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LogisticsRequest;
use App\Models\LogisticsRequestItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CanonicalProductStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateLogisticsRequestAction
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction,
        private readonly CanonicalProductStockService $stockService,
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

            if ($logisticsRequest->financial_invoice_id && empty($data['requester_user_id'])) {
                throw ValidationException::withMessages([
                    'requester_user_id' => 'A requisição faturada precisa de utilizador associado.',
                ]);
            }

            LogisticsRequestItem::query()
                ->where('logistics_request_id', $logisticsRequest->id)
                ->delete();

            foreach ($items as $item) {
                $product = Product::query()->findOrFail($item['article_id']);
                $quantity = (int) $item['quantity'];

                if ($quantity <= 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Quantidade inválida nos itens da requisição.',
                    ]);
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
                    $requester = User::query()->find($data['requester_user_id']);
                    $requesterEscalao = $requester?->escalao;
                    $escalaoNome = is_array($requesterEscalao)
                        ? (string) collect($requesterEscalao)->filter()->first()
                        : (string) ($requesterEscalao ?? '');

                    $centroCustoId = null;

                    if ($escalaoNome !== '') {
                        $normalizedEscalao = mb_strtolower(trim($escalaoNome));

                        $centroCustoId = CostCenter::query()
                            ->where(function ($query) use ($normalizedEscalao) {
                                $query->whereRaw('LOWER(nome) = ?', [$normalizedEscalao])
                                    ->orWhereRaw('LOWER(codigo) = ?', [$normalizedEscalao]);
                            })
                            ->value('id');
                    }

                    $invoice->update([
                        'user_id' => $data['requester_user_id'],
                        'valor_total' => $total,
                        'centro_custo_id' => $centroCustoId,
                    ]);

                    InvoiceItem::query()->where('fatura_id', $invoice->id)->delete();

                    foreach ($items as $item) {
                        $product = Product::query()->findOrFail($item['article_id']);
                        $quantity = (int) $item['quantity'];
                        $unitPrice = (float) ($item['unit_price'] ?? 0);

                        InvoiceItem::create([
                            'fatura_id' => $invoice->id,
                            'descricao' => $product->nome,
                            'quantidade' => $quantity,
                            'valor_unitario' => $unitPrice,
                            'imposto_percentual' => 0,
                            'total_linha' => $quantity * $unitPrice,
                            'produto_id' => $product->id,
                        ]);
                    }
                }
            }

            return $logisticsRequest->fresh(['items']);
        });
    }
}

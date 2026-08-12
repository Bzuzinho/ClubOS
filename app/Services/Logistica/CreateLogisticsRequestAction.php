<?php

namespace App\Services\Logistica;

use App\Models\LogisticsRequest;
use App\Models\LogisticsRequestItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CanonicalProductStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateLogisticsRequestAction
{
    public function __construct(
        private readonly CanonicalProductStockService $stockService,
    ) {
    }

    public function execute(array $data, ?User $actor = null): LogisticsRequest
    {
        return DB::transaction(function () use ($data, $actor) {
            $items = $data['items'] ?? [];

            if (empty($items)) {
                throw ValidationException::withMessages(['items' => 'A requisição precisa de pelo menos um artigo.']);
            }

            $request = LogisticsRequest::create([
                'requester_user_id' => $data['requester_user_id'] ?? null,
                'requester_name_snapshot' => $data['requester_name_snapshot'],
                'requester_area' => $data['requester_area'] ?? null,
                'requester_type' => $data['requester_type'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'notes' => $data['notes'] ?? null,
                'total_amount' => 0,
                'created_by' => $actor?->id,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'idempotency_key' => $data['idempotency_key'] ?? null,
            ]);

            $total = 0;
            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item['article_id']);
                $quantity = (int) $item['quantity'];

                if ($quantity <= 0) {
                    throw ValidationException::withMessages(['items' => 'Quantidade inválida nos itens da requisição.']);
                }

                $allowOverdraw = (bool) ($data['allow_overdraw'] ?? false);

                if (! $allowOverdraw) {
                    $this->stockService->ensureAvailable(
                        $product,
                        $quantity,
                        'items',
                        "Stock insuficiente para o artigo {$product->nome}.",
                    );
                }

                $unitPrice = isset($item['unit_price'])
                    ? (float) $item['unit_price']
                    : $this->stockService->defaultUnitPrice($product);
                $lineTotal = $unitPrice * $quantity;

                LogisticsRequestItem::create([
                    'logistics_request_id' => $request->id,
                    'article_id' => $product->id,
                    'article_name_snapshot' => $product->nome,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ]);

                $total += $lineTotal;
            }

            $request->update(['total_amount' => $total]);

            return $request->fresh(['items', 'requester']);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Loja;

use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class CancelStoreOrderStockAction
{
    private const STORE_SOURCES = ['store_order_item', 'loja_encomenda_item'];

    private const EXIT_TYPES = ['exit', 'sale', 'venda'];

    public function __construct(
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    public function execute(LojaEncomenda $order, User $actor): void
    {
        $order->loadMissing(['itens.article']);

        foreach ($order->itens as $item) {
            $this->restoreItem($item, $actor);
        }
    }

    private function restoreItem(LojaEncomendaItem $item, User $actor): void
    {
        $movements = StockMovement::query()
            ->where('article_id', $item->article_id)
            ->whereIn('reference_type', self::STORE_SOURCES)
            ->where('reference_id', $item->id)
            ->lockForUpdate()
            ->get();

        $exits = $movements->whereIn('movement_type', self::EXIT_TYPES);
        $returns = $movements->where('movement_type', 'return');
        $exitQuantity = $this->absoluteQuantity($exits);
        $returnQuantity = $this->absoluteQuantity($returns);

        if ($exitQuantity === 0 && $returnQuantity === 0) {
            return;
        }

        $expectedQuantity = (int) $item->quantidade;
        if ($exits->count() !== 1 || $exitQuantity !== $expectedQuantity || $returnQuantity > $exitQuantity) {
            throw ValidationException::withMessages([
                'estado' => 'O stock desta encomenda tem movimentos incoerentes e requer revisão antes do cancelamento.',
            ]);
        }

        $remainingQuantity = $exitQuantity - $returnQuantity;
        if ($remainingQuantity === 0) {
            return;
        }

        $product = $item->article;
        if (! $product) {
            throw ValidationException::withMessages([
                'estado' => 'Não foi possível localizar o produto necessário para repor o stock.',
            ]);
        }

        $exitVariantIds = $exits->pluck('product_variant_id')->map(
            static fn (mixed $id): ?string => filled($id) ? (string) $id : null,
        )->unique()->values();
        $itemVariantId = filled($item->product_variant_id) ? (string) $item->product_variant_id : null;

        if ($exitVariantIds->count() !== 1 || $exitVariantIds->first() !== $itemVariantId) {
            throw ValidationException::withMessages([
                'estado' => 'A variante associada ao movimento de stock não coincide com a encomenda.',
            ]);
        }

        $this->stockLedger->registerReturn($product, $remainingQuantity, [
            'product_variant_id' => $itemVariantId,
            'source_type' => 'store_order_item',
            'source_id' => (string) $item->id,
            'idempotency_key' => 'store-order-item-cancel-'.$item->id,
            'notes' => 'Reposição de stock por cancelamento da encomenda da loja',
            'created_by' => (string) $actor->id,
            'occurred_at' => now(),
        ]);
    }

    /**
     * @param Collection<int,StockMovement> $movements
     */
    private function absoluteQuantity(Collection $movements): int
    {
        return $movements->sum(static fn (StockMovement $movement): int => abs((int) $movement->quantity));
    }
}

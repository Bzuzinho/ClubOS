<?php

namespace App\Services\Logistica;

use App\Models\EquipmentLoan;
use App\Models\Product;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateEquipmentLoanAction
{
    public function __construct(
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    public function execute(EquipmentLoan $loan, array $data): EquipmentLoan
    {
        if (!in_array($loan->status, ['active', 'overdue'])) {
            throw ValidationException::withMessages([
                'status' => 'Só é possível editar empréstimos ativos ou em atraso.',
            ]);
        }

        return DB::transaction(function () use ($loan, $data) {
            $newArticleId = $data['article_id'];
            $newQuantity = (int) $data['quantity'];
            $oldQuantity = (int) $loan->quantity;
            $oldArticleId = $loan->article_id;
            $idempotencyBase = implode('-', [
                'equipment-loan-update',
                $loan->id,
                $oldArticleId,
                $oldQuantity,
                $newArticleId,
                $newQuantity,
                optional($loan->updated_at)->timestamp ?? 'no-ts',
            ]);

            if ($oldArticleId === $newArticleId) {
                $diff = $newQuantity - $oldQuantity;
                if ($diff !== 0) {
                    $product = Product::query()->lockForUpdate()->findOrFail($newArticleId);
                    $this->applyLoanStockDelta(
                        product: $product,
                        quantityDelta: $diff,
                        sourceType: 'equipment_loan_update',
                        sourceId: $loan->id,
                        idempotencyKey: $idempotencyBase.'-same-article',
                        insufficientMessage: 'Stock insuficiente para atualizar o empréstimo.',
                        notes: 'Ajuste de stock por edição de empréstimo',
                    );
                }
            } else {
                $oldProduct = Product::query()->lockForUpdate()->findOrFail($oldArticleId);
                $this->stockLedger->registerReturn($oldProduct, $oldQuantity, [
                    'source_type' => 'equipment_loan_update',
                    'source_id' => $loan->id,
                    'notes' => 'Reposição do artigo anterior por edição de empréstimo',
                    'created_by' => $loan->created_by,
                    'occurred_at' => now(),
                    'idempotency_key' => $idempotencyBase.'-old-article-return',
                ]);

                $newProduct = Product::query()->lockForUpdate()->findOrFail($newArticleId);
                $this->applyLoanStockDelta(
                    product: $newProduct,
                    quantityDelta: $newQuantity,
                    sourceType: 'equipment_loan_update',
                    sourceId: $loan->id,
                    idempotencyKey: $idempotencyBase.'-new-article-exit',
                    insufficientMessage: 'Stock insuficiente no artigo selecionado.',
                    notes: 'Saída do novo artigo por edição de empréstimo',
                );
            }

            $newProduct = Product::query()->find($newArticleId);

            $loan->update([
                'borrower_user_id' => $data['borrower_user_id'] ?? null,
                'borrower_name_snapshot' => $data['borrower_name_snapshot'],
                'article_id' => $newArticleId,
                'article_name_snapshot' => $newProduct?->nome ?? $loan->article_name_snapshot,
                'quantity' => $newQuantity,
                'loan_date' => $data['loan_date'],
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            return $loan->fresh();
        });
    }

    private function applyLoanStockDelta(
        Product $product,
        int $quantityDelta,
        string $sourceType,
        string $sourceId,
        string $idempotencyKey,
        string $insufficientMessage,
        string $notes,
    ): void {
        try {
            if ($quantityDelta > 0) {
                $this->stockLedger->registerExit($product, $quantityDelta, [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'notes' => $notes,
                    'occurred_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);
            } else {
                $this->stockLedger->registerReturn($product, abs($quantityDelta), [
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'notes' => $notes,
                    'occurred_at' => now(),
                    'idempotency_key' => $idempotencyKey,
                ]);
            }
        } catch (\App\Exceptions\Inventario\InsufficientStockException) {
            throw ValidationException::withMessages([
                'quantity' => $insufficientMessage,
            ]);
        }
    }
}

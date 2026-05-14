<?php

namespace App\Services\Logistica;

use App\Models\FinancialEntry;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementItem;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPurchase;
use App\Models\SupplierPurchaseItem;
use App\Models\User;
use App\Services\Financeiro\MovementDocumentControlService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSupplierPurchaseAction
{
    public function __construct(
        private readonly MovementDocumentControlService $movementDocumentControlService,
    ) {
    }

    public function execute(SupplierPurchase $purchase, array $data, ?User $actor = null): SupplierPurchase
    {
        return DB::transaction(function () use ($purchase, $data, $actor) {
            $purchase->refresh()->load('items');

            $items = $data['items'] ?? [];
            if (empty($items)) {
                throw ValidationException::withMessages(['items' => 'A compra deve ter pelo menos um item.']);
            }

            // Reverter impacto de stock da versão atual da compra
            foreach ($purchase->items as $existingItem) {
                if (empty($existingItem->article_id)) {
                    continue;
                }

                $product = Product::query()->lockForUpdate()->find($existingItem->article_id);
                if (!$product) {
                    continue;
                }

                $newStock = (int) $product->stock - (int) $existingItem->quantity;
                if ($newStock < 0) {
                    throw ValidationException::withMessages([
                        'items' => 'Não foi possível reverter o stock anterior da compra.',
                    ]);
                }

                $product->stock = $newStock;
                $product->save();
            }

            SupplierPurchaseItem::query()->where('supplier_purchase_id', $purchase->id)->delete();

            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $total = 0.0;

            foreach ($items as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item['article_id']);
                $quantity = (int) $item['quantity'];
                $unitCost = (float) $item['unit_cost'];
                $lineTotal = $quantity * $unitCost;

                SupplierPurchaseItem::create([
                    'supplier_purchase_id' => $purchase->id,
                    'article_id' => $product->id,
                    'article_name_snapshot' => $product->nome,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ]);

                $product->stock = (int) $product->stock + $quantity;
                $product->save();

                $total += $lineTotal;
            }

            $purchase->update([
                'supplier_id' => $supplier->id,
                'supplier_name_snapshot' => $supplier->nome,
                'invoice_reference' => $data['invoice_reference'],
                'invoice_date' => $data['invoice_date'],
                'total_amount' => $total,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($purchase->financial_movement_id) {
                $movement = Movement::query()->find($purchase->financial_movement_id);
                if ($movement) {
                    $movement->update([
                        'supplier_id' => $supplier->id,
                        'nome_manual' => $supplier->nome,
                        'categoria' => 'compras_stock',
                        'data_emissao' => $purchase->invoice_date,
                        'data_vencimento' => $data['due_date'] ?? $purchase->invoice_date,
                        'valor_total' => -abs($total),
                        'centro_custo_id' => $data['centro_custo_id'] ?? $movement->centro_custo_id,
                        'referencia_pagamento' => $purchase->invoice_reference,
                        'observacoes' => 'Despesa atualizada pela compra de fornecedor na logística.',
                    ]);

                    MovementItem::query()->where('movimento_id', $movement->id)->delete();
                    foreach ($purchase->items()->get() as $purchaseItem) {
                        MovementItem::create([
                            'movimento_id' => $movement->id,
                            'descricao' => $purchaseItem->article_name_snapshot,
                            'quantidade' => $purchaseItem->quantity,
                            'valor_unitario' => $purchaseItem->unit_cost,
                            'imposto_percentual' => 0,
                            'total_linha' => $purchaseItem->line_total,
                            'produto_id' => $purchaseItem->article_id,
                            'centro_custo_id' => $data['centro_custo_id'] ?? $movement->centro_custo_id,
                        ]);
                    }
                }
            }

            if ($purchase->financial_entry_id) {
                $entry = FinancialEntry::query()->find($purchase->financial_entry_id);
                if ($entry) {
                    $entry->update([
                        'data' => $purchase->invoice_date,
                        'categoria' => 'Compras fornecedor',
                        'descricao' => 'Compra a fornecedor: '.$supplier->nome,
                        'documento_ref' => $purchase->invoice_reference,
                        'valor' => $total,
                        'centro_custo_id' => $data['centro_custo_id'] ?? $entry->centro_custo_id,
                        'metodo_pagamento' => $data['metodo_pagamento'] ?? $entry->metodo_pagamento,
                        'user_id' => $actor?->id ?? $entry->user_id,
                    ]);
                }
            }

            if ($purchase->financial_movement_id) {
                $movement = Movement::query()->find($purchase->financial_movement_id);
                if ($movement && !empty($data['attachment'])) {
                    $attachment = $data['attachment'];

                    $document = MovementDocument::query()
                        ->where('movement_id', $movement->id)
                        ->where('source_type', 'logistics')
                        ->latest('created_at')
                        ->first();

                    $document ??= new MovementDocument();
                    $document->fill([
                        'movement_id' => $movement->id,
                        'supplier_id' => $supplier->id,
                        'document_type' => $data['document_type'] ?? 'invoice',
                        'source_type' => 'logistics',
                        'source_id' => $purchase->id,
                        'original_filename' => $attachment->getClientOriginalName(),
                        'stored_path' => $attachment->store('financeiro/movimentos/documentos', 'public'),
                        'mime_type' => $attachment->getClientMimeType(),
                        'sha256_hash' => hash_file('sha256', $attachment->getRealPath()),
                        'document_number' => $purchase->invoice_reference,
                        'issue_date' => $purchase->invoice_date,
                        'due_date' => $data['due_date'] ?? $purchase->invoice_date,
                        'amount' => $total,
                        'vat_amount' => $data['vat_amount'] ?? null,
                        'status' => 'pending_validation',
                        'notes' => $data['notes'] ?? null,
                    ]);
                    $document->save();

                    $this->movementDocumentControlService->refresh($movement->fresh());
                }
            }

            return $purchase->fresh(['items', 'supplier', 'financialMovement', 'financialEntry']);
        });
    }
}

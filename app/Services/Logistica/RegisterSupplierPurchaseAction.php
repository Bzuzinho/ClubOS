<?php

namespace App\Services\Logistica;

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

class RegisterSupplierPurchaseAction
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction,
        private MovementDocumentControlService $movementDocumentControlService,
    ) {
    }

    public function execute(array $data, ?User $actor = null): SupplierPurchase
    {
        return DB::transaction(function () use ($data, $actor) {
            $items = $data['items'] ?? [];

            if (empty($items)) {
                throw ValidationException::withMessages(['items' => 'A compra deve ter pelo menos um item.']);
            }

            $supplier = Supplier::query()->findOrFail($data['supplier_id']);

            $purchase = SupplierPurchase::create([
                'supplier_id' => $supplier->id,
                'supplier_name_snapshot' => $supplier->nome,
                'invoice_reference' => $data['invoice_reference'],
                'invoice_date' => $data['invoice_date'],
                'total_amount' => 0,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $total = 0;
            foreach ($items as $item) {
                $product = Product::query()->findOrFail($item['article_id']);
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

                $this->registerStockMovementAction->execute([
                    'article_id' => $product->id,
                    'movement_type' => 'entry',
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'reference_type' => 'supplier_purchase',
                    'reference_id' => $purchase->id,
                    'notes' => 'Entrada de stock por compra a fornecedor',
                ], $actor);

                $total += $lineTotal;
            }

            $purchase->update(['total_amount' => $total]);

            $movement = Movement::create([
                'supplier_id' => $supplier->id,
                'nome_manual' => $purchase->supplier_name_snapshot,
                'classificacao' => 'despesa',
                'categoria' => 'compras_stock',
                'data_emissao' => $purchase->invoice_date,
                'data_vencimento' => $data['due_date'] ?? $purchase->invoice_date,
                'valor_total' => abs($total),
                'estado_pagamento' => 'por_pagar',
                'estado_conciliacao' => 'nao_conciliado',
                'estado_documental' => 'sem_documentos',
                'centro_custo_id' => $data['centro_custo_id'] ?? null,
                'tipo' => 'fornecedor',
                'origem_tipo' => 'supplier_purchase',
                'origem_id' => $purchase->id,
                'referencia_pagamento' => $purchase->invoice_reference,
                'observacoes' => 'Despesa gerada pela compra de fornecedor na logística.',
            ]);

            foreach ($purchase->items as $item) {
                MovementItem::create([
                    'movimento_id' => $movement->id,
                    'descricao' => $item->article_name_snapshot,
                    'quantidade' => $item->quantity,
                    'valor_unitario' => $item->unit_cost,
                    'imposto_percentual' => 0,
                    'total_linha' => $item->line_total,
                    'produto_id' => $item->article_id,
                    'centro_custo_id' => $data['centro_custo_id'] ?? null,
                ]);
            }

            $purchase->update([
                'financial_movement_id' => $movement->id,
            ]);

            if (!empty($data['attachment'])) {
                $attachment = $data['attachment'];

                MovementDocument::query()->create([
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
            }

            $this->movementDocumentControlService->refresh($movement->fresh());

            return $purchase->fresh(['items', 'supplier', 'financialMovement', 'financialEntry']);
        });
    }
}

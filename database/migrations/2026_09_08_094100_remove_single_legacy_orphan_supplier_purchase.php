<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

return new class extends Migration
{
    /**
     * Remove exclusivamente a compra legacy de teste que ficou órfã antes do cutover
     * financeiro e cujo efeito de stock foi eliminado no reset operacional de 2026-09-08.
     *
     * Não cria ligações financeiras retroativas. Se existir qualquer evidência financeira,
     * documental ou de stock, a migração aborta em vez de apagar silenciosamente.
     */
    public function up(): void
    {
        if (! Schema::hasTable('supplier_purchases')) {
            return;
        }

        DB::transaction(function (): void {
            $candidates = DB::table('supplier_purchases')
                ->whereNull('financial_movement_id')
                ->whereNull('financial_entry_id')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            if ($candidates->isEmpty()) {
                return;
            }

            if ($candidates->count() !== 1) {
                throw new RuntimeException('legacy_supplier_purchase_cleanup_expected_exactly_one_orphan');
            }

            $purchase = $candidates->first();
            $purchaseId = (string) $purchase->id;
            $items = Schema::hasTable('supplier_purchase_items')
                ? DB::table('supplier_purchase_items')->where('supplier_purchase_id', $purchaseId)->get()
                : collect();
            $itemIds = $items->pluck('id')->filter()->map('strval')->values()->all();

            $hasFinancialMovement = Schema::hasTable('movements')
                && DB::table('movements')
                    ->where('origem_tipo', 'supplier_purchase')
                    ->where('origem_id', $purchaseId)
                    ->exists();

            $hasLegacyEntry = Schema::hasTable('financial_entries')
                && DB::table('financial_entries')
                    ->whereIn('origem_tipo', ['stock', 'supplier_purchase'])
                    ->where('origem_id', $purchaseId)
                    ->exists();

            $hasStockEvidence = false;
            if (Schema::hasTable('stock_movements')) {
                $hasStockEvidence = DB::table('stock_movements')
                    ->where(function ($query) use ($purchaseId, $itemIds): void {
                        $query->where(function ($initial) use ($purchaseId): void {
                            $initial->where('reference_type', 'supplier_purchase')
                                ->where('reference_id', $purchaseId);
                        });

                        if ($itemIds !== []) {
                            $query->orWhere(function ($updated) use ($itemIds): void {
                                $updated->whereIn('reference_type', [
                                    'supplier_purchase_update_entry',
                                    'supplier_purchase_update_reversal',
                                    'supplier_purchase_delete',
                                ])->whereIn('reference_id', $itemIds);
                            });
                        }
                    })
                    ->exists();
            }

            $hasDocumentEvidence = Schema::hasTable('movement_documents')
                && Schema::hasColumn('movement_documents', 'source_id')
                && DB::table('movement_documents')
                    ->where('source_type', 'logistics')
                    ->where('source_id', $purchaseId)
                    ->exists();

            if ($hasFinancialMovement || $hasLegacyEntry || $hasStockEvidence || $hasDocumentEvidence) {
                throw new RuntimeException('legacy_supplier_purchase_cleanup_found_linked_evidence');
            }

            if (Schema::hasTable('supplier_purchase_deletion_audits')) {
                DB::table('supplier_purchase_deletion_audits')->insert([
                    'id' => (string) Str::uuid(),
                    'supplier_purchase_id' => $purchaseId,
                    'supplier_id' => $purchase->supplier_id,
                    'supplier_name_snapshot' => $purchase->supplier_name_snapshot,
                    'invoice_reference' => $purchase->invoice_reference,
                    'invoice_date' => $purchase->invoice_date,
                    'total_amount' => $purchase->total_amount,
                    'financial_movement_id' => null,
                    'deleted_by' => null,
                    'deletion_mode' => 'legacy_test_cleanup',
                    'reason' => 'Compra de teste órfã removida após reset operacional de stock.',
                    'payload' => json_encode([
                        'purchase' => (array) $purchase,
                        'items' => $items->map(fn (object $item): array => (array) $item)->all(),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'deleted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (Schema::hasTable('supplier_purchase_items')) {
                DB::table('supplier_purchase_items')->where('supplier_purchase_id', $purchaseId)->delete();
            }

            DB::table('supplier_purchases')->where('id', $purchaseId)->delete();
        });
    }

    public function down(): void
    {
        // Irreversível: o registo removido era dados de teste; a auditoria preserva o snapshot.
    }
};

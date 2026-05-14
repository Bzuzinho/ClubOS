<?php

namespace App\Services\Financeiro;

use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\Payment;
use App\Models\ReceiptImportBatch;
use App\Models\ReceiptImportItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ReceiptCommitService
{
    public function __construct(
        private readonly FinancialSettlementService $financialSettlementService,
        private readonly ReconciliationAliasService $aliasService,
        private readonly BankDescriptionParser $descriptionParser,
    ) {
    }

    public function commitItems(ReceiptImportBatch $batch, array $itemIds, User $actor): ReceiptImportBatch
    {
        return DB::transaction(function () use ($batch, $itemIds, $actor) {
            $items = ReceiptImportItem::query()
                ->with(['invoice', 'bankStatement'])
                ->where('batch_id', $batch->id)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Selecione pelo menos um recibo para confirmar.',
                ]);
            }

            foreach ($items as $item) {
                $this->commitItem($item, $actor);
            }

            $batch->forceFill([
                'status' => ReceiptImportBatch::STATUS_COMMITTED,
                'imported_count' => ReceiptImportItem::query()
                    ->where('batch_id', $batch->id)
                    ->where('status', ReceiptImportItem::STATUS_IMPORTED)
                    ->count(),
                'committed_by' => $actor->id,
                'committed_at' => now(),
            ])->save();

            Log::info('Receipt import batch committed.', [
                'batch_id' => $batch->id,
                'item_ids' => $items->pluck('id')->all(),
                'actor_id' => $actor->id,
            ]);

            return $batch->fresh(['items.user', 'items.invoice', 'items.bankStatement']);
        });
    }

    private function commitItem(ReceiptImportItem $item, User $actor): void
    {
        $invoice = $item->invoice;
        $bankStatement = $item->bankStatement;

        if (!$invoice || !$bankStatement || !$item->user_id || !$item->numero_recibo) {
            throw ValidationException::withMessages([
                'items' => 'Cada item precisa de utilizador, fatura, numero de recibo e movimento bancario antes da confirmacao.',
            ]);
        }

        if ($item->status === ReceiptImportItem::STATUS_IMPORTED) {
            return;
        }

        $openAmount = round((float) ($invoice->valor_em_aberto ?? $invoice->valor_total), 2);
        if ($openAmount <= 0) {
            throw ValidationException::withMessages([
                'items' => 'A fatura selecionada ja nao tem valor em aberto.',
            ]);
        }

        $availableAmount = $this->availableBankStatementAmount($bankStatement);
        if ($openAmount - $availableAmount > 0.009) {
            throw ValidationException::withMessages([
                'bank_statement_id' => 'A alocacao excede o valor disponivel do movimento bancario.',
            ]);
        }

        $payment = $this->financialSettlementService->settleInvoices([
            [
                'invoice_id' => $invoice->id,
                'amount' => $openAmount,
                'notes' => 'Importacao assistida de recibo antigo.',
                'metadata' => [
                    'receipt_import_item_id' => $item->id,
                ],
            ],
        ], [
            'bank_statement_id' => $bankStatement->id,
            'user_id' => $item->user_id,
            'amount' => $openAmount,
            'payment_date' => optional($item->recibo_emitido_em)?->toDateString() ?? optional($bankStatement->data_movimento)?->toDateString() ?? now()->toDateString(),
            'method' => 'transferencia',
            'reference' => $item->numero_recibo,
            'description' => sprintf('Importacao recibo %s', $item->numero_recibo),
            'notes' => 'Importacao assistida de recibo antigo.',
            'source' => Payment::SOURCE_IMPORT,
            'created_by' => $actor->id,
            'map_rule' => 'receipt_import',
            'map_metadata' => [
                'receipt_import_item_id' => $item->id,
                'batch_id' => $item->batch_id,
            ],
            'metadata' => [
                'receipt_import_item_id' => $item->id,
                'batch_id' => $item->batch_id,
            ],
        ]);

        $invoice->refresh();
        if ($invoice->estado_pagamento !== 'pago') {
            throw ValidationException::withMessages([
                'items' => 'A fatura nao ficou totalmente paga apos a importacao do recibo.',
            ]);
        }

        $invoice->forceFill([
            'numero_recibo' => $item->numero_recibo,
            'recibo_emitido_em' => $item->recibo_emitido_em,
            'recibo_pdf_path' => $item->storage_path,
            'receipt_import_item_id' => $item->id,
            'estado_pagamento' => 'pago',
        ])->save();

        $paymentAllocation = $payment->allocations()
            ->where('invoice_id', $invoice->id)
            ->latest('created_at')
            ->first();
        $mapa = $paymentAllocation
            ? $bankStatement->reconciliationMaps()->where('payment_allocation_id', $paymentAllocation->id)->latest('created_at')->first()
            : null;

        BankTransactionAllocation::query()->create([
            'bank_statement_id' => $bankStatement->id,
            'invoice_id' => $invoice->id,
            'user_id' => $item->user_id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $paymentAllocation?->id,
            'receipt_import_item_id' => $item->id,
            'mapa_conciliacao_id' => $mapa?->id,
            'valor_alocado' => $openAmount,
            'status' => 'confirmed',
            'origem' => 'importacao_recibos',
            'metadata' => [
                'numero_recibo' => $item->numero_recibo,
            ],
            'created_by' => $actor->id,
            'committed_by' => $actor->id,
            'committed_at' => now(),
        ]);

        $item->forceFill([
            'status' => ReceiptImportItem::STATUS_IMPORTED,
            'committed_at' => now(),
        ])->save();

        $this->storeAliasFromStatement($bankStatement, $item->user_id, $actor->id);

        Log::info('Receipt import item committed.', [
            'item_id' => $item->id,
            'invoice_id' => $invoice->id,
            'bank_statement_id' => $bankStatement->id,
            'payment_id' => $payment->id,
            'actor_id' => $actor->id,
        ]);
    }

    private function availableBankStatementAmount(BankStatement $bankStatement): float
    {
        if ($bankStatement->valor_por_conciliar !== null) {
            return round(abs((float) $bankStatement->valor_por_conciliar), 2);
        }

        return round(max(abs((float) $bankStatement->valor) - (float) ($bankStatement->valor_conciliado ?? 0), 0), 2);
    }

    private function storeAliasFromStatement(BankStatement $bankStatement, string $userId, string $createdBy): void
    {
        $rawDescription = trim((string) $bankStatement->descricao);
        $extracted = $this->descriptionParser->extractAliasAfterDe($rawDescription);
        $normalizedAlias = $this->descriptionParser->normalizeAlias($extracted);

        if ($rawDescription === '' || $normalizedAlias === '') {
            return;
        }

        $this->aliasService->createAlias([
            'user_id' => $userId,
            'type' => 'payer_name',
            'value' => $extracted,
            'normalized_value' => $extracted,
            'raw_description' => $rawDescription,
            'extracted_after_de' => $extracted,
            'normalized_alias' => $normalizedAlias,
            'source' => 'receipt_import',
            'confidence' => 80,
            'confidence_score' => 80,
            'usage_count' => 1,
            'last_used_at' => now(),
            'last_matched_at' => now(),
            'match_count' => 1,
            'created_by' => $createdBy,
        ]);
    }
}
<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReceiptImportItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class PendingFiscalRequestInspectionService
{
    private const VERSION = 'a6-2-pending-fiscal-request-inspection-v1';
    private const TOLERANCE = 0.01;
    private const STALE_DAYS = 30;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function inspect(array $options = []): array
    {
        $filters = $this->filters($options);
        $requests = $this->pendingRequests($filters);
        $items = $requests
            ->map(fn (FiscalDocumentRequest $request): array => $this->inspectRequest($request))
            ->values()
            ->all();

        if ($filters['only_actionable']) {
            $items = array_values(array_filter($items, static fn (array $item): bool => (bool) data_get($item, 'decision.actionable')));
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'summary' => $this->summary($items),
            'items' => $items,
            'read_only' => true,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function filters(array $options): array
    {
        return [
            'fiscal_request' => $this->stringOrNull($options['fiscal_request'] ?? null),
            'invoice' => $this->stringOrNull($options['invoice'] ?? null),
            'payment' => $this->stringOrNull($options['payment'] ?? null),
            'user' => $this->stringOrNull($options['user'] ?? null),
            'only_actionable' => (bool) ($options['only_actionable'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,FiscalDocumentRequest>
     */
    private function pendingRequests(array $filters): Collection
    {
        if (! Schema::hasTable('fiscal_document_requests')) {
            return collect();
        }

        $query = FiscalDocumentRequest::query()
            ->where('status', FiscalDocumentRequest::STATUS_PENDING)
            ->whereNull('external_document_number')
            ->whereNull('external_document_id')
            ->whereNull('issued_at')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['fiscal_request']) {
            $query->whereKey($filters['fiscal_request']);
        }

        if ($filters['invoice']) {
            $query->where('invoice_id', $filters['invoice']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['payment']) {
            $invoiceIds = PaymentAllocation::withTrashed()
                ->where('payment_id', $filters['payment'])
                ->pluck('invoice_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($invoiceIds === []) {
                return collect();
            }

            $query->whereIn('invoice_id', $invoiceIds);
        }

        return $query->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectRequest(FiscalDocumentRequest $request): array
    {
        $invoice = $request->invoice_id ? Invoice::query()->find($request->invoice_id) : null;
        $allocations = $this->allocations($request, $invoice);
        $payments = $this->payments($request, $allocations);
        $receiptItems = $this->receiptImportItems($request, $invoice);
        $reconciliations = $this->reconciliations($request, $invoice, $payments, $allocations);
        $bankAllocations = $this->bankAllocations($request, $invoice, $payments, $allocations);
        $bankStatements = $this->bankStatements($request, $payments, $reconciliations, $bankAllocations);
        $amountAnalysis = $this->amountAnalysis($request, $invoice, $allocations, $payments);
        $reasons = $this->reasons($request, $invoice, $allocations, $payments, $receiptItems, $reconciliations, $amountAnalysis);
        $decision = $this->decision($request, $invoice, $allocations, $payments, $receiptItems, $reconciliations, $amountAnalysis, $reasons);

        return [
            'fiscal_request' => $this->fiscalRequestSnapshot($request),
            'invoice' => $this->invoiceSnapshot($invoice),
            'payment' => $payments->map(fn (Payment $payment): array => $this->paymentSnapshot($payment))->values()->all(),
            'allocation' => $allocations->map(fn (PaymentAllocation $allocation): array => $this->allocationSnapshot($allocation))->values()->all(),
            'external_receipt_import' => $receiptItems->map(fn (ReceiptImportItem $item): array => $this->receiptImportItemSnapshot($item))->values()->all(),
            'reconciliation_financial_trace' => [
                'mapa_conciliacao' => $reconciliations->map(fn (MapaConciliacao $map): array => $this->reconciliationSnapshot($map))->values()->all(),
                'bank_transaction_allocations' => $bankAllocations->map(fn (BankTransactionAllocation $allocation): array => $this->bankAllocationSnapshot($allocation))->values()->all(),
                'bank_statements' => $bankStatements->map(fn (BankStatement $statement): array => $this->bankStatementSnapshot($statement))->values()->all(),
            ],
            'timeline' => $this->timeline($request, $invoice, $payments, $allocations, $reconciliations, $bankStatements),
            'amount_analysis' => $amountAnalysis,
            'decision' => $decision,
            'reasons' => $reasons,
            'read_only' => true,
        ];
    }

    /**
     * @return Collection<int,PaymentAllocation>
     */
    private function allocations(FiscalDocumentRequest $request, ?Invoice $invoice): Collection
    {
        if (! $invoice instanceof Invoice || ! Schema::hasTable('payment_allocations')) {
            return collect();
        }

        return PaymentAllocation::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @return Collection<int,Payment>
     */
    private function payments(FiscalDocumentRequest $request, Collection $allocations): Collection
    {
        if (! Schema::hasTable('payments')) {
            return collect();
        }

        $ids = $allocations->pluck('payment_id')->filter()->unique()->values();
        if ($request->bank_statement_id) {
            Payment::withTrashed()
                ->where('bank_statement_id', $request->bank_statement_id)
                ->pluck('id')
                ->each(fn (mixed $id) => $ids->push($id));
        }

        $ids = $ids->filter()->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return Payment::withTrashed()
            ->whereIn('id', $ids)
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int,ReceiptImportItem>
     */
    private function receiptImportItems(FiscalDocumentRequest $request, ?Invoice $invoice): Collection
    {
        if (! Schema::hasTable('receipt_import_items')) {
            return collect();
        }

        $query = ReceiptImportItem::query()
            ->where(function (Builder $query) use ($request, $invoice): void {
                $query->when($request->invoice_id, fn (Builder $q) => $q->orWhere('invoice_id', $request->invoice_id))
                    ->when($request->bank_statement_id, fn (Builder $q) => $q->orWhere('bank_statement_id', $request->bank_statement_id))
                    ->when($invoice?->receipt_import_item_id, fn (Builder $q) => $q->orWhereKey($invoice->receipt_import_item_id));
            })
            ->orderBy('created_at')
            ->orderBy('id');

        return $query->get();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @return Collection<int,MapaConciliacao>
     */
    private function reconciliations(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $payments, Collection $allocations): Collection
    {
        if (! Schema::hasTable('mapa_conciliacao')) {
            return collect();
        }

        return MapaConciliacao::query()
            ->where(function (Builder $query) use ($request, $invoice, $payments, $allocations): void {
                $query->when($request->mapa_conciliacao_id, fn (Builder $q) => $q->orWhereKey($request->mapa_conciliacao_id))
                    ->when($request->bank_statement_id, fn (Builder $q) => $q->orWhere('extrato_id', $request->bank_statement_id))
                    ->when($invoice?->id, fn (Builder $q) => $q->orWhere('fatura_id', $invoice->id))
                    ->when($payments->isNotEmpty(), fn (Builder $q) => $q->orWhereIn('payment_id', $payments->pluck('id')->all()))
                    ->when($allocations->isNotEmpty(), fn (Builder $q) => $q->orWhereIn('payment_allocation_id', $allocations->pluck('id')->all()));
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @return Collection<int,BankTransactionAllocation>
     */
    private function bankAllocations(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $payments, Collection $allocations): Collection
    {
        if (! Schema::hasTable('bank_transaction_allocations')) {
            return collect();
        }

        return BankTransactionAllocation::query()
            ->where(function (Builder $query) use ($request, $invoice, $payments, $allocations): void {
                $query->when($request->bank_statement_id, fn (Builder $q) => $q->orWhere('bank_statement_id', $request->bank_statement_id))
                    ->when($invoice?->id, fn (Builder $q) => $q->orWhere('invoice_id', $invoice->id))
                    ->when($payments->isNotEmpty(), fn (Builder $q) => $q->orWhereIn('payment_id', $payments->pluck('id')->all()))
                    ->when($allocations->isNotEmpty(), fn (Builder $q) => $q->orWhereIn('payment_allocation_id', $allocations->pluck('id')->all()));
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankTransactionAllocation> $bankAllocations
     * @return Collection<int,BankStatement>
     */
    private function bankStatements(FiscalDocumentRequest $request, Collection $payments, Collection $reconciliations, Collection $bankAllocations): Collection
    {
        if (! Schema::hasTable('bank_statements')) {
            return collect();
        }

        $ids = collect([$request->bank_statement_id])
            ->merge($payments->pluck('bank_statement_id'))
            ->merge($reconciliations->pluck('extrato_id'))
            ->merge($bankAllocations->pluck('bank_statement_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return collect();
        }

        return BankStatement::query()
            ->whereIn('id', $ids)
            ->orderBy('data_movimento')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     * @return array<string,mixed>
     */
    private function amountAnalysis(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $allocations, Collection $payments): array
    {
        $requestAmount = $this->money($request->amount);
        $invoiceAmount = $invoice instanceof Invoice ? $this->money($invoice->valor_total) : null;
        $confirmedAllocationAmount = $allocations
            ->filter(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation))
            ->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount);
        $allocationAmount = $confirmedAllocationAmount > 0 ? $this->money($confirmedAllocationAmount) : null;
        $paymentAmount = $payments
            ->filter(fn (Payment $payment): bool => $this->activePayment($payment))
            ->sum(fn (Payment $payment): float => (float) $payment->amount);
        $paymentTotalAmount = $paymentAmount > 0 ? $this->money($paymentAmount) : null;

        $basis = 'unknown';
        $matches = false;

        if ($invoiceAmount !== null && abs($requestAmount - $invoiceAmount) <= self::TOLERANCE) {
            $basis = 'invoice';
            $matches = true;
        } elseif ($allocationAmount !== null && abs($requestAmount - $allocationAmount) <= self::TOLERANCE) {
            $basis = 'allocation';
            $matches = true;
        } elseif ($paymentTotalAmount !== null && abs($requestAmount - $paymentTotalAmount) <= self::TOLERANCE) {
            $basis = 'payment';
            $matches = true;
        } elseif ($allocationAmount !== null) {
            $basis = 'allocation';
        } elseif ($invoiceAmount !== null) {
            $basis = 'invoice';
        } elseif ($paymentTotalAmount !== null) {
            $basis = 'payment';
        }

        return [
            'amount_basis_used' => $basis,
            'amount_matches_invoice_or_allocation' => $matches && in_array($basis, ['invoice', 'allocation'], true),
            'amount_matches_basis' => $matches,
            'amount_mismatch' => $basis !== 'unknown' && ! $matches,
            'fiscal_request_amount' => $requestAmount,
            'invoice_amount' => $invoiceAmount,
            'allocation_amount' => $allocationAmount,
            'payment_total_amount' => $paymentTotalAmount,
        ];
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     * @param Collection<int,ReceiptImportItem> $receiptItems
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param array<string,mixed> $amountAnalysis
     * @return list<string>
     */
    private function reasons(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $allocations, Collection $payments, Collection $receiptItems, Collection $reconciliations, array $amountAnalysis): array
    {
        $reasons = ['no_external_document'];

        if ($invoice instanceof Invoice && (string) $invoice->estado_pagamento === 'pago') {
            $reasons[] = 'invoice_paid';
        }

        if ($payments->contains(fn (Payment $payment): bool => $this->activePayment($payment))) {
            $reasons[] = 'payment_confirmed';
        }

        if ($allocations->contains(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation))) {
            $reasons[] = 'allocation_confirmed';
        }

        if ((bool) $amountAnalysis['amount_matches_invoice_or_allocation']) {
            $reasons[] = 'amount_matches_invoice_or_allocation';
        }

        if ($receiptItems->isEmpty() && ! $this->invoiceHasReceipt($invoice)) {
            $reasons[] = 'no_receipt_import_item';
        }

        if ($this->isOldPending($request)) {
            $reasons[] = 'old_pending';
        }

        if ($this->legacyOrTestSignal($request, $invoice)) {
            $reasons[] = 'legacy_or_test_data_signal';
        }

        if ($this->protectedInvoiceSignal($invoice, $allocations, $payments, $reconciliations)) {
            $reasons[] = 'protected_invoice_signal';
        }

        if ($this->providerQueueSignal($request)) {
            $reasons[] = 'provider_queue_missing_response';
        }

        if ($this->providerUnknown($request)) {
            $reasons[] = 'provider_unknown';
        }

        if ($this->fiscalObligationLikelyReal($invoice, $allocations, $payments, $amountAnalysis)) {
            $reasons[] = 'fiscal_obligation_likely_real';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     * @param Collection<int,ReceiptImportItem> $receiptItems
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param array<string,mixed> $amountAnalysis
     * @param list<string> $reasons
     * @return array<string,mixed>
     */
    private function decision(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $allocations, Collection $payments, Collection $receiptItems, Collection $reconciliations, array $amountAnalysis, array $reasons): array
    {
        $hasCompletePaidChain = $this->fiscalObligationLikelyReal($invoice, $allocations, $payments, $amountAnalysis);
        $hasImportedReceipt = $this->invoiceHasReceipt($invoice) || $receiptItems->isNotEmpty();

        if ($this->providerQueueSignal($request)) {
            return [
                'risk_level' => 'medium',
                'should_process_external_issue' => false,
                'can_archive_without_external_issue' => false,
                'recommended_next_action' => 'keep_pending_waiting_provider',
                'actionable' => false,
            ];
        }

        if ($this->legacyOrTestSignal($request, $invoice) && ! $hasCompletePaidChain && ! $hasImportedReceipt) {
            return [
                'risk_level' => 'low',
                'should_process_external_issue' => false,
                'can_archive_without_external_issue' => true,
                'recommended_next_action' => 'archive_historical_pending_without_external_document',
                'actionable' => false,
            ];
        }

        if (! $invoice instanceof Invoice || $payments->isEmpty() || $allocations->isEmpty() || (bool) $amountAnalysis['amount_mismatch'] || $this->providerUnknown($request)) {
            return [
                'risk_level' => 'high',
                'should_process_external_issue' => false,
                'can_archive_without_external_issue' => false,
                'recommended_next_action' => 'manual_review_required',
                'actionable' => true,
            ];
        }

        if ($hasCompletePaidChain && ! $hasImportedReceipt) {
            return [
                'risk_level' => 'medium',
                'should_process_external_issue' => true,
                'can_archive_without_external_issue' => false,
                'recommended_next_action' => 'process_external_fiscal_document',
                'actionable' => true,
            ];
        }

        return [
            'risk_level' => 'medium',
            'should_process_external_issue' => false,
            'can_archive_without_external_issue' => false,
            'recommended_next_action' => 'manual_review_required',
            'actionable' => true,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function summary(array $items): array
    {
        return [
            'total_pending_scanned' => count($items),
            'process_external_issue_count' => $this->countDecision($items, 'process_external_fiscal_document'),
            'archive_historical_count' => $this->countDecision($items, 'archive_historical_pending_without_external_document'),
            'keep_pending_count' => $this->countDecision($items, 'keep_pending_waiting_provider'),
            'manual_review_count' => $this->countDecision($items, 'manual_review_required'),
            'low_risk_count' => $this->countRisk($items, 'low'),
            'medium_risk_count' => $this->countRisk($items, 'medium'),
            'high_risk_count' => $this->countRisk($items, 'high'),
            'actionable_count' => count(array_filter($items, static fn (array $item): bool => (bool) data_get($item, 'decision.actionable'))),
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function countDecision(array $items, string $decision): int
    {
        return count(array_filter($items, static fn (array $item): bool => data_get($item, 'decision.recommended_next_action') === $decision));
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function countRisk(array $items, string $risk): int
    {
        return count(array_filter($items, static fn (array $item): bool => data_get($item, 'decision.risk_level') === $risk));
    }

    private function fiscalRequestSnapshot(FiscalDocumentRequest $request): array
    {
        return [
            'id' => (string) $request->id,
            'invoice_id' => $request->invoice_id ? (string) $request->invoice_id : null,
            'user_id' => $request->user_id ? (string) $request->user_id : null,
            'provider' => $request->provider,
            'document_type' => $request->document_type,
            'status' => $request->status,
            'amount' => $this->money($request->amount),
            'paid_at' => $this->dateString($request->paid_at),
            'due_at' => $this->dateString($request->due_at),
            'issued_at' => $this->dateTimeString($request->issued_at),
            'handled_at' => $this->dateTimeString($request->handled_at),
            'external_document_number' => $request->external_document_number,
            'external_document_id' => $request->external_document_id,
            'metadata' => $request->metadata,
            'created_at' => $this->dateTimeString($request->created_at),
            'updated_at' => $this->dateTimeString($request->updated_at),
            'deleted_at' => $this->dateTimeString($request->deleted_at),
        ];
    }

    private function invoiceSnapshot(?Invoice $invoice): ?array
    {
        if (! $invoice instanceof Invoice) {
            return null;
        }

        return [
            'id' => (string) $invoice->id,
            'estado_pagamento' => $invoice->estado_pagamento,
            'valor_total' => $this->money($invoice->valor_total),
            'valor_pago' => $this->money($invoice->valor_pago),
            'valor_em_aberto' => $this->money($invoice->valor_em_aberto),
            'data_emissao' => $this->dateString($invoice->data_emissao),
            'data_pagamento' => $this->dateString($invoice->data_pagamento),
            'numero_recibo' => $invoice->numero_recibo,
            'recibo_emitido_em' => $this->dateString($invoice->recibo_emitido_em),
            'receipt_import_item_id' => $invoice->receipt_import_item_id ? (string) $invoice->receipt_import_item_id : null,
            'recibo_pdf_path' => $invoice->recibo_pdf_path,
            'origem_tipo' => $invoice->origem_tipo,
            'origem_id' => $invoice->origem_id ? (string) $invoice->origem_id : null,
            'tipo' => $invoice->tipo,
            'is_monthly' => in_array((string) $invoice->tipo, ['mensalidade', 'monthly_fee'], true)
                || in_array((string) $invoice->origem_tipo, ['monthly_fee', 'monthly_fee_legacy'], true),
            'created_at' => $this->dateTimeString($invoice->created_at),
            'updated_at' => $this->dateTimeString($invoice->updated_at),
        ];
    }

    private function paymentSnapshot(Payment $payment): array
    {
        return [
            'id' => (string) $payment->id,
            'amount' => $this->money($payment->amount),
            'allocated_amount' => $this->money($payment->allocated_amount),
            'unallocated_amount' => $this->money($payment->unallocated_amount),
            'payment_date' => $this->dateString($payment->payment_date),
            'source' => $payment->source,
            'status' => $payment->status,
            'bank_statement_id' => $payment->bank_statement_id ? (string) $payment->bank_statement_id : null,
            'cancelled_at' => $this->dateTimeString($payment->cancelled_at),
            'deleted_at' => $this->dateTimeString($payment->deleted_at),
        ];
    }

    private function allocationSnapshot(PaymentAllocation $allocation): array
    {
        return [
            'id' => (string) $allocation->id,
            'payment_id' => $allocation->payment_id ? (string) $allocation->payment_id : null,
            'invoice_id' => $allocation->invoice_id ? (string) $allocation->invoice_id : null,
            'amount' => $this->money($allocation->amount),
            'status' => $allocation->status,
            'allocated_at' => $this->dateTimeString($allocation->allocated_at),
            'deleted_at' => $this->dateTimeString($allocation->deleted_at),
        ];
    }

    private function receiptImportItemSnapshot(ReceiptImportItem $item): array
    {
        return [
            'id' => (string) $item->id,
            'invoice_id' => $item->invoice_id ? (string) $item->invoice_id : null,
            'user_id' => $item->user_id ? (string) $item->user_id : null,
            'bank_statement_id' => $item->bank_statement_id ? (string) $item->bank_statement_id : null,
            'status' => $item->status,
            'numero_recibo' => $item->numero_recibo,
            'recibo_emitido_em' => $this->dateString($item->recibo_emitido_em),
            'valor' => $this->money($item->valor),
            'file_name' => $item->file_name,
            'storage_path' => $item->storage_path,
            'committed_at' => $this->dateTimeString($item->committed_at),
        ];
    }

    private function reconciliationSnapshot(MapaConciliacao $map): array
    {
        return [
            'id' => (string) $map->id,
            'extrato_id' => $map->extrato_id ? (string) $map->extrato_id : null,
            'fatura_id' => $map->fatura_id ? (string) $map->fatura_id : null,
            'payment_id' => $map->payment_id ? (string) $map->payment_id : null,
            'payment_allocation_id' => $map->payment_allocation_id ? (string) $map->payment_allocation_id : null,
            'valor_conciliado' => $this->money($map->valor_conciliado),
            'status' => $map->status,
            'created_at' => $this->dateTimeString($map->created_at),
            'updated_at' => $this->dateTimeString($map->updated_at),
        ];
    }

    private function bankAllocationSnapshot(BankTransactionAllocation $allocation): array
    {
        return [
            'id' => (string) $allocation->id,
            'bank_statement_id' => $allocation->bank_statement_id ? (string) $allocation->bank_statement_id : null,
            'invoice_id' => $allocation->invoice_id ? (string) $allocation->invoice_id : null,
            'payment_id' => $allocation->payment_id ? (string) $allocation->payment_id : null,
            'payment_allocation_id' => $allocation->payment_allocation_id ? (string) $allocation->payment_allocation_id : null,
            'receipt_import_item_id' => $allocation->receipt_import_item_id ? (string) $allocation->receipt_import_item_id : null,
            'mapa_conciliacao_id' => $allocation->mapa_conciliacao_id ? (string) $allocation->mapa_conciliacao_id : null,
            'valor_alocado' => $this->money($allocation->valor_alocado),
            'status' => $allocation->status,
            'origem' => $allocation->origem,
            'committed_at' => $this->dateTimeString($allocation->committed_at),
        ];
    }

    private function bankStatementSnapshot(BankStatement $statement): array
    {
        return [
            'id' => (string) $statement->id,
            'data_movimento' => $this->dateString($statement->data_movimento),
            'amount' => $this->money($statement->valor),
            'status' => $statement->conciliacao_status,
            'conciliado' => (bool) $statement->conciliado,
            'valor_conciliado' => $this->money($statement->valor_conciliado),
            'valor_por_conciliar' => $this->money($statement->valor_por_conciliar),
            'descricao' => $statement->descricao,
            'referencia' => $statement->referencia,
        ];
    }

    /**
     * @param Collection<int,Payment> $payments
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,MapaConciliacao> $reconciliations
     * @param Collection<int,BankStatement> $bankStatements
     * @return array<string,mixed>
     */
    private function timeline(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $payments, Collection $allocations, Collection $reconciliations, Collection $bankStatements): array
    {
        return [
            'fiscal_request_created_at' => $this->dateTimeString($request->created_at),
            'invoice_data_emissao' => $this->dateString($invoice?->data_emissao),
            'invoice_data_pagamento' => $this->dateString($invoice?->data_pagamento),
            'payment_dates' => $payments->map(fn (Payment $payment): ?string => $this->dateString($payment->payment_date))->filter()->values()->all(),
            'allocation_dates' => $allocations->map(fn (PaymentAllocation $allocation): ?string => $this->dateTimeString($allocation->allocated_at))->filter()->values()->all(),
            'bank_dates' => $bankStatements->map(fn (BankStatement $statement): ?string => $this->dateString($statement->data_movimento))->filter()->values()->all(),
            'reconciliation_dates' => $reconciliations->map(fn (MapaConciliacao $map): ?string => $this->dateTimeString($map->created_at))->filter()->values()->all(),
            'age_days' => $request->created_at instanceof Carbon ? $request->created_at->diffInDays(Carbon::now()) : null,
        ];
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     * @param array<string,mixed> $amountAnalysis
     */
    private function fiscalObligationLikelyReal(?Invoice $invoice, Collection $allocations, Collection $payments, array $amountAnalysis): bool
    {
        if (! $invoice instanceof Invoice) {
            return false;
        }

        return (string) $invoice->estado_pagamento === 'pago'
            && $this->money($invoice->valor_pago) >= $this->money($invoice->valor_total)
            && abs($this->money($invoice->valor_em_aberto)) <= self::TOLERANCE
            && $allocations->contains(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation))
            && $payments->contains(fn (Payment $payment): bool => $this->activePayment($payment))
            && (bool) $amountAnalysis['amount_matches_invoice_or_allocation'];
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @param Collection<int,Payment> $payments
     * @param Collection<int,MapaConciliacao> $reconciliations
     */
    private function protectedInvoiceSignal(?Invoice $invoice, Collection $allocations, Collection $payments, Collection $reconciliations): bool
    {
        return $invoice instanceof Invoice
            && ((string) $invoice->estado_pagamento === 'pago'
                || $allocations->contains(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation))
                || $payments->contains(fn (Payment $payment): bool => $this->activePayment($payment))
                || $reconciliations->isNotEmpty());
    }

    private function activeAllocation(PaymentAllocation $allocation): bool
    {
        return (string) $allocation->status === PaymentAllocation::STATUS_CONFIRMED && $allocation->deleted_at === null;
    }

    private function activePayment(Payment $payment): bool
    {
        return (string) $payment->status === Payment::STATUS_CONFIRMED && $payment->deleted_at === null;
    }

    private function invoiceHasReceipt(?Invoice $invoice): bool
    {
        return $invoice instanceof Invoice
            && (filled($invoice->numero_recibo)
                || filled($invoice->recibo_emitido_em)
                || filled($invoice->recibo_pdf_path)
                || filled($invoice->receipt_import_item_id));
    }

    private function isOldPending(FiscalDocumentRequest $request): bool
    {
        return $request->created_at instanceof Carbon
            && $request->created_at->lt(Carbon::now()->subDays(self::STALE_DAYS)->startOfDay());
    }

    private function legacyOrTestSignal(FiscalDocumentRequest $request, ?Invoice $invoice): bool
    {
        $metadata = $request->metadata ?? [];
        $text = mb_strtolower(json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $text .= ' ' . mb_strtolower(implode(' ', array_filter([
            (string) $request->description,
            (string) $request->internal_reference,
            (string) $request->notes,
            (string) $invoice?->observacoes,
            (string) $invoice?->origem_tipo,
        ])));

        foreach (['test', 'teste', 'legacy_only', 'historico', 'histórico', 'migration', 'migracao', 'migração', 'seed', 'demo', 'stale_cleanup'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function providerQueueSignal(FiscalDocumentRequest $request): bool
    {
        $text = mb_strtolower(json_encode($request->metadata ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        $text .= ' ' . mb_strtolower(implode(' ', array_filter([
            (string) $request->last_error,
            (string) $request->notes,
        ])));

        foreach (['provider_waiting', 'waiting_provider', 'provider_pending', 'queue', 'queued', 'fila', 'api_pending', 'retry', 'awaiting_response', 'sem resposta'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function providerUnknown(FiscalDocumentRequest $request): bool
    {
        return blank($request->provider) || ! in_array((string) $request->provider, [FiscalDocumentRequest::PROVIDER_WINTOUCH], true);
    }

    private function dateString(mixed $value): ?string
    {
        return $this->dateValue($value)?->toDateString();
    }

    private function dateTimeString(mixed $value): ?string
    {
        return $this->dateValue($value)?->toDateTimeString();
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

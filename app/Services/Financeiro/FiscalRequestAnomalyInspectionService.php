<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class FiscalRequestAnomalyInspectionService
{
    private const VERSION = 'a3-5-fiscal-request-anomaly-inspection-v1';
    private const TOLERANCE = 0.01;

    /**
     * @return array<string,mixed>|null
     */
    public function inspect(string $invoiceId): ?array
    {
        $invoice = Invoice::query()
            ->with(['items', 'user'])
            ->whereKey($invoiceId)
            ->first();

        if (! $invoice) {
            return null;
        }

        $allocations = PaymentAllocation::withTrashed()
            ->with(['payment' => fn ($query) => $query->withTrashed()])
            ->where('invoice_id', $invoice->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $payments = Payment::withTrashed()
            ->whereIn('id', $allocations->pluck('payment_id')->filter()->unique()->all())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $fiscalRequests = FiscalDocumentRequest::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $reconciliations = MapaConciliacao::query()
            ->where('fatura_id', $invoice->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $bankAllocations = BankTransactionAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $financialEntries = $this->financialEntries($invoice, $allocations, $payments, $fiscalRequests);

        $anomalies = $this->detectAnomalies($invoice, $allocations, $payments, $fiscalRequests, $reconciliations, $bankAllocations);
        $riskLevel = $this->riskLevel($invoice, $allocations, $fiscalRequests, $reconciliations, $bankAllocations, $anomalies);
        $futureActionCandidate = $this->futureActionCandidate($fiscalRequests, $allocations, $payments, $reconciliations, $bankAllocations);
        $reversalContext = $this->reversalContext($invoice, $allocations, $fiscalRequests, $reconciliations, $bankAllocations, $financialEntries);

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'invoice_id' => (string) $invoice->id,
            'invoice_snapshot' => $this->invoiceSnapshot($invoice),
            'item_summary' => $this->itemSummary($invoice),
            'payment_snapshot' => $payments->map(fn (Payment $payment): array => $this->paymentSnapshot($payment))->values()->all(),
            'payment_allocation_snapshot' => $allocations->map(fn (PaymentAllocation $allocation): array => $this->paymentAllocationSnapshot($allocation))->values()->all(),
            'fiscal_request_snapshot' => $fiscalRequests->map(fn (FiscalDocumentRequest $request): array => $this->fiscalRequestSnapshot($request))->values()->all(),
            'bank_reconciliation_snapshot' => [
                'mapa_conciliacao' => $reconciliations->map(fn (MapaConciliacao $map): array => $this->reconciliationSnapshot($map))->values()->all(),
                'bank_transaction_allocations' => $bankAllocations->map(fn (BankTransactionAllocation $allocation): array => $this->bankAllocationSnapshot($allocation))->values()->all(),
            ],
            'financial_entry_snapshot' => $financialEntries->map(fn (FinancialEntry $entry): array => $this->financialEntrySnapshot($entry))->values()->all(),
            'detected_anomalies' => $anomalies,
            'reversal_context' => $reversalContext,
            'risk_level' => $riskLevel,
            'can_auto_fix' => false,
            'can_archive_stale_request' => (bool) $reversalContext['can_archive_stale_request'],
            'future_action_candidate' => $futureActionCandidate,
            'recommended_next_action' => $this->recommendedNextAction($riskLevel, $futureActionCandidate, $anomalies),
        ];
    }

    private function financialEntries(Invoice $invoice, $allocations, $payments, $fiscalRequests)
    {
        $allocationIds = $allocations->pluck('id')->filter()->unique()->all();
        $paymentIds = $payments->pluck('id')->filter()->unique()->all();
        $fiscalRequestIds = $fiscalRequests->pluck('id')->filter()->unique()->all();

        return FinancialEntry::query()
            ->where(function (Builder $query) use ($invoice, $allocationIds, $paymentIds, $fiscalRequestIds): void {
                $query->where('fatura_id', $invoice->id);

                if ($allocationIds !== []) {
                    $query->orWhere(function (Builder $query) use ($allocationIds): void {
                        $query->where('origem_tipo', 'payment_allocation')
                            ->whereIn('origem_id', $allocationIds);
                    });
                }

                if ($paymentIds !== []) {
                    $query->orWhereIn('payment_id', $paymentIds);
                }

                if ($fiscalRequestIds !== []) {
                    $query->orWhereIn('fiscal_document_request_id', $fiscalRequestIds);
                }
            })
            ->orderBy('data')
            ->orderBy('id')
            ->get();
    }

    private function detectAnomalies(Invoice $invoice, $allocations, $payments, $fiscalRequests, $reconciliations, $bankAllocations): array
    {
        $anomalies = [];
        $hasPendingFiscalRequest = $fiscalRequests->contains(
            fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_PENDING,
        );
        $hasSoftDeletedPendingFiscalRequest = $fiscalRequests->contains(
            fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_PENDING && $request->trashed(),
        );
        $confirmedAllocationCount = $allocations->where('status', PaymentAllocation::STATUS_CONFIRMED)->count();

        if ($hasPendingFiscalRequest && $invoice->estado_pagamento !== 'pago') {
            $anomalies[] = 'pending_fiscal_request_for_unpaid_invoice';
        }

        if ($hasSoftDeletedPendingFiscalRequest) {
            $anomalies[] = 'soft_deleted_pending_fiscal_request_still_affecting_invoice_audit';
        }

        if ($allocations->isNotEmpty() && $confirmedAllocationCount === 0) {
            $anomalies[] = 'unconfirmed_payment_allocation_present';
        }

        if ($payments->isNotEmpty() && $confirmedAllocationCount === 0) {
            $anomalies[] = 'payment_record_present_without_confirmed_allocation';
        }

        if ($fiscalRequests->isNotEmpty() || $payments->isNotEmpty() || $allocations->isNotEmpty() || $reconciliations->isNotEmpty() || $bankAllocations->isNotEmpty()) {
            $anomalies[] = 'invoice_protected_from_automatic_change';
        }

        return array_values(array_unique($anomalies));
    }

    private function riskLevel(Invoice $invoice, $allocations, $fiscalRequests, $reconciliations, $bankAllocations, array $anomalies): string
    {
        $hasExternalDocument = $fiscalRequests->contains(
            fn (FiscalDocumentRequest $request): bool => filled($request->external_document_number) || filled($request->external_document_id),
        );
        $hasConfirmedAllocation = $allocations->contains(
            fn (PaymentAllocation $allocation): bool => $allocation->status === PaymentAllocation::STATUS_CONFIRMED,
        );

        if ($hasExternalDocument || $hasConfirmedAllocation || $reconciliations->isNotEmpty() || $bankAllocations->isNotEmpty()) {
            return 'high';
        }

        if ($anomalies !== []) {
            return 'medium';
        }

        return 'low';
    }

    private function futureActionCandidate($fiscalRequests, $allocations, $payments, $reconciliations, $bankAllocations): ?string
    {
        $onlySoftDeletedPendingWithoutExternalDocument = $fiscalRequests->isNotEmpty()
            && $fiscalRequests->every(
                fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_PENDING
                    && $request->trashed()
                    && blank($request->external_document_number)
                    && blank($request->external_document_id),
            );
        $hasNoConfirmedAllocations = ! $allocations->contains(
            fn (PaymentAllocation $allocation): bool => $allocation->status === PaymentAllocation::STATUS_CONFIRMED,
        );

        if ($onlySoftDeletedPendingWithoutExternalDocument
            && $hasNoConfirmedAllocations
            && $reconciliations->isEmpty()
            && $bankAllocations->isEmpty()
            && ($payments->isEmpty() || $allocations->isNotEmpty())) {
            return 'detach_or_archive_stale_pending_fiscal_request';
        }

        return null;
    }

    private function reversalContext(Invoice $invoice, $allocations, $fiscalRequests, $reconciliations, $bankAllocations, $financialEntries): array
    {
        $pendingFiscalRequests = $fiscalRequests->filter(
            fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_PENDING,
        );
        $hasExternalDocument = $fiscalRequests->contains(
            fn (FiscalDocumentRequest $request): bool => filled($request->external_document_number)
                || filled($request->external_document_id)
                || $request->issued_at !== null,
        );
        $hasInvoiceReceipt = filled($invoice->numero_recibo)
            || filled($invoice->recibo_emitido_em)
            || filled($invoice->recibo_pdf_path)
            || filled($invoice->receipt_import_item_id);
        $softDeletedCancelledAllocations = $allocations->filter(
            fn (PaymentAllocation $allocation): bool => $allocation->status === PaymentAllocation::STATUS_CANCELLED
                && $allocation->deleted_at !== null,
        );
        $activeConfirmedAllocations = $allocations->filter(
            fn (PaymentAllocation $allocation): bool => $allocation->status === PaymentAllocation::STATUS_CONFIRMED
                && $allocation->deleted_at === null,
        );
        $activeReconciliations = $reconciliations->filter(
            fn (MapaConciliacao $map): bool => ! in_array((string) $map->status, ['cancelled', 'cancelado', 'reversed', 'anulado'], true),
        );
        $activeBankAllocations = $bankAllocations->filter(
            fn (BankTransactionAllocation $allocation): bool => $allocation->status === BankTransactionAllocation::STATUS_CONFIRMED,
        );
        $activeFinancialEntries = $financialEntries->filter(
            fn (FinancialEntry $entry): bool => in_array((string) $entry->estado, ['pago', 'parcial'], true)
                || (float) $entry->valor_pago > self::TOLERANCE
                || filled($entry->payment_id),
        );
        $hasIssuedFiscalRequest = $fiscalRequests->contains(
            fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_ISSUED,
        );

        return [
            'pending_fiscal_request_count' => $pendingFiscalRequests->count(),
            'has_pending_fiscal_request' => $pendingFiscalRequests->isNotEmpty(),
            'has_soft_deleted_pending_fiscal_request' => $pendingFiscalRequests->contains(fn (FiscalDocumentRequest $request): bool => $request->trashed()),
            'has_external_document' => $hasExternalDocument,
            'has_invoice_receipt' => $hasInvoiceReceipt,
            'soft_deleted_cancelled_allocation_count' => $softDeletedCancelledAllocations->count(),
            'has_soft_deleted_cancelled_allocation' => $softDeletedCancelledAllocations->isNotEmpty(),
            'active_confirmed_allocation_count' => $activeConfirmedAllocations->count(),
            'has_active_confirmed_allocation' => $activeConfirmedAllocations->isNotEmpty(),
            'has_active_reconciliation' => $activeReconciliations->isNotEmpty(),
            'has_active_bank_allocation' => $activeBankAllocations->isNotEmpty(),
            'has_active_financial_entry' => $activeFinancialEntries->isNotEmpty(),
            'has_issued_fiscal_request' => $hasIssuedFiscalRequest,
            'can_archive_stale_request' => $pendingFiscalRequests->isNotEmpty()
                && $softDeletedCancelledAllocations->isNotEmpty()
                && ! $hasExternalDocument
                && ! $hasInvoiceReceipt
                && ! $hasIssuedFiscalRequest
                && $activeConfirmedAllocations->isEmpty()
                && $activeReconciliations->isEmpty()
                && $activeBankAllocations->isEmpty()
                && $activeFinancialEntries->isEmpty(),
        ];
    }

    private function recommendedNextAction(string $riskLevel, ?string $futureActionCandidate, array $anomalies): string
    {
        if ($riskLevel === 'high') {
            return 'do_not_modify_external_fiscal_document_without_manual_review';
        }

        if ($futureActionCandidate !== null) {
            return 'review_stale_pending_fiscal_request_manually';
        }

        if ($riskLevel === 'medium') {
            return 'review_fiscal_request_anomaly_manually';
        }

        return 'no_action_needed';
    }

    private function invoiceSnapshot(Invoice $invoice): array
    {
        return [
            'id' => (string) $invoice->id,
            'user_id' => $invoice->user_id ? (string) $invoice->user_id : null,
            'user_name' => $invoice->user?->name,
            'tipo' => (string) $invoice->tipo,
            'mes' => $invoice->mes ? (string) $invoice->mes : null,
            'estado_pagamento' => (string) $invoice->estado_pagamento,
            'valor_total' => $this->money($invoice->valor_total),
            'valor_pago' => $this->money($invoice->valor_pago),
            'valor_em_aberto' => $this->money($invoice->valor_em_aberto),
            'data_emissao' => $invoice->data_emissao?->toDateString(),
            'data_vencimento' => $invoice->data_vencimento?->toDateString(),
            'data_pagamento' => $invoice->data_pagamento?->toDateString(),
            'numero_recibo' => $invoice->numero_recibo,
            'recibo_emitido_em' => $invoice->recibo_emitido_em?->toDateString(),
            'recibo_pdf_path' => $invoice->recibo_pdf_path,
            'receipt_import_item_id' => $invoice->receipt_import_item_id,
            'origem_tipo' => $invoice->origem_tipo,
            'origem_id' => $invoice->origem_id,
            'updated_at' => $invoice->updated_at?->toIso8601String(),
        ];
    }

    private function itemSummary(Invoice $invoice): array
    {
        $items = $invoice->items;

        return [
            'count' => $items->count(),
            'total_linha_sum' => $this->money($items->sum(fn ($item): float => (float) $item->total_linha)),
            'items' => $items
                ->map(fn ($item): array => [
                    'id' => (string) $item->id,
                    'descricao' => (string) $item->descricao,
                    'quantidade' => $this->money($item->quantidade),
                    'valor_unitario' => $this->money($item->valor_unitario),
                    'imposto_percentual' => $this->money($item->imposto_percentual),
                    'total_linha' => $this->money($item->total_linha),
                ])
                ->values()
                ->all(),
        ];
    }

    private function paymentSnapshot(Payment $payment): array
    {
        return [
            'id' => (string) $payment->id,
            'user_id' => $payment->user_id ? (string) $payment->user_id : null,
            'bank_statement_id' => $payment->bank_statement_id ? (string) $payment->bank_statement_id : null,
            'amount' => $this->money($payment->amount),
            'allocated_amount' => $this->money($payment->allocated_amount),
            'unallocated_amount' => $this->money($payment->unallocated_amount),
            'payment_date' => $payment->payment_date?->toDateString(),
            'method' => $payment->method,
            'source' => $payment->source,
            'status' => $payment->status,
            'deleted_at' => $payment->deleted_at?->toIso8601String(),
        ];
    }

    private function paymentAllocationSnapshot(PaymentAllocation $allocation): array
    {
        return [
            'id' => (string) $allocation->id,
            'payment_id' => $allocation->payment_id ? (string) $allocation->payment_id : null,
            'invoice_id' => $allocation->invoice_id ? (string) $allocation->invoice_id : null,
            'financial_entry_id' => $allocation->financial_entry_id ? (string) $allocation->financial_entry_id : null,
            'amount' => $this->money($allocation->amount),
            'status' => $allocation->status,
            'allocated_at' => $allocation->allocated_at?->toIso8601String(),
            'deleted_at' => $allocation->deleted_at?->toIso8601String(),
        ];
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
            'priority' => $request->priority,
            'amount' => $this->money($request->amount),
            'external_document_number' => $request->external_document_number,
            'external_document_id' => $request->external_document_id,
            'issued_at' => $request->issued_at?->toIso8601String(),
            'deleted_at' => $request->deleted_at?->toIso8601String(),
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
            'regra_usada' => $map->regra_usada,
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
            'valor_alocado' => $this->money($allocation->valor_alocado),
            'status' => $allocation->status,
            'origem' => $allocation->origem,
        ];
    }

    private function financialEntrySnapshot(FinancialEntry $entry): array
    {
        return [
            'id' => (string) $entry->id,
            'data' => $entry->data?->toDateString(),
            'tipo' => $entry->tipo,
            'categoria' => $entry->categoria,
            'valor' => $this->money($entry->valor),
            'valor_pago' => $this->money($entry->valor_pago),
            'valor_em_aberto' => $this->money($entry->valor_em_aberto),
            'estado' => $entry->estado,
            'fatura_id' => $entry->fatura_id ? (string) $entry->fatura_id : null,
            'payment_id' => $entry->payment_id ? (string) $entry->payment_id : null,
            'origem_tipo' => $entry->origem_tipo,
            'origem_id' => $entry->origem_id,
            'fiscal_document_request_id' => $entry->fiscal_document_request_id ? (string) $entry->fiscal_document_request_id : null,
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}

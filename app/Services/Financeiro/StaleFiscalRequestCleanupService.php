<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StaleFiscalRequestCleanupService
{
    public const VERSION = 'a4-6-stale-fiscal-request-cleanup-v2';
    public const CLEANUP_VERSION = 'a3-6';
    public const CLEANUP_REASON = 'soft_deleted_pending_request_without_external_document_for_unpaid_invoice';
    public const CLEANUP_NOTE = '[A3.6] Pedido fiscal pendente stale arquivado logicamente; sem documento externo; invoice nao paga; sem alteracao fiscal emitida.';
    public const REVERSAL_CLEANUP_VERSION = 'a4-6';
    public const REVERSAL_CLEANUP_REASON = 'pending_request_after_reversed_payment_allocation_without_external_document';
    public const REVERSAL_CLEANUP_NOTE = '[A4.6] Pedido fiscal pendente stale arquivado logicamente apos reversao de alocacao; sem documento externo; sem alteracao fiscal emitida.';

    private const TOLERANCE = 0.01;

    /**
     * @param array{apply?:bool,invoice?:string|null} $options
     * @return array<string,mixed>
     */
    public function run(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $invoiceId = $this->normalizeNullableString($options['invoice'] ?? null);
        $items = [];
        $appliedCount = 0;

        $requests = $this->candidateRequests($invoiceId);

        foreach ($requests as $request) {
            $item = $this->inspectRequest($request);

            if ($apply && in_array($item['classification'], ['safe_to_archive_stale_request', 'safe_to_archive_reversed_stale_request'], true)) {
                $item = DB::transaction(function () use ($request): array {
                    $locked = FiscalDocumentRequest::withTrashed()
                        ->lockForUpdate()
                        ->whereKey($request->id)
                        ->firstOrFail();

                    $lockedItem = $this->inspectRequest($locked);

                    if (! in_array($lockedItem['classification'], ['safe_to_archive_stale_request', 'safe_to_archive_reversed_stale_request'], true)) {
                        return $lockedItem;
                    }

                    $this->archive($locked, (string) $lockedItem['cleanup_version'], (string) $lockedItem['cleanup_reason'], (string) $lockedItem['cleanup_note']);

                    $updated = FiscalDocumentRequest::withTrashed()->whereKey($locked->id)->firstOrFail();
                    $appliedItem = $this->inspectRequest($updated);
                    $appliedItem['action'] = 'archive_stale_request_metadata';
                    $appliedItem['applied'] = true;

                    return $appliedItem;
                });

                if ((bool) $item['applied']) {
                    $appliedCount++;
                }
            }

            $items[] = $item;
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'mode' => $apply ? 'apply' : 'dry-run',
            'filters' => [
                'invoice' => $invoiceId,
            ],
            'summary' => $this->summary($items, $appliedCount),
            'items' => $items,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int,FiscalDocumentRequest>
     */
    private function candidateRequests(?string $invoiceId)
    {
        return FiscalDocumentRequest::withTrashed()
            ->with('invoice')
            ->where('status', FiscalDocumentRequest::STATUS_PENDING)
            ->whereNotNull('invoice_id')
            ->when($invoiceId !== null, fn (Builder $query): Builder => $query->where('invoice_id', $invoiceId))
            ->orderBy('invoice_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (FiscalDocumentRequest $request): bool => $request->invoice instanceof Invoice)
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    private function inspectRequest(FiscalDocumentRequest $request): array
    {
        $invoice = $request->invoice instanceof Invoice
            ? $request->invoice
            : Invoice::query()->whereKey($request->invoice_id)->first();
        $riskReasons = $this->riskReasons($request, $invoice);
        $alreadyArchived = $this->isArchivedStaleRequest($request);
        $canArchiveA36 = $riskReasons === [] && $request->trashed();
        $canArchiveA46 = $this->canArchiveReversedStaleRequest($request, $invoice);
        $classification = match (true) {
            $alreadyArchived => 'already_archived_stale_request',
            $canArchiveA36 => 'safe_to_archive_stale_request',
            $canArchiveA46 => 'safe_to_archive_reversed_stale_request',
            default => $riskReasons[0],
        };
        $cleanupVersion = $classification === 'safe_to_archive_reversed_stale_request' ? self::REVERSAL_CLEANUP_VERSION : self::CLEANUP_VERSION;
        $cleanupReason = $classification === 'safe_to_archive_reversed_stale_request' ? self::REVERSAL_CLEANUP_REASON : self::CLEANUP_REASON;
        $cleanupNote = $classification === 'safe_to_archive_reversed_stale_request' ? self::REVERSAL_CLEANUP_NOTE : self::CLEANUP_NOTE;

        return [
            'fiscal_request_id' => (string) $request->id,
            'invoice_id' => $request->invoice_id ? (string) $request->invoice_id : null,
            'user_id' => $request->user_id ? (string) $request->user_id : null,
            'status' => (string) $request->status,
            'deleted_at' => $request->deleted_at?->toIso8601String(),
            'external_document_number' => $this->normalizeNullableString($request->external_document_number),
            'external_document_id' => $this->normalizeNullableString($request->external_document_id),
            'issued_at' => $request->issued_at?->toIso8601String(),
            'classification' => $classification,
            'risk_reasons' => $riskReasons,
            'can_archive_stale_request' => in_array($classification, ['safe_to_archive_stale_request', 'safe_to_archive_reversed_stale_request'], true),
            'cleanup_version' => $cleanupVersion,
            'cleanup_reason' => $cleanupReason,
            'cleanup_note' => $cleanupNote,
            'action' => in_array($classification, ['safe_to_archive_stale_request', 'safe_to_archive_reversed_stale_request'], true)
                ? 'archive_stale_request_metadata'
                : 'skip',
            'applied' => false,
            'recommendation' => $this->recommendation($classification),
        ];
    }

    /**
     * @return list<string>
     */
    private function riskReasons(FiscalDocumentRequest $request, ?Invoice $invoice): array
    {
        $reasons = [];

        if (filled($request->external_document_number)) {
            $reasons[] = 'unsafe_external_document_present';
        }

        if (filled($request->external_document_id)) {
            $reasons[] = 'unsafe_external_document_present';
        }

        if ($request->issued_at !== null) {
            $reasons[] = 'unsafe_external_document_present';
        }

        if (! $invoice instanceof Invoice) {
            $reasons[] = 'unsafe_invoice_missing';

            return array_values(array_unique($reasons));
        }

        if ($this->hasIssuedFiscalRequest($invoice)) {
            $reasons[] = 'unsafe_issued_fiscal_request_present';
        }

        if ($invoice->estado_pagamento === 'pago'
            || (float) ($invoice->valor_pago ?? 0) > self::TOLERANCE
            || abs((float) ($invoice->valor_em_aberto ?? 0) - (float) ($invoice->valor_total ?? 0)) > self::TOLERANCE) {
            $reasons[] = 'unsafe_invoice_paid_or_partially_paid';
        }

        if (filled($invoice->numero_recibo)
            || filled($invoice->recibo_emitido_em)
            || filled($invoice->recibo_pdf_path)
            || filled($invoice->receipt_import_item_id)) {
            $reasons[] = 'unsafe_invoice_receipt_present';
        }

        if (PaymentAllocation::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->exists()) {
            $reasons[] = 'unsafe_confirmed_allocation_present';
        }

        if (MapaConciliacao::query()->where('fatura_id', $invoice->id)->exists()) {
            $reasons[] = 'unsafe_reconciliation_present';
        }

        if (BankTransactionAllocation::query()->where('invoice_id', $invoice->id)->exists()) {
            $reasons[] = 'unsafe_bank_allocation_present';
        }

        if ($this->hasFinancialEntryTrail($invoice, $request)) {
            $reasons[] = 'unsafe_financial_entry_present';
        }

        if (! $request->trashed() && ! $this->hasSoftDeletedCancelledAllocation($invoice)) {
            $reasons[] = 'unsafe_request_not_soft_deleted';
        }

        return array_values(array_unique($reasons));
    }

    private function canArchiveReversedStaleRequest(FiscalDocumentRequest $request, ?Invoice $invoice): bool
    {
        if (! $invoice instanceof Invoice) {
            return false;
        }

        if (filled($request->external_document_number)
            || filled($request->external_document_id)
            || $request->issued_at !== null
            || filled($invoice->numero_recibo)
            || filled($invoice->recibo_emitido_em)
            || filled($invoice->recibo_pdf_path)
            || filled($invoice->receipt_import_item_id)
            || $this->hasIssuedFiscalRequest($invoice)) {
            return false;
        }

        if (! $this->hasSoftDeletedCancelledAllocation($invoice)) {
            return false;
        }

        if ($this->hasActiveConfirmedAllocation($invoice)
            || $this->hasActiveReconciliation($invoice)
            || $this->hasActiveBankAllocation($invoice)
            || $this->hasActiveFinancialEntryTrail($invoice, $request)) {
            return false;
        }

        $invoiceIsOpen = $invoice->estado_pagamento !== 'pago'
            && (float) ($invoice->valor_pago ?? 0) <= self::TOLERANCE
            && abs((float) ($invoice->valor_em_aberto ?? 0) - (float) ($invoice->valor_total ?? 0)) <= self::TOLERANCE;

        return $invoiceIsOpen || $this->hasSoftDeletedCancelledAllocation($invoice);
    }

    private function hasSoftDeletedCancelledAllocation(Invoice $invoice): bool
    {
        return PaymentAllocation::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentAllocation::STATUS_CANCELLED)
            ->whereNotNull('deleted_at')
            ->exists();
    }

    private function hasActiveConfirmedAllocation(Invoice $invoice): bool
    {
        return PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function hasIssuedFiscalRequest(Invoice $invoice): bool
    {
        return FiscalDocumentRequest::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->where(function (Builder $query): void {
                $query->where('status', FiscalDocumentRequest::STATUS_ISSUED)
                    ->orWhereNotNull('external_document_number')
                    ->orWhereNotNull('external_document_id')
                    ->orWhereNotNull('issued_at');
            })
            ->exists();
    }

    private function hasActiveReconciliation(Invoice $invoice): bool
    {
        return MapaConciliacao::query()
            ->where('fatura_id', $invoice->id)
            ->whereNotIn('status', ['cancelled', 'cancelado', 'reversed', 'anulado'])
            ->exists();
    }

    private function hasActiveBankAllocation(Invoice $invoice): bool
    {
        return BankTransactionAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', BankTransactionAllocation::STATUS_CONFIRMED)
            ->exists();
    }

    private function hasFinancialEntryTrail(Invoice $invoice, FiscalDocumentRequest $request): bool
    {
        $allocationIds = PaymentAllocation::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->pluck('id')
            ->filter()
            ->all();

        return FinancialEntry::query()
            ->where(function (Builder $query) use ($invoice, $request, $allocationIds): void {
                $query->where('fatura_id', $invoice->id)
                    ->orWhere('fiscal_document_request_id', $request->id);

                if ($allocationIds !== []) {
                    $query->orWhere(function (Builder $query) use ($allocationIds): void {
                        $query->where('origem_tipo', 'payment_allocation')
                            ->whereIn('origem_id', $allocationIds);
                    });
                }
            })
            ->exists();
    }

    private function hasActiveFinancialEntryTrail(Invoice $invoice, FiscalDocumentRequest $request): bool
    {
        $allocationIds = PaymentAllocation::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->pluck('id')
            ->filter()
            ->all();

        return FinancialEntry::query()
            ->where(function (Builder $query) use ($invoice, $request, $allocationIds): void {
                $query->where('fatura_id', $invoice->id)
                    ->orWhere('fiscal_document_request_id', $request->id);

                if ($allocationIds !== []) {
                    $query->orWhere(function (Builder $query) use ($allocationIds): void {
                        $query->where('origem_tipo', 'payment_allocation')
                            ->whereIn('origem_id', $allocationIds);
                    });
                }
            })
            ->where(function (Builder $query): void {
                $query->whereIn('estado', ['pago', 'parcial'])
                    ->orWhere('valor_pago', '>', self::TOLERANCE)
                    ->orWhereNotNull('payment_id');
            })
            ->exists();
    }

    private function archive(FiscalDocumentRequest $request, string $cleanupVersion, string $cleanupReason, string $cleanupNote): void
    {
        if ($this->isArchivedStaleRequest($request)) {
            return;
        }

        $metadata = (array) ($request->metadata ?? []);
        $metadata['stale_cleanup'] = true;
        $metadata['stale_cleanup_at'] = Carbon::now()->toIso8601String();
        $metadata['stale_cleanup_reason'] = $cleanupReason;
        $metadata['stale_cleanup_version'] = $cleanupVersion;

        $notes = (string) ($request->notes ?? '');
        if (! Str::contains($notes, $cleanupNote)) {
            $notes = trim($notes) === ''
                ? $cleanupNote
                : rtrim($notes) . PHP_EOL . $cleanupNote;
        }

        $request->forceFill([
            'metadata' => $metadata,
            'notes' => $notes,
        ])->save();
    }

    private function isArchivedStaleRequest(FiscalDocumentRequest $request): bool
    {
        $version = data_get($request->metadata, 'stale_cleanup_version');
        $reason = data_get($request->metadata, 'stale_cleanup_reason');

        return (bool) data_get($request->metadata, 'stale_cleanup') === true
            && (($version === self::CLEANUP_VERSION && $reason === self::CLEANUP_REASON)
                || ($version === self::REVERSAL_CLEANUP_VERSION && $reason === self::REVERSAL_CLEANUP_REASON))
            && blank($request->external_document_number)
            && blank($request->external_document_id)
            && $request->issued_at === null;
    }

    private function recommendation(string $classification): string
    {
        return match ($classification) {
            'safe_to_archive_stale_request' => 'apply_with_controlled_cleanup_if_operationally_confirmed',
            'safe_to_archive_reversed_stale_request' => 'apply_with_controlled_cleanup_after_payment_reversal_if_operationally_confirmed',
            'already_archived_stale_request' => 'no_action_needed_stale_fiscal_request_archived',
            default => 'manual_review_required_before_any_fiscal_or_financial_change',
        };
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items, int $appliedCount): array
    {
        $safe = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'safe_to_archive_stale_request'));
        $safeReversed = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'safe_to_archive_reversed_stale_request'));
        $alreadyArchived = count(array_filter($items, static fn (array $item): bool => $item['classification'] === 'already_archived_stale_request'));
        $unsafe = count($items) - $safe - $safeReversed - $alreadyArchived;

        return [
            'total_candidates' => count($items),
            'safe_to_archive_stale_request' => $safe,
            'safe_to_archive_reversed_stale_request' => $safeReversed,
            'already_archived_stale_request' => $alreadyArchived,
            'unsafe_count' => $unsafe,
            'applied_count' => $appliedCount,
            'skipped_count' => count($items) - $appliedCount,
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}

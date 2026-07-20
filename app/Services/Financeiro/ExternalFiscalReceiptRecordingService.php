<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ExternalFiscalReceiptRecordingService
{
    private const VERSION = 'a6-5-external-fiscal-receipt-recording-v1';
    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly FiscalDocumentRequestService $requestService,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function record(string $fiscalRequestId, array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $dryRun = ! $apply;
        $confirmed = (bool) ($options['confirm_manual_receipt'] ?? false);
        $receiptNumber = $this->stringOrNull($options['receipt_number'] ?? null);
        $issuedAtInput = $this->stringOrNull($options['issued_at'] ?? null);
        $externalDocumentId = $this->stringOrNull($options['external_document_id'] ?? null);
        $providerInput = $this->stringOrNull($options['provider'] ?? null);
        $receiptPdfPath = $this->stringOrNull($options['receipt_pdf_path'] ?? null);
        $notes = $this->stringOrNull($options['notes'] ?? null);

        $context = $this->context($fiscalRequestId, $providerInput, $receiptNumber, $issuedAtInput);
        $blockedReasons = $context['blocked_reasons'];
        $request = $context['request'];
        $invoice = $context['invoice'];
        $provider = $context['provider'];
        $issuedAt = $context['issued_at'];

        if ($apply && ! $confirmed) {
            $blockedReasons[] = 'missing_confirm_manual_receipt';
        }

        if ($apply && $receiptNumber === null) {
            $blockedReasons[] = 'missing_receipt_number';
        }

        if ($apply && $issuedAt === null) {
            $blockedReasons[] = 'missing_issued_at';
        }

        $blockedReasons = array_values(array_unique($blockedReasons));

        if ($request instanceof FiscalDocumentRequest && $invoice instanceof Invoice && $this->alreadyRecorded($request, $invoice, $provider, $receiptNumber, $issuedAt)) {
            return $this->payload(
                $fiscalRequestId,
                $invoice,
                $provider,
                $receiptNumber,
                $issuedAt,
                dryRun: $dryRun,
                apply: $apply,
                confirmed: $confirmed,
                ready: false,
                blockedReasons: [],
                changesPreview: [],
                applied: false,
                skipped: true,
                action: 'already_recorded',
            );
        }

        if ($blockedReasons !== [] || ! $request instanceof FiscalDocumentRequest || ! $invoice instanceof Invoice || $receiptNumber === null || ! $issuedAt instanceof Carbon || $provider === null) {
            return $this->payload(
                $fiscalRequestId,
                $invoice,
                $provider,
                $receiptNumber,
                $issuedAt,
                dryRun: $dryRun,
                apply: $apply,
                confirmed: $confirmed,
                ready: false,
                blockedReasons: $blockedReasons,
                changesPreview: $this->changesPreview($request, $invoice, $provider, $receiptNumber, $issuedAt, $externalDocumentId, $receiptPdfPath, $notes),
                applied: false,
                skipped: false,
                action: 'blocked',
            );
        }

        $changesPreview = $this->changesPreview($request, $invoice, $provider, $receiptNumber, $issuedAt, $externalDocumentId, $receiptPdfPath, $notes);

        if ($dryRun) {
            return $this->payload($fiscalRequestId, $invoice, $provider, $receiptNumber, $issuedAt, true, false, $confirmed, true, [], $changesPreview, false, false, 'dry_run_ready');
        }

        $updatedRequest = DB::transaction(function () use ($request, $provider, $receiptNumber, $issuedAt, $externalDocumentId, $receiptPdfPath, $notes): FiscalDocumentRequest {
            $lockedRequest = FiscalDocumentRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $lockedInvoice = Invoice::query()->whereKey($lockedRequest->invoice_id)->lockForUpdate()->firstOrFail();

            $metadata = array_merge((array) ($lockedRequest->metadata ?? []), [
                'manual_receipt_recording' => [
                    'recorded_manually' => true,
                    'recorded_at' => Carbon::now()->toIso8601String(),
                    'recorded_by' => null,
                    'notes' => $notes,
                    'previous_status' => $lockedRequest->status,
                    'source' => 'manual_external_receipt',
                    'version' => self::VERSION,
                ],
            ]);

            $lockedRequest->forceFill([
                'provider' => $provider,
                'metadata' => $metadata,
            ])->save();

            $issued = $this->requestService->markIssued($lockedRequest, [
                'external_document_number' => $receiptNumber,
                'external_document_id' => $externalDocumentId,
                'issued_at' => $issuedAt->toDateTimeString(),
                'notes' => $notes ?? $lockedRequest->notes,
            ]);

            if ($receiptPdfPath !== null) {
                $lockedInvoice->forceFill([
                    'recibo_pdf_path' => $receiptPdfPath,
                ])->save();
            }

            return $issued;
        });

        $updatedInvoice = $updatedRequest->invoice_id ? Invoice::query()->find($updatedRequest->invoice_id) : $invoice;

        return $this->payload(
            $fiscalRequestId,
            $updatedInvoice,
            $provider,
            $receiptNumber,
            $issuedAt,
            dryRun: false,
            apply: true,
            confirmed: true,
            ready: true,
            blockedReasons: [],
            changesPreview: $changesPreview,
            applied: true,
            skipped: false,
            action: 'recorded',
        );
    }

    /**
     * @return array{request:?FiscalDocumentRequest,invoice:?Invoice,provider:?string,issued_at:?Carbon,blocked_reasons:list<string>}
     */
    private function context(string $fiscalRequestId, ?string $providerInput, ?string $receiptNumber, ?string $issuedAtInput): array
    {
        $blocked = [];
        $request = FiscalDocumentRequest::withTrashed()->whereKey($fiscalRequestId)->first();
        $invoice = null;
        $provider = $providerInput;
        $issuedAt = null;

        if (! $request instanceof FiscalDocumentRequest) {
            return [
                'request' => null,
                'invoice' => null,
                'provider' => $provider,
                'issued_at' => null,
                'blocked_reasons' => ['fiscal_request_not_found'],
            ];
        }

        if ($request->trashed()) {
            $blocked[] = 'fiscal_request_deleted';
        }

        $provider ??= $this->stringOrNull($request->provider);
        if ($provider === null) {
            $blocked[] = 'missing_provider';
        }

        if ($receiptNumber === null) {
            $blocked[] = 'missing_receipt_number';
        }

        if ($issuedAtInput === null) {
            $blocked[] = 'missing_issued_at';
        } else {
            try {
                $issuedAt = Carbon::parse($issuedAtInput);
            } catch (\Throwable) {
                $blocked[] = 'invalid_issued_at';
            }
        }

        if (! in_array((string) $request->status, [
            FiscalDocumentRequest::STATUS_PENDING,
            FiscalDocumentRequest::STATUS_IN_PROGRESS,
        ], true) && ! $this->requestHasDocumentSignal($request)) {
            $blocked[] = 'fiscal_request_status_not_recordable';
        }

        if ($this->requestHasDocumentSignal($request)) {
            $blocked[] = 'already_has_fiscal_document_signal';
        }

        if (! $request->invoice_id) {
            $blocked[] = 'invoice_missing';
        } else {
            $invoice = Invoice::query()->whereKey($request->invoice_id)->first();
            if (! $invoice instanceof Invoice) {
                $blocked[] = 'invoice_missing';
            }
        }

        if ($invoice instanceof Invoice) {
            if ((string) $invoice->estado_pagamento !== 'pago') {
                $blocked[] = 'invoice_not_paid';
            }

            if (filled($invoice->numero_recibo) || filled($invoice->recibo_emitido_em)) {
                $blocked[] = 'invoice_already_has_receipt_signal';
            }

            if (! $this->amountMatches($request, $invoice)) {
                $blocked[] = 'amount_mismatch';
            }

            if (! $this->hasConfirmedPaymentAllocation($invoice)) {
                $blocked[] = 'missing_confirmed_payment_allocation';
            }

            if ($issuedAt instanceof Carbon) {
                $invoiceDate = $this->invoiceDate($invoice);
                if ($invoiceDate instanceof Carbon && $issuedAt->copy()->startOfDay()->lt($invoiceDate->copy()->startOfDay())) {
                    $blocked[] = 'issued_at_before_invoice_date';
                }
            }
        }

        if ($issuedAt instanceof Carbon && $issuedAt->gt(Carbon::now()->addDay())) {
            $blocked[] = 'issued_at_too_far_in_future';
        }

        if ($provider !== null && $receiptNumber !== null && $this->receiptNumberExists($provider, $receiptNumber, $request, $invoice)) {
            $blocked[] = 'duplicate_receipt_number_for_provider';
        }

        return [
            'request' => $request,
            'invoice' => $invoice,
            'provider' => $provider,
            'issued_at' => $issuedAt,
            'blocked_reasons' => array_values(array_unique($blocked)),
        ];
    }

    private function requestHasDocumentSignal(FiscalDocumentRequest $request): bool
    {
        return filled($request->external_document_number)
            || filled($request->external_document_id)
            || $request->issued_at !== null
            || (string) $request->status === FiscalDocumentRequest::STATUS_ISSUED;
    }

    private function alreadyRecorded(FiscalDocumentRequest $request, Invoice $invoice, ?string $provider, ?string $receiptNumber, ?Carbon $issuedAt): bool
    {
        return (string) $request->status === FiscalDocumentRequest::STATUS_ISSUED
            && $provider !== null
            && $receiptNumber !== null
            && $issuedAt instanceof Carbon
            && (string) $request->provider === $provider
            && (string) $request->external_document_number === $receiptNumber
            && $request->issued_at instanceof Carbon
            && $request->issued_at->toDateString() === $issuedAt->toDateString()
            && (string) $invoice->numero_recibo === $receiptNumber
            && $invoice->recibo_emitido_em instanceof Carbon
            && $invoice->recibo_emitido_em->toDateString() === $issuedAt->toDateString();
    }

    private function amountMatches(FiscalDocumentRequest $request, Invoice $invoice): bool
    {
        $requestAmount = $this->money($request->amount);
        $invoiceAmount = $this->money($invoice->valor_total);
        if (abs($requestAmount - $invoiceAmount) <= self::TOLERANCE) {
            return true;
        }

        $allocationAmount = PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at')
            ->sum('amount');

        return abs($requestAmount - $this->money($allocationAmount)) <= self::TOLERANCE;
    }

    private function hasConfirmedPaymentAllocation(Invoice $invoice): bool
    {
        return PaymentAllocation::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->whereNull('deleted_at')
            ->whereHas('payment', static function ($query): void {
                $query
                    ->where('status', Payment::STATUS_CONFIRMED)
                    ->whereNull('deleted_at');
            })
            ->exists();
    }

    private function receiptNumberExists(string $provider, string $receiptNumber, FiscalDocumentRequest $request, ?Invoice $invoice): bool
    {
        $requestDuplicate = FiscalDocumentRequest::withTrashed()
            ->where('provider', $provider)
            ->where('external_document_number', $receiptNumber)
            ->whereKeyNot($request->id)
            ->exists();

        if ($requestDuplicate) {
            return true;
        }

        return Invoice::query()
            ->where('numero_recibo', $receiptNumber)
            ->when($invoice instanceof Invoice, fn ($query) => $query->whereKeyNot($invoice->id))
            ->whereHas('fiscalDocumentRequests', static function ($query) use ($provider): void {
                $query->where('provider', $provider);
            })
            ->exists();
    }

    private function invoiceDate(Invoice $invoice): ?Carbon
    {
        $value = $invoice->data_emissao ?? $invoice->data_fatura ?? $invoice->created_at;

        return $value ? Carbon::parse((string) $value) : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function changesPreview(?FiscalDocumentRequest $request, ?Invoice $invoice, ?string $provider, ?string $receiptNumber, ?Carbon $issuedAt, ?string $externalDocumentId, ?string $receiptPdfPath, ?string $notes): array
    {
        return [
            'fiscal_document_request' => [
                'id' => $request?->id,
                'status' => [
                    'from' => $request?->status,
                    'to' => FiscalDocumentRequest::STATUS_ISSUED,
                ],
                'provider' => [
                    'from' => $request?->provider,
                    'to' => $provider,
                ],
                'external_document_number' => [
                    'from' => $request?->external_document_number,
                    'to' => $receiptNumber,
                ],
                'external_document_id' => [
                    'from' => $request?->external_document_id,
                    'to' => $externalDocumentId,
                ],
                'issued_at' => [
                    'from' => $request?->issued_at?->toIso8601String(),
                    'to' => $issuedAt?->toIso8601String(),
                ],
                'metadata.manual_receipt_recording.source' => 'manual_external_receipt',
            ],
            'invoice' => [
                'id' => $invoice?->id,
                'numero_recibo' => [
                    'from' => $invoice?->numero_recibo,
                    'to' => $receiptNumber,
                ],
                'recibo_emitido_em' => [
                    'from' => $invoice?->recibo_emitido_em?->toDateString(),
                    'to' => $issuedAt?->toDateString(),
                ],
                'recibo_pdf_path' => [
                    'from' => $invoice?->recibo_pdf_path,
                    'to' => $receiptPdfPath,
                ],
            ],
            'notes' => $notes,
        ];
    }

    /**
     * @param list<string> $blockedReasons
     * @param array<string,mixed> $changesPreview
     * @return array<string,mixed>
     */
    private function payload(string $fiscalRequestId, ?Invoice $invoice, ?string $provider, ?string $receiptNumber, ?Carbon $issuedAt, bool $dryRun, bool $apply, bool $confirmed, bool $ready, array $blockedReasons, array $changesPreview, bool $applied, bool $skipped, string $action): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'dry_run' => $dryRun,
            'apply' => $apply,
            'confirmed_manual_receipt' => $confirmed,
            'fiscal_request_id' => $fiscalRequestId,
            'invoice_id' => $invoice?->id ? (string) $invoice->id : null,
            'provider' => $provider,
            'receipt_number' => $receiptNumber,
            'issued_at' => $issuedAt?->toIso8601String(),
            'ready_to_record' => $ready,
            'blocked_reasons' => array_values($blockedReasons),
            'changes_preview' => $changesPreview,
            'applied' => $applied,
            'skipped' => $skipped,
            'action' => $action,
            'read_only_when_dry_run' => $dryRun,
        ];
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

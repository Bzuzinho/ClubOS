<?php

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Validation\ValidationException;

class MonthlyInvoiceStatusService
{
    public const REOPEN_WITH_FISCAL_DOCUMENT_MESSAGE = 'Ja existe documento fiscal emitido. E necessario cancelar/anular fiscalmente antes de reabrir a mensalidade.';

    public function __construct(
        private readonly FinancialSettlementService $financialSettlementService,
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly FiscalDocumentRequestService $fiscalDocumentRequestService,
    ) {
    }

    public function transition(Invoice $invoice, string $targetStatus, array $options = []): Invoice
    {
        return match ($targetStatus) {
            'pago' => $this->markAsPaid($invoice, $options),
            'pendente', 'vencido' => in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
                ? $this->revertToOpen($invoice, $targetStatus, $options)
                : $this->setOpenStatus($invoice, $targetStatus),
            default => throw ValidationException::withMessages([
                'estado_pagamento' => 'Estado de pagamento invalido para mensalidades.',
            ]),
        };
    }

    public function markAsPaid(Invoice $invoice, array $options = []): Invoice
    {
        $invoice = $this->resolveMonthlyInvoice($invoice);
        $outstandingAmount = $this->getOutstandingAmount($invoice);

        if ($outstandingAmount <= 0.009) {
            return $this->paymentAllocationService->recalculateInvoicePaymentStatus($invoice);
        }

        $this->financialSettlementService->settleInvoices([
            [
                'invoice_id' => $invoice->id,
                'amount' => $outstandingAmount,
                'notes' => $options['notes'] ?? null,
            ],
        ], [
            'bank_statement_id' => $options['bank_statement_id'] ?? null,
            'amount' => $options['amount'] ?? $outstandingAmount,
            'payment_date' => $options['payment_date'] ?? now()->toDateString(),
            'method' => $options['method'] ?? null,
            'reference' => $options['reference'] ?? null,
            'notes' => $options['notes'] ?? null,
            'user_id' => $invoice->user_id,
            'created_by' => $options['created_by'] ?? null,
            'source' => ! empty($options['bank_statement_id']) ? Payment::SOURCE_BANK_STATEMENT : Payment::SOURCE_MANUAL,
        ]);

        return $invoice->fresh();
    }

    public function revertToOpen(Invoice $invoice, string $targetStatus, array $options = []): Invoice
    {
        if (! in_array($targetStatus, ['pendente', 'vencido'], true)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A mensalidade so pode ser reaberta para pendente ou vencido.',
            ]);
        }

        $invoice = $this->resolveMonthlyInvoice($invoice);

        if ($this->fiscalDocumentRequestService->invoiceHasRegisteredDocument($invoice)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => self::REOPEN_WITH_FISCAL_DOCUMENT_MESSAGE,
            ]);
        }

        $affectedPaymentIds = PaymentAllocation::query()
            ->confirmed()
            ->where('invoice_id', $invoice->id)
            ->pluck('payment_id')
            ->filter()
            ->unique()
            ->values();

        $invoice = $this->paymentAllocationService->reverseInvoicePayments($invoice, [
            'cancelled_by' => $options['created_by'] ?? null,
            'cancelled_at' => now(),
        ]);

        foreach ($affectedPaymentIds as $paymentId) {
            $payment = Payment::query()
                ->with([
                    'allocations' => function ($query): void {
                        $query->confirmed();
                    },
                    'credits' => function ($query): void {
                        $query->where('status', '!=', AccountCredit::STATUS_CANCELLED);
                    },
                ])
                ->find($paymentId);

            if ($payment) {
                $this->cancelOrphanPaymentIfSafe(
                    $payment,
                    $options['created_by'] ?? null,
                    'Pagamento revertido por reabertura canonica de mensalidade.'
                );
            }
        }

        $invoice->forceFill([
            'estado_pagamento' => $targetStatus,
            'valor_pago' => 0,
            'valor_em_aberto' => round((float) $invoice->valor_total, 2),
            'data_pagamento' => null,
            'metodo_pagamento' => null,
            'referencia_pagamento' => null,
            'pagamento_observacoes' => null,
        ]);
        $invoice->save();

        return $invoice->fresh();
    }

    private function resolveMonthlyInvoice(Invoice $invoice): Invoice
    {
        $invoice = $invoice->fresh();

        if (! $invoice || $invoice->tipo !== 'mensalidade') {
            throw ValidationException::withMessages([
                'invoice' => 'Este fluxo so esta disponivel para mensalidades.',
            ]);
        }

        if ($invoice->estado_pagamento === 'cancelado') {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'Nao e possivel alterar o estado de uma mensalidade cancelada.',
            ]);
        }

        return $invoice;
    }

    private function getOutstandingAmount(Invoice $invoice): float
    {
        $paidAmount = round((float) PaymentAllocation::query()
            ->confirmed()
            ->where('invoice_id', $invoice->id)
            ->sum('amount'), 2);

        return round(max((float) $invoice->valor_total - $paidAmount, 0), 2);
    }

    private function setOpenStatus(Invoice $invoice, string $targetStatus): Invoice
    {
        $invoice->forceFill([
            'estado_pagamento' => $targetStatus,
        ]);
        $invoice->save();

        return $invoice->fresh();
    }

    private function cancelOrphanPaymentIfSafe(Payment $payment, ?string $cancelledBy, string $reason): void
    {
        if ($payment->status !== Payment::STATUS_CONFIRMED) {
            return;
        }

        if ($payment->allocations->isNotEmpty() || $payment->credits->isNotEmpty()) {
            return;
        }

        if (! in_array($payment->source, [
            Payment::SOURCE_MANUAL,
            Payment::SOURCE_RECONCILIATION,
            Payment::SOURCE_BANK_STATEMENT,
        ], true)) {
            return;
        }

        $payment->forceFill([
            'status' => Payment::STATUS_CANCELLED,
            'cancelled_by' => $cancelledBy,
            'cancelled_at' => now(),
            'notes' => $this->appendRepairNote($payment->notes, $reason),
        ]);
        $payment->save();
    }

    private function appendRepairNote(?string $existingNotes, string $reason): string
    {
        $existingNotes = trim((string) $existingNotes);

        if ($existingNotes === '') {
            return $reason;
        }

        if (str_contains($existingNotes, $reason)) {
            return $existingNotes;
        }

        return $existingNotes . "\n" . $reason;
    }
}
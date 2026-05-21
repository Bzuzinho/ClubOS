<?php

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Validation\ValidationException;

class MonthlyInvoiceStatusService
{
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

        return $this->paymentAllocationService->reopenInvoice($invoice, $targetStatus, $options);
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
}
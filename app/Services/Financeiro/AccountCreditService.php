<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\AccountCreditUsage;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountCreditService
{
    private const TOLERANCE = 0.01;

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function previewCreateFromPaymentOverpayment(Payment $payment, ?float $amount = null, array $options = []): array
    {
        $payment = $payment->fresh(['credits']);
        $requestedAmount = $this->resolveCreditAmount($payment, $amount);
        $existing = $this->existingPaymentOverallocationCredit($payment);

        $this->ensurePaymentCanCreateCredit($payment, $requestedAmount, $existing);

        return [
            'action' => $existing ? 'reuse_existing_credit' : 'create_account_credit',
            'payment_id' => (string) $payment->id,
            'account_credit_id' => $existing?->id,
            'amount' => $requestedAmount,
            'user_id' => $options['user_id'] ?? $payment->user_id,
            'family_id' => $options['family_id'] ?? $payment->family_id,
            'source' => AccountCredit::SOURCE_PAYMENT_OVERALLOCATION,
            'status' => $existing?->status ?? AccountCredit::STATUS_AVAILABLE,
        ];
    }

    /**
     * @param array<string,mixed> $options
     */
    public function createFromPaymentOverpayment(Payment $payment, ?float $amount = null, array $options = []): AccountCredit
    {
        return DB::transaction(function () use ($payment, $amount, $options): AccountCredit {
            $lockedPayment = Payment::query()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $requestedAmount = $this->resolveCreditAmount($lockedPayment, $amount);
            $existing = $this->existingPaymentOverallocationCredit($lockedPayment, lock: true);

            $this->ensurePaymentCanCreateCredit($lockedPayment, $requestedAmount, $existing);

            if ($existing) {
                return $existing->refresh();
            }

            $credit = AccountCredit::query()->create([
                'user_id' => $options['user_id'] ?? $lockedPayment->user_id,
                'family_id' => $options['family_id'] ?? $lockedPayment->family_id,
                'payment_id' => $lockedPayment->id,
                'amount' => $requestedAmount,
                'remaining_amount' => $requestedAmount,
                'source' => AccountCredit::SOURCE_PAYMENT_OVERALLOCATION,
                'status' => AccountCredit::STATUS_AVAILABLE,
                'description' => $options['description'] ?? $options['credit_description'] ?? 'Excedente de pagamento convertido em credito.',
                'created_by' => $options['created_by'] ?? null,
            ]);

            $this->syncPaymentBalances($lockedPayment->fresh());
            $this->createOrUpdateCreditFinancialEntry($lockedPayment->fresh(), $credit);

            return $credit->refresh();
        });
    }

    public function cancel(AccountCredit $credit, string $reason, ?User $actor = null): AccountCredit
    {
        return DB::transaction(function () use ($credit, $reason, $actor): AccountCredit {
            $lockedCredit = AccountCredit::query()
                ->whereKey($credit->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedCredit->status === AccountCredit::STATUS_CANCELLED) {
                return $lockedCredit->refresh();
            }

            $activeUsageAmount = $this->activeUsageAmount($lockedCredit);
            if ($activeUsageAmount > self::TOLERANCE || (float) $lockedCredit->remaining_amount + self::TOLERANCE < (float) $lockedCredit->amount) {
                throw ValidationException::withMessages([
                    'credit' => 'Nao e possivel cancelar um credito ja usado ou parcialmente usado.',
                ]);
            }

            $lockedCredit->forceFill([
                'remaining_amount' => 0,
                'status' => AccountCredit::STATUS_CANCELLED,
                'description' => $this->appendReason($lockedCredit->description, $reason, $actor),
            ])->save();

            if ($lockedCredit->payment_id) {
                $payment = Payment::query()->find($lockedCredit->payment_id);
                if ($payment) {
                    $this->syncPaymentBalances($payment);
                }
            }

            return $lockedCredit->refresh();
        });
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function previewApplyToInvoice(AccountCredit $credit, Invoice $invoice, float $amount, array $options = []): array
    {
        $credit = $credit->fresh();
        $invoice = $invoice->fresh('user.families');
        $amount = $this->money($amount);

        $this->ensureCreditCanBeApplied($credit, $invoice, $amount);

        return [
            'action' => 'apply_account_credit',
            'account_credit_id' => (string) $credit->id,
            'invoice_id' => (string) $invoice->id,
            'amount' => $amount,
            'credit_remaining_before' => $this->money($credit->remaining_amount),
            'credit_remaining_after' => $this->money((float) $credit->remaining_amount - $amount),
            'invoice_open_before' => $this->invoiceOutstandingAmount($invoice),
            'invoice_open_after' => $this->money($this->invoiceOutstandingAmount($invoice) - $amount),
            'usage_table' => 'account_credit_usages',
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{account_credit: AccountCredit, invoice: Invoice, usage: AccountCreditUsage, financial_entry: FinancialEntry}
     */
    public function applyToInvoice(AccountCredit $credit, Invoice $invoice, float $amount, array $options = []): array
    {
        return DB::transaction(function () use ($credit, $invoice, $amount, $options): array {
            $lockedCredit = AccountCredit::query()
                ->whereKey($credit->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedInvoice = Invoice::query()
                ->with('user.families')
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();
            $amount = $this->money($amount);

            $this->ensureCreditCanBeApplied($lockedCredit, $lockedInvoice, $amount);

            $usage = AccountCreditUsage::query()->create([
                'account_credit_id' => $lockedCredit->id,
                'invoice_id' => $lockedInvoice->id,
                'amount' => $amount,
                'status' => AccountCreditUsage::STATUS_APPLIED,
                'applied_at' => $options['applied_at'] ?? now(),
                'created_by' => $options['created_by'] ?? null,
                'metadata' => $options['metadata'] ?? null,
            ]);

            $this->syncCreditFromUsages($lockedCredit);
            $entry = $this->createCreditUsageFinancialEntry($lockedCredit->fresh(), $usage->fresh(), $lockedInvoice->fresh());
            $invoice = $this->recalculateInvoiceFromCreditUsages($lockedInvoice->fresh());

            return [
                'account_credit' => $lockedCredit->fresh(),
                'invoice' => $invoice,
                'usage' => $usage->fresh(),
                'financial_entry' => $entry,
            ];
        });
    }

    public function recalculateInvoiceFromCreditUsages(Invoice $invoice): Invoice
    {
        $paymentAllocationPaid = $this->money(PaymentAllocation::query()
            ->confirmed()
            ->where('invoice_id', $invoice->id)
            ->sum('amount'));
        $creditUsagePaid = $this->money(AccountCreditUsage::query()
            ->applied()
            ->where('invoice_id', $invoice->id)
            ->whereNull('deleted_at')
            ->sum('amount'));
        $paidAmount = $this->money($paymentAllocationPaid + $creditUsagePaid);
        $total = $this->money($invoice->valor_total);
        $open = $this->money(max($total - $paidAmount, 0));
        $status = 'pendente';

        if ($invoice->estado_pagamento === 'cancelado') {
            $status = 'cancelado';
        } elseif ($open <= self::TOLERANCE && $total > self::TOLERANCE) {
            $status = 'pago';
        } elseif ($paidAmount > self::TOLERANCE) {
            $status = 'parcial';
        } elseif ($invoice->data_vencimento && $invoice->data_vencimento->isPast()) {
            $status = 'vencido';
        }

        $invoice->forceFill([
            'valor_pago' => $paidAmount,
            'valor_em_aberto' => $open,
            'estado_pagamento' => $status,
            'data_pagamento' => $status === 'pago' ? now()->toDateString() : null,
            'metodo_pagamento' => $creditUsagePaid > self::TOLERANCE ? 'account_credit' : $invoice->metodo_pagamento,
            'pagamento_observacoes' => $creditUsagePaid > self::TOLERANCE ? 'Liquidacao por credito em conta corrente.' : $invoice->pagamento_observacoes,
        ])->save();

        return $invoice->refresh();
    }

    private function ensurePaymentCanCreateCredit(Payment $payment, float $amount, ?AccountCredit $existing): void
    {
        if ($payment->status !== Payment::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'payment' => 'Apenas pagamentos confirmados podem originar credito em conta.',
            ]);
        }

        if ($amount <= self::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'O valor do credito deve ser superior a zero.',
            ]);
        }

        if ($existing) {
            if (abs((float) $existing->amount - $amount) > self::TOLERANCE) {
                throw ValidationException::withMessages([
                    'amount' => 'Ja existe credito ativo para este pagamento com valor diferente.',
                ]);
            }

            return;
        }

        if ($amount - $this->money($payment->unallocated_amount) > self::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'O credito excede o valor nao alocado do pagamento.',
            ]);
        }
    }

    private function ensureCreditCanBeApplied(AccountCredit $credit, Invoice $invoice, float $amount): void
    {
        if ($amount <= self::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'O valor a aplicar deve ser superior a zero.',
            ]);
        }

        if ($credit->status === AccountCredit::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'credit' => 'Nao e possivel usar um credito cancelado.',
            ]);
        }

        if (! in_array($credit->status, [AccountCredit::STATUS_AVAILABLE, AccountCredit::STATUS_PARTIALLY_USED], true)) {
            throw ValidationException::withMessages([
                'credit' => 'Este credito nao tem saldo disponivel.',
            ]);
        }

        if ($amount - $this->money($credit->remaining_amount) > self::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'O valor a aplicar excede o saldo disponivel do credito.',
            ]);
        }

        if ($invoice->estado_pagamento === 'cancelado') {
            throw ValidationException::withMessages([
                'invoice' => 'Nao e possivel aplicar credito a uma fatura cancelada.',
            ]);
        }

        if ($invoice->estado_pagamento === 'pago' || $this->invoiceOutstandingAmount($invoice) <= self::TOLERANCE) {
            throw ValidationException::withMessages([
                'invoice' => 'Nao e possivel aplicar credito a uma fatura ja paga.',
            ]);
        }

        if ($amount - $this->invoiceOutstandingAmount($invoice) > self::TOLERANCE) {
            throw ValidationException::withMessages([
                'amount' => 'O valor a aplicar excede o valor em aberto da fatura.',
            ]);
        }

        if ($credit->user_id !== null && $invoice->user_id !== $credit->user_id) {
            throw ValidationException::withMessages([
                'invoice' => 'Nao e possivel aplicar credito a fatura de outro utilizador.',
            ]);
        }

        if ($credit->user_id === null && $credit->family_id !== null && ! $this->invoiceBelongsToFamily($invoice, (string) $credit->family_id)) {
            throw ValidationException::withMessages([
                'invoice' => 'Nao e possivel aplicar credito a fatura fora da familia do credito.',
            ]);
        }
    }

    private function resolveCreditAmount(Payment $payment, ?float $amount): float
    {
        return $this->money($amount ?? (float) $payment->unallocated_amount);
    }

    private function existingPaymentOverallocationCredit(Payment $payment, bool $lock = false): ?AccountCredit
    {
        $query = AccountCredit::query()
            ->where('payment_id', $payment->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', AccountCredit::STATUS_CANCELLED)
            ->whereIn('source', [AccountCredit::SOURCE_PAYMENT_OVERALLOCATION, AccountCredit::SOURCE_LEGACY_OVERPAYMENT])
            ->orderBy('created_at')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function syncCreditFromUsages(AccountCredit $credit): AccountCredit
    {
        $activeUsageAmount = $this->activeUsageAmount($credit);
        $remaining = $this->money(max((float) $credit->amount - $activeUsageAmount, 0));
        $status = AccountCredit::STATUS_AVAILABLE;

        if ($remaining <= self::TOLERANCE) {
            $status = AccountCredit::STATUS_USED;
        } elseif ($activeUsageAmount > self::TOLERANCE) {
            $status = AccountCredit::STATUS_PARTIALLY_USED;
        }

        $credit->forceFill([
            'remaining_amount' => $remaining,
            'status' => $status,
        ])->save();

        return $credit->refresh();
    }

    private function activeUsageAmount(AccountCredit $credit): float
    {
        return $this->money(AccountCreditUsage::query()
            ->applied()
            ->where('account_credit_id', $credit->id)
            ->whereNull('deleted_at')
            ->sum('amount'));
    }

    private function invoiceOutstandingAmount(Invoice $invoice): float
    {
        $open = $invoice->valor_em_aberto !== null
            ? (float) $invoice->valor_em_aberto
            : (float) $invoice->valor_total - (float) ($invoice->valor_pago ?? 0);

        return $this->money(max($open, 0));
    }

    private function invoiceBelongsToFamily(Invoice $invoice, string $familyId): bool
    {
        $user = $invoice->relationLoaded('user') ? $invoice->user : $invoice->user()->with('families:id')->first();

        return (bool) $user?->families?->contains('id', $familyId);
    }

    private function syncPaymentBalances(Payment $payment): Payment
    {
        $allocatedAmount = $this->money(PaymentAllocation::query()
            ->confirmed()
            ->where('payment_id', $payment->id)
            ->sum('amount'));
        $creditedAmount = $this->money(AccountCredit::query()
            ->where('payment_id', $payment->id)
            ->where('status', '!=', AccountCredit::STATUS_CANCELLED)
            ->whereNull('deleted_at')
            ->sum('amount'));

        $payment->forceFill([
            'allocated_amount' => $allocatedAmount,
            'unallocated_amount' => $this->money(max((float) $payment->amount - $allocatedAmount - $creditedAmount, 0)),
        ])->save();

        return $payment->refresh();
    }

    private function createOrUpdateCreditFinancialEntry(Payment $payment, AccountCredit $credit): FinancialEntry
    {
        $entry = FinancialEntry::query()->firstOrNew([
            'origem_tipo' => 'account_credit',
            'origem_id' => $credit->id,
        ]);

        $entry->fill([
            'data' => $payment->payment_date ?? now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Credito em Conta Corrente',
            'descricao' => 'Excedente convertido em credito de conta corrente',
            'documento_ref' => $payment->reference,
            'valor' => $credit->amount,
            'valor_pago' => 0,
            'valor_em_aberto' => $credit->remaining_amount,
            'estado' => $credit->status === AccountCredit::STATUS_USED ? 'pago' : 'pendente',
            'user_id' => $credit->user_id,
            'payment_id' => $payment->id,
            'fatura_id' => null,
            'origem_modulo' => 'financeiro',
            'metodo_pagamento' => $payment->method,
        ])->save();

        return $entry->refresh();
    }

    private function createCreditUsageFinancialEntry(AccountCredit $credit, AccountCreditUsage $usage, Invoice $invoice): FinancialEntry
    {
        $entry = FinancialEntry::query()->firstOrNew([
            'origem_tipo' => 'account_credit_usage',
            'origem_id' => $usage->id,
        ]);

        $entry->fill([
            'data' => $usage->applied_at?->toDateString() ?? now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Aplicacao de Credito em Fatura',
            'descricao' => sprintf('Credito em conta aplicado a fatura %s', $invoice->id),
            'documento_ref' => $credit->payment?->reference,
            'valor' => $usage->amount,
            'valor_pago' => $usage->amount,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => $usage->applied_at?->toDateString() ?? now()->toDateString(),
            'centro_custo_id' => $invoice->centro_custo_id,
            'user_id' => $invoice->user_id,
            'fatura_id' => $invoice->id,
            'payment_id' => $credit->payment_id,
            'origem_modulo' => 'financeiro',
            'metodo_pagamento' => 'account_credit',
        ])->save();

        return $entry->refresh();
    }

    private function appendReason(?string $description, string $reason, ?User $actor): string
    {
        $line = 'Credito cancelado: ' . trim($reason);
        if ($actor) {
            $line .= ' por ' . $actor->id;
        }

        $description = trim((string) $description);

        if ($description === '') {
            return $line;
        }

        if (str_contains($description, $line)) {
            return $description;
        }

        return $description . "\n" . $line;
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }
}

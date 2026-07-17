<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;

final class FinancialTimelineAnomalyInspectionService
{
    /**
     * @param array{payment?:mixed,allocation?:mixed,financial_entry?:mixed,bank_transaction?:mixed} $filters
     * @return array<string,mixed>|null
     */
    public function inspect(array $filters): ?array
    {
        $entities = $this->resolveEntities($filters);

        /** @var BankStatement|null $bank */
        $bank = $entities['bank'];
        /** @var Payment|null $payment */
        $payment = $entities['payment'];
        /** @var PaymentAllocation|null $allocation */
        $allocation = $entities['allocation'];
        /** @var FinancialEntry|null $entry */
        $entry = $entities['entry'];
        /** @var Invoice|null $invoice */
        $invoice = $entities['invoice'];

        if (! $bank && ! $payment && ! $allocation && ! $entry) {
            return null;
        }

        $reconciliations = $this->relatedReconciliations($bank, $payment, $allocation, $entry);
        $bankAllocations = $this->relatedBankAllocations($bank, $payment, $allocation, $invoice);
        $links = $this->links($bank, $payment, $allocation, $entry, $invoice, $reconciliations, $bankAllocations);
        $anomalies = $this->anomalies($bank, $payment, $allocation, $entry, $invoice);
        $amountsCoherent = $this->amountsCoherent($bank, $payment, $allocation, $entry);
        $nonInvoiceMovement = $this->nonInvoiceMovement($entry, $allocation, $invoice);
        $canAutoClassifyAsInfo = $nonInvoiceMovement && $amountsCoherent;
        $riskLevel = $this->riskLevel($anomalies, $amountsCoherent, $canAutoClassifyAsInfo);

        return [
            'schema_version' => 'financial_timeline_anomaly_inspection.v1',
            'filters' => [
                'payment' => $this->stringOrNull($filters['payment'] ?? null),
                'allocation' => $this->stringOrNull($filters['allocation'] ?? null),
                'financial_entry' => $this->stringOrNull($filters['financial_entry'] ?? null),
                'bank_transaction' => $this->stringOrNull($filters['bank_transaction'] ?? null),
            ],
            'bank_transaction_snapshot' => $this->bankSnapshot($bank),
            'payment_snapshot' => $this->paymentSnapshot($payment),
            'payment_allocation_snapshot' => $this->allocationSnapshot($allocation),
            'invoice_snapshot' => $invoice ? $this->invoiceSnapshot($invoice) : ['invoice_id' => null],
            'financial_entry_snapshot' => $this->financialEntrySnapshot($entry),
            'reconciliation_snapshot' => [
                'mapa_conciliacao' => $reconciliations->map(fn (MapaConciliacao $map): array => $this->reconciliationSnapshot($map))->values()->all(),
                'bank_transaction_allocations' => $bankAllocations->map(fn (BankTransactionAllocation $bankAllocation): array => $this->bankAllocationSnapshot($bankAllocation))->values()->all(),
            ],
            'links_discovered' => $links,
            'anomalies' => $anomalies,
            'risk_level' => $riskLevel,
            'can_auto_classify_as_info' => $canAutoClassifyAsInfo,
            'recommended_next_action' => $this->recommendedNextAction($riskLevel, $canAutoClassifyAsInfo),
            'read_only' => true,
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{bank:?BankStatement,payment:?Payment,allocation:?PaymentAllocation,entry:?FinancialEntry,invoice:?Invoice}
     */
    private function resolveEntities(array $filters): array
    {
        $payment = $this->stringOrNull($filters['payment'] ?? null)
            ? Payment::withTrashed()->find($this->stringOrNull($filters['payment']))
            : null;
        $allocation = $this->stringOrNull($filters['allocation'] ?? null)
            ? PaymentAllocation::withTrashed()->find($this->stringOrNull($filters['allocation']))
            : null;
        $entry = $this->stringOrNull($filters['financial_entry'] ?? null)
            ? FinancialEntry::query()->find($this->stringOrNull($filters['financial_entry']))
            : null;
        $bank = $this->stringOrNull($filters['bank_transaction'] ?? null)
            ? BankStatement::query()->find($this->stringOrNull($filters['bank_transaction']))
            : null;

        $payment ??= $allocation?->payment_id ? Payment::withTrashed()->find($allocation->payment_id) : null;
        $payment ??= $entry?->payment_id ? Payment::withTrashed()->find($entry->payment_id) : null;
        $payment ??= $bank?->id ? Payment::withTrashed()->where('bank_statement_id', $bank->id)->first() : null;

        $allocation ??= $entry?->id ? PaymentAllocation::withTrashed()->where('financial_entry_id', $entry->id)->first() : null;
        $allocation ??= $entry?->origem_tipo === 'payment_allocation' && $entry->origem_id
            ? PaymentAllocation::withTrashed()->find($entry->origem_id)
            : null;
        $allocation ??= $payment?->id ? PaymentAllocation::withTrashed()->where('payment_id', $payment->id)->first() : null;

        $entry ??= $allocation?->financial_entry_id ? FinancialEntry::query()->find($allocation->financial_entry_id) : null;
        $entry ??= $allocation?->id ? FinancialEntry::query()->where('origem_tipo', 'payment_allocation')->where('origem_id', $allocation->id)->first() : null;
        $entry ??= $payment?->id ? FinancialEntry::query()->where('payment_id', $payment->id)->first() : null;
        $entry ??= $bank?->id ? FinancialEntry::query()->where('bank_statement_id', $bank->id)->first() : null;

        $bank ??= $payment?->bank_statement_id ? BankStatement::query()->find($payment->bank_statement_id) : null;
        $bank ??= $entry?->bank_statement_id ? BankStatement::query()->find($entry->bank_statement_id) : null;

        $invoice = $allocation?->invoice_id ? Invoice::query()->find($allocation->invoice_id) : null;
        $invoice ??= $entry?->fatura_id ? Invoice::query()->find($entry->fatura_id) : null;

        return compact('bank', 'payment', 'allocation', 'entry', 'invoice');
    }

    private function relatedReconciliations(?BankStatement $bank, ?Payment $payment, ?PaymentAllocation $allocation, ?FinancialEntry $entry)
    {
        return MapaConciliacao::query()
            ->where(function ($query) use ($bank, $payment, $allocation, $entry): void {
                $query->when($bank?->id, fn ($q) => $q->orWhere('extrato_id', $bank->id))
                    ->when($payment?->id, fn ($q) => $q->orWhere('payment_id', $payment->id))
                    ->when($allocation?->id, fn ($q) => $q->orWhere('payment_allocation_id', $allocation->id))
                    ->when($entry?->id, fn ($q) => $q->orWhere('lancamento_id', $entry->id));
            })
            ->get();
    }

    private function relatedBankAllocations(?BankStatement $bank, ?Payment $payment, ?PaymentAllocation $allocation, ?Invoice $invoice)
    {
        return BankTransactionAllocation::query()
            ->where(function ($query) use ($bank, $payment, $allocation, $invoice): void {
                $query->when($bank?->id, fn ($q) => $q->orWhere('bank_statement_id', $bank->id))
                    ->when($payment?->id, fn ($q) => $q->orWhere('payment_id', $payment->id))
                    ->when($allocation?->id, fn ($q) => $q->orWhere('payment_allocation_id', $allocation->id))
                    ->when($invoice?->id, fn ($q) => $q->orWhere('invoice_id', $invoice->id));
            })
            ->get();
    }

    /**
     * @return array<int,string>
     */
    private function anomalies(?BankStatement $bank, ?Payment $payment, ?PaymentAllocation $allocation, ?FinancialEntry $entry, ?Invoice $invoice): array
    {
        $anomalies = [];
        $entryDate = $this->dateValue($entry?->data);
        $bankDate = $this->dateValue($bank?->data_movimento);
        $paymentDate = $this->dateValue($payment?->payment_date);
        $allocationDate = $this->dateValue($allocation?->allocated_at);

        if ($entryDate && $bankDate && $entryDate->lt($bankDate)) {
            $anomalies[] = 'financial_entry_date_before_bank_date';
        }

        if ($entryDate && $paymentDate && $entryDate->lt($paymentDate)) {
            $anomalies[] = 'financial_entry_date_before_payment_date';
        }

        if ($entryDate && $allocationDate && $entryDate->lt($allocationDate)) {
            $anomalies[] = 'financial_entry_date_before_allocation_date';
        }

        if ($allocation && $allocation->invoice_id === null) {
            $anomalies[] = 'missing_invoice_for_allocation';
        }

        if ($payment && $allocation && $allocation->invoice_id === null) {
            $anomalies[] = 'payment_without_invoice_allocation';
        }

        if ($this->nonInvoiceMovement($entry, $allocation, $invoice)) {
            $anomalies[] = 'movement_outside_invoice_domain';
        }

        if (! $this->amountsCoherent($bank, $payment, $allocation, $entry)) {
            $anomalies[] = 'amount_mismatch';
        }

        return array_values(array_unique($anomalies));
    }

    private function riskLevel(array $anomalies, bool $amountsCoherent, bool $canAutoClassifyAsInfo): string
    {
        if (! $amountsCoherent) {
            return 'high';
        }

        if ($canAutoClassifyAsInfo) {
            return 'low';
        }

        if (in_array('missing_invoice_for_allocation', $anomalies, true) || in_array('payment_without_invoice_allocation', $anomalies, true)) {
            return 'medium';
        }

        return $anomalies === [] ? 'low' : 'medium';
    }

    private function recommendedNextAction(string $riskLevel, bool $canAutoClassifyAsInfo): string
    {
        if ($canAutoClassifyAsInfo) {
            return 'classify_as_economic_date_if_source_is_non_invoice_movement';
        }

        if ($riskLevel === 'low') {
            return 'no_action_needed';
        }

        if ($riskLevel === 'medium') {
            return 'keep_warning_pending_manual_review';
        }

        return 'create_targeted_fix_only_if_operationally_confirmed';
    }

    private function nonInvoiceMovement(?FinancialEntry $entry, ?PaymentAllocation $allocation, ?Invoice $invoice): bool
    {
        if (! $entry || $invoice || $entry->fatura_id !== null || ($allocation && $allocation->invoice_id !== null)) {
            return false;
        }

        $text = mb_strtolower(implode(' ', array_filter([
            (string) $entry->origem_tipo,
            (string) $entry->origem_modulo,
            (string) $entry->categoria,
            (string) $entry->descricao,
        ])));

        foreach (['movimento', 'movement', 'tesouraria', 'patrocinio', 'sponsorship', 'donativo', 'subsidio', 'quota extra', 'receita avulsa'] as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function amountsCoherent(?BankStatement $bank, ?Payment $payment, ?PaymentAllocation $allocation, ?FinancialEntry $entry): bool
    {
        $amounts = array_filter([
            $bank ? abs($this->money($bank->valor)) : null,
            $payment ? abs($this->money($payment->amount)) : null,
            $allocation ? abs($this->money($allocation->amount)) : null,
            $entry ? abs($this->money($entry->valor)) : null,
        ], static fn (?float $value): bool => $value !== null && $value > 0);

        if (count($amounts) < 2) {
            return true;
        }

        $first = array_values($amounts)[0];

        foreach ($amounts as $amount) {
            if (abs($amount - $first) > 0.01) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $reconciliations
     * @param mixed $bankAllocations
     * @return array<string,mixed>
     */
    private function links(?BankStatement $bank, ?Payment $payment, ?PaymentAllocation $allocation, ?FinancialEntry $entry, ?Invoice $invoice, $reconciliations, $bankAllocations): array
    {
        return [
            'bank_to_payment' => $bank && $payment ? $payment->bank_statement_id === $bank->id : false,
            'payment_to_allocation' => $payment && $allocation ? $allocation->payment_id === $payment->id : false,
            'allocation_to_financial_entry' => $allocation && $entry ? $allocation->financial_entry_id === $entry->id : false,
            'financial_entry_to_allocation_origin' => $allocation && $entry ? ((string) $entry->origem_tipo === 'payment_allocation' && (string) $entry->origem_id === (string) $allocation->id) : false,
            'allocation_to_invoice' => $allocation && $invoice ? $allocation->invoice_id === $invoice->id : false,
            'financial_entry_to_invoice' => $entry && $invoice ? $entry->fatura_id === $invoice->id : false,
            'reconciliation_ids' => $reconciliations->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
            'bank_transaction_allocation_ids' => $bankAllocations->pluck('id')->map(fn (mixed $id): string => (string) $id)->values()->all(),
        ];
    }

    private function bankSnapshot(?BankStatement $bank): ?array
    {
        return $bank ? [
            'id' => (string) $bank->id,
            'data_movimento' => $this->dateString($bank->data_movimento),
            'amount' => $this->money($bank->valor),
            'description' => $bank->descricao,
            'reference' => $bank->referencia,
            'type' => $bank->getAttribute('tipo'),
            'direction' => $bank->getAttribute('direcao'),
            'status' => $bank->conciliacao_status,
            'created_at' => $this->dateTimeString($bank->created_at),
            'updated_at' => $this->dateTimeString($bank->updated_at),
            'deleted_at' => $this->dateTimeString($bank->getAttribute('deleted_at')),
        ] : null;
    }

    private function paymentSnapshot(?Payment $payment): ?array
    {
        return $payment ? [
            'id' => (string) $payment->id,
            'amount' => $this->money($payment->amount),
            'allocated_amount' => $this->money($payment->allocated_amount),
            'unallocated_amount' => $this->money($payment->unallocated_amount),
            'payment_date' => $this->dateString($payment->payment_date),
            'method' => $payment->method,
            'source' => $payment->source,
            'status' => $payment->status,
            'bank_statement_id' => $payment->bank_statement_id ? (string) $payment->bank_statement_id : null,
            'created_at' => $this->dateTimeString($payment->created_at),
            'updated_at' => $this->dateTimeString($payment->updated_at),
            'deleted_at' => $this->dateTimeString($payment->deleted_at),
            'cancelled_at' => $this->dateTimeString($payment->cancelled_at),
        ] : null;
    }

    private function allocationSnapshot(?PaymentAllocation $allocation): ?array
    {
        return $allocation ? [
            'id' => (string) $allocation->id,
            'payment_id' => $allocation->payment_id ? (string) $allocation->payment_id : null,
            'invoice_id' => $allocation->invoice_id ? (string) $allocation->invoice_id : null,
            'financial_entry_id' => $allocation->financial_entry_id ? (string) $allocation->financial_entry_id : null,
            'amount' => $this->money($allocation->amount),
            'status' => $allocation->status,
            'allocated_at' => $this->dateTimeString($allocation->allocated_at),
            'created_at' => $this->dateTimeString($allocation->created_at),
            'updated_at' => $this->dateTimeString($allocation->updated_at),
            'deleted_at' => $this->dateTimeString($allocation->deleted_at),
        ] : null;
    }

    private function invoiceSnapshot(Invoice $invoice): array
    {
        return [
            'id' => (string) $invoice->id,
            'tipo' => $invoice->tipo,
            'origem_tipo' => $invoice->origem_tipo,
            'origem_id' => $invoice->origem_id ? (string) $invoice->origem_id : null,
            'valor_total' => $this->money($invoice->valor_total),
            'valor_pago' => $this->money($invoice->valor_pago),
            'valor_em_aberto' => $this->money($invoice->valor_em_aberto),
            'estado_pagamento' => $invoice->estado_pagamento,
            'data_emissao' => $this->dateString($invoice->data_emissao),
            'data_vencimento' => $this->dateString($invoice->data_vencimento),
            'created_at' => $this->dateTimeString($invoice->created_at),
            'updated_at' => $this->dateTimeString($invoice->updated_at),
            'deleted_at' => $this->dateTimeString($invoice->getAttribute('deleted_at')),
        ];
    }

    private function financialEntrySnapshot(?FinancialEntry $entry): ?array
    {
        return $entry ? [
            'id' => (string) $entry->id,
            'origem_tipo' => $entry->origem_tipo,
            'origem_modulo' => $entry->origem_modulo,
            'origem_id' => $entry->origem_id ? (string) $entry->origem_id : null,
            'amount' => $this->money($entry->valor),
            'direction' => $entry->getAttribute('direcao'),
            'type' => $entry->tipo,
            'category' => $entry->categoria,
            'data' => $this->dateString($entry->data),
            'data_pagamento' => $this->dateString($entry->data_pagamento),
            'data_liquidacao' => $this->dateString($entry->data_liquidacao),
            'created_at' => $this->dateTimeString($entry->created_at),
            'updated_at' => $this->dateTimeString($entry->updated_at),
            'deleted_at' => $this->dateTimeString($entry->getAttribute('deleted_at')),
        ] : null;
    }

    private function reconciliationSnapshot(MapaConciliacao $map): array
    {
        return [
            'id' => (string) $map->id,
            'extrato_id' => $map->extrato_id ? (string) $map->extrato_id : null,
            'lancamento_id' => $map->lancamento_id ? (string) $map->lancamento_id : null,
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
            'valor_alocado' => $this->money($allocation->valor_alocado),
            'status' => $allocation->status,
            'origem' => $allocation->origem,
            'committed_at' => $this->dateTimeString($allocation->committed_at),
            'created_at' => $this->dateTimeString($allocation->created_at),
            'updated_at' => $this->dateTimeString($allocation->updated_at),
        ];
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

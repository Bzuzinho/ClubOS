<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankTransactionAllocation;
use App\Models\AccountCreditUsage;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MapaConciliacao;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class InvoiceObligationAuditService
{
    private const VERSION = 'a3-3-invoice-obligation-audit-v1';
    private const TOLERANCE = 0.01;
    private const MONTHLY_FEE_ORIGIN = 'monthly_fee';
    private const LEGACY_MONTHLY_FEE_ORIGIN = 'monthly_fee_legacy';

    public function __construct(
        private readonly InvoiceFinancialGuardService $guardService,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $filters = $this->filters($options);
        $invoices = $this->invoices($filters);
        $knownTypes = InvoiceType::query()
            ->pluck('codigo')
            ->filter()
            ->map(static fn (mixed $value): string => (string) $value)
            ->all();

        $diagnostics = [];
        $findings = [];

        foreach ($invoices as $invoice) {
            $diagnostic = $this->diagnostic($invoice, $knownTypes);
            $diagnostics[(string) $invoice->id] = $diagnostic;

            array_push($findings, ...$this->invoiceFindings($diagnostic));
        }

        array_push($findings, ...$this->duplicateFindings($invoices, $diagnostics));

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'summary' => $this->summary($invoices, $findings),
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function filters(array $options): array
    {
        return [
            'from' => $this->normalizeNullableString($options['from'] ?? null),
            'to' => $this->normalizeNullableString($options['to'] ?? null),
            'invoice' => $this->normalizeNullableString($options['invoice'] ?? null),
            'user' => $this->normalizeNullableString($options['user'] ?? null),
            'type' => $this->normalizeNullableString($options['type'] ?? null),
            'only_open' => (bool) ($options['only_open'] ?? false),
            'include_cancelled' => (bool) ($options['include_cancelled'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,Invoice>
     */
    private function invoices(array $filters): Collection
    {
        $query = Invoice::query()
            ->with(['items', 'user'])
            ->orderBy('user_id')
            ->orderBy('data_emissao')
            ->orderBy('id');

        if (! $filters['include_cancelled']) {
            $query->where('estado_pagamento', '!=', 'cancelado');
        }

        if ($filters['invoice']) {
            $query->whereKey($filters['invoice']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['type']) {
            $query->where('tipo', $filters['type']);
        }

        if ($filters['only_open']) {
            $query->where(function (Builder $query): void {
                $query->where('valor_em_aberto', '>', self::TOLERANCE)
                    ->orWhereIn('estado_pagamento', ['pendente', 'vencido', 'parcial']);
            });
        }

        if ($filters['from']) {
            $from = Carbon::parse((string) $filters['from'])->toDateString();
            $query->where(function (Builder $query) use ($from): void {
                $query->whereDate('data_emissao', '>=', $from)
                    ->orWhereDate('data_vencimento', '>=', $from);
            });
        }

        if ($filters['to']) {
            $to = Carbon::parse((string) $filters['to'])->toDateString();
            $query->where(function (Builder $query) use ($to): void {
                $query->whereDate('data_emissao', '<=', $to)
                    ->orWhereDate('data_vencimento', '<=', $to);
            });
        }

        return $query->get();
    }

    /**
     * @param list<string> $knownTypes
     * @return array<string,mixed>
     */
    private function diagnostic(Invoice $invoice, array $knownTypes): array
    {
        $items = $invoice->items;
        $itemSum = $this->roundMoney($items->sum(static fn ($item): float => (float) $item->total_linha));
        $valorTotal = $this->roundMoney((float) $invoice->valor_total);
        $valorPago = $this->roundMoney((float) ($invoice->valor_pago ?? 0));
        $valorAberto = $this->roundMoney((float) ($invoice->valor_em_aberto ?? 0));
        $computedOpen = $this->roundMoney($valorTotal - $valorPago);
        $protectionReasons = $this->guardService->trailReasons($invoice);
        $trail = $this->trail($invoice);

        return [
            'invoice' => $invoice,
            'invoice_id' => (string) $invoice->id,
            'user_id' => $invoice->user_id ? (string) $invoice->user_id : null,
            'tipo' => (string) $invoice->tipo,
            'mes' => $this->normalizeNullableString($invoice->mes),
            'estado_pagamento' => (string) $invoice->estado_pagamento,
            'valor_total' => $valorTotal,
            'valor_pago' => $valorPago,
            'valor_em_aberto' => $valorAberto,
            'computed_open_amount' => $computedOpen,
            'item_sum' => $itemSum,
            'data_emissao' => $invoice->data_emissao?->toDateString(),
            'data_fatura' => $invoice->data_fatura?->toDateString(),
            'data_vencimento' => $invoice->data_vencimento?->toDateString(),
            'data_pagamento' => $invoice->data_pagamento?->toDateString(),
            'numero_recibo' => $this->normalizeNullableString($invoice->numero_recibo),
            'origem_tipo' => $this->normalizeNullableString($invoice->origem_tipo),
            'origem_id' => $this->normalizeNullableString($invoice->origem_id),
            'items_count' => $items->count(),
            'known_type' => in_array((string) $invoice->tipo, $knownTypes, true),
            'trail_flags' => $trail,
            'protected' => $protectionReasons !== [],
            'protection_reasons' => $protectionReasons,
            'line_diagnostics' => $this->lineDiagnostics($invoice),
            'origin_reference_exists' => $this->originReferenceExists($invoice),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function trail(Invoice $invoice): array
    {
        $allocations = PaymentAllocation::withTrashed()->where('invoice_id', $invoice->id)->get();
        $confirmedAllocations = $allocations->where('status', PaymentAllocation::STATUS_CONFIRMED);
        $creditUsages = AccountCreditUsage::withTrashed()->where('invoice_id', $invoice->id)->get();
        $appliedCreditUsages = $creditUsages
            ->where('status', AccountCreditUsage::STATUS_APPLIED)
            ->whereNull('deleted_at');
        $financialEntries = FinancialEntry::query()
            ->where(function (Builder $query) use ($invoice): void {
                $query->where('fatura_id', $invoice->id)
                    ->orWhere(function (Builder $query) use ($invoice): void {
                        $query->where('origem_tipo', 'payment_allocation')
                            ->whereIn('origem_id', PaymentAllocation::withTrashed()
                                ->where('invoice_id', $invoice->id)
                                ->pluck('id')
                                ->all());
                    });
            })
            ->get();
        $mapaCount = MapaConciliacao::query()->where('fatura_id', $invoice->id)->count();
        $bankAllocations = BankTransactionAllocation::query()->where('invoice_id', $invoice->id)->get();
        $fiscalRequests = FiscalDocumentRequest::withTrashed()->where('invoice_id', $invoice->id)->get();
        $archivedStaleFiscalRequests = $fiscalRequests->filter(
            fn (FiscalDocumentRequest $request): bool => $this->isArchivedStaleFiscalRequest($request),
        );
        $nonArchivedFiscalRequests = $fiscalRequests->reject(
            fn (FiscalDocumentRequest $request): bool => $this->isArchivedStaleFiscalRequest($request),
        );

        return [
            'payment_allocation_count' => $allocations->count(),
            'confirmed_payment_allocation_count' => $confirmedAllocations->count(),
            'payment_allocation_sum' => $this->roundMoney($confirmedAllocations->sum(static fn (PaymentAllocation $allocation): float => (float) $allocation->amount)),
            'account_credit_usage_count' => $creditUsages->count(),
            'applied_account_credit_usage_count' => $appliedCreditUsages->count(),
            'account_credit_usage_sum' => $this->roundMoney($appliedCreditUsages->sum(static fn (AccountCreditUsage $usage): float => (float) $usage->amount)),
            'payment_count' => $invoice->payments()->count(),
            'financial_entry_count' => $financialEntries->count(),
            'mapa_conciliacao_count' => $mapaCount,
            'bank_transaction_allocation_count' => $bankAllocations->count(),
            'bank_allocation_without_payment_allocation' => $bankAllocations
                ->contains(static fn (BankTransactionAllocation $allocation): bool => $allocation->payment_allocation_id === null),
            'reconciliation_without_payment_allocation' => MapaConciliacao::query()
                ->where('fatura_id', $invoice->id)
                ->whereNull('payment_allocation_id')
                ->exists(),
            'fiscal_document_request_count' => $fiscalRequests->count(),
            'fiscal_document_request_deleted_count' => $fiscalRequests->whereNotNull('deleted_at')->count(),
            'has_fiscal_request' => $fiscalRequests->isNotEmpty(),
            'archived_stale_fiscal_request_count' => $archivedStaleFiscalRequests->count(),
            'has_archived_stale_fiscal_request' => $archivedStaleFiscalRequests->isNotEmpty(),
            'has_non_archived_fiscal_request' => $nonArchivedFiscalRequests->isNotEmpty(),
            'has_issued_fiscal_request' => $fiscalRequests->contains(static fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_ISSUED),
            'has_pending_fiscal_request' => $nonArchivedFiscalRequests->contains(static fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_PENDING),
            'external_document_numbers' => $nonArchivedFiscalRequests
                ->pluck('external_document_number')
                ->filter()
                ->map(static fn (mixed $value): string => (string) $value)
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function lineDiagnostics(Invoice $invoice): array
    {
        return $invoice->items
            ->map(function ($item): array {
                $expected = $this->roundMoney(
                    (float) $item->quantidade
                    * (float) $item->valor_unitario
                    * (1 + ((float) $item->imposto_percentual / 100))
                );
                $actual = $this->roundMoney((float) $item->total_linha);

                return [
                    'item_id' => (string) $item->id,
                    'descricao' => (string) $item->descricao,
                    'expected_total_linha' => $expected,
                    'actual_total_linha' => $actual,
                    'difference' => $this->roundMoney($actual - $expected),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @return list<array<string,mixed>>
     */
    private function invoiceFindings(array $diagnostic): array
    {
        $findings = [];

        $this->checkTotalsAndItems($diagnostic, $findings);
        $this->checkFinancialState($diagnostic, $findings);
        $this->checkTrail($diagnostic, $findings);
        $this->checkOriginAndType($diagnostic, $findings);
        $this->checkDates($diagnostic, $findings);

        return $findings;
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @param list<array<string,mixed>> $findings
     */
    private function checkTotalsAndItems(array $diagnostic, array &$findings): void
    {
        if ((int) $diagnostic['items_count'] === 0 && $diagnostic['valor_total'] > self::TOLERANCE && ! $this->isAdjustmentOrLegacyType($diagnostic['tipo'])) {
            $findings[] = $this->finding('invoice_without_items', $diagnostic, 'critical', 'review_invoice_items_before_any_settlement_or_fiscal_action');
        }

        if (abs($diagnostic['valor_total'] - $diagnostic['item_sum']) > self::TOLERANCE) {
            $findings[] = $this->finding('invoice_total_differs_from_items_sum', $diagnostic, 'critical', 'review_invoice_total_against_item_lines');
        }

        foreach ($diagnostic['line_diagnostics'] as $line) {
            if (abs((float) $line['difference']) > self::TOLERANCE) {
                $findings[] = $this->finding(
                    'invoice_item_line_total_differs',
                    $diagnostic,
                    abs((float) $line['difference']) > 1.0 ? 'critical' : 'warning',
                    'review_item_quantity_unit_price_tax_and_line_total',
                    ['line' => $line],
                );
            }
        }

        if ($diagnostic['valor_total'] < 0 && ! $this->isCreditType($diagnostic['tipo'])) {
            $findings[] = $this->finding('invoice_negative_or_invalid_amount', $diagnostic, 'warning', 'review_negative_invoice_amount_semantics');
        }

        if (abs($diagnostic['valor_total']) <= self::TOLERANCE && $diagnostic['valor_em_aberto'] > self::TOLERANCE) {
            $findings[] = $this->finding('invoice_zero_total_with_open_amount', $diagnostic, 'critical', 'review_zero_total_invoice_open_amount');
        }
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @param list<array<string,mixed>> $findings
     */
    private function checkFinancialState(array $diagnostic, array &$findings): void
    {
        if ($diagnostic['estado_pagamento'] !== 'cancelado' && abs($diagnostic['valor_em_aberto'] - $diagnostic['computed_open_amount']) > self::TOLERANCE) {
            $findings[] = $this->finding('open_amount_inconsistent', $diagnostic, 'critical', 'review_invoice_open_amount_formula');
        }

        if ($diagnostic['valor_pago'] - $diagnostic['valor_total'] > self::TOLERANCE) {
            $findings[] = $this->finding('paid_amount_exceeds_total', $diagnostic, 'critical', 'review_paid_amount_before_reporting_or_fiscal_action');
        }

        if ($diagnostic['estado_pagamento'] === 'pago' && $diagnostic['valor_em_aberto'] > self::TOLERANCE) {
            $findings[] = $this->finding('paid_invoice_with_open_amount', $diagnostic, 'critical', 'review_paid_state_and_open_amount');
        }

        if (in_array($diagnostic['estado_pagamento'], ['pendente', 'vencido'], true) && $diagnostic['valor_pago'] + self::TOLERANCE >= $diagnostic['valor_total'] && $diagnostic['valor_total'] > self::TOLERANCE) {
            $findings[] = $this->finding('pending_invoice_with_full_payment', $diagnostic, 'critical', 'review_payment_state_recalculation');
        }

        if ($diagnostic['estado_pagamento'] === 'parcial' && ($diagnostic['valor_pago'] <= self::TOLERANCE || $diagnostic['valor_em_aberto'] <= self::TOLERANCE)) {
            $findings[] = $this->finding('partial_invoice_without_partial_amount', $diagnostic, 'warning', 'review_partial_state_amounts');
        }

        if ($diagnostic['estado_pagamento'] === 'cancelado' && $diagnostic['valor_em_aberto'] > self::TOLERANCE) {
            $findings[] = $this->finding('cancelled_invoice_with_open_amount', $diagnostic, 'critical', 'review_cancelled_invoice_amounts');
        }

        if ($diagnostic['estado_pagamento'] === 'cancelado' && $diagnostic['valor_pago'] > self::TOLERANCE && ! $diagnostic['protected']) {
            $findings[] = $this->finding('cancelled_invoice_with_paid_amount_without_trail', $diagnostic, 'critical', 'review_cancelled_paid_invoice_without_financial_trail');
        }

        if ($diagnostic['estado_pagamento'] === 'pago' && $diagnostic['data_pagamento'] === null) {
            $findings[] = $this->finding('payment_date_missing_for_paid_invoice', $diagnostic, 'warning', 'review_missing_payment_date');
        }

        if ($diagnostic['estado_pagamento'] !== 'pago' && $diagnostic['data_pagamento'] !== null) {
            $findings[] = $this->finding('payment_date_present_for_unpaid_invoice', $diagnostic, 'warning', 'review_payment_date_for_non_paid_state');
        }
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @param list<array<string,mixed>> $findings
     */
    private function checkTrail(array $diagnostic, array &$findings): void
    {
        $trail = $diagnostic['trail_flags'];

        $settledByCanonicalTrail = $this->roundMoney((float) $trail['payment_allocation_sum'] + (float) ($trail['account_credit_usage_sum'] ?? 0));

        if (abs($settledByCanonicalTrail - (float) $diagnostic['valor_pago']) > self::TOLERANCE) {
            $findings[] = $this->finding('payment_allocation_sum_differs_from_valor_pago', $diagnostic, 'warning', 'review_payment_allocations_against_invoice_paid_amount');
        }

        if (((int) $trail['confirmed_payment_allocation_count'] > 0 || (int) ($trail['applied_account_credit_usage_count'] ?? 0) > 0) && ! in_array($diagnostic['estado_pagamento'], ['pago', 'parcial'], true)) {
            $findings[] = $this->finding('confirmed_allocations_without_paid_state', $diagnostic, 'critical', 'review_invoice_state_against_confirmed_allocations');
        }

        if (in_array($diagnostic['estado_pagamento'], ['pago', 'parcial'], true) && ! $diagnostic['protected']) {
            $findings[] = $this->finding('paid_state_without_financial_trail', $diagnostic, 'critical', 'review_paid_or_partial_invoice_without_financial_trail');
        }

        if ($diagnostic['estado_pagamento'] === 'cancelado' && $diagnostic['protected']) {
            $findings[] = $this->finding('financial_trail_on_cancelled_invoice', $diagnostic, 'warning', 'review_cancelled_invoice_financial_or_fiscal_trail');
        }

        if ((bool) $trail['bank_allocation_without_payment_allocation']) {
            $findings[] = $this->finding('bank_allocation_without_payment_allocation', $diagnostic, 'critical', 'review_bank_allocation_missing_payment_allocation');
        }

        if ((bool) $trail['reconciliation_without_payment_allocation']) {
            $findings[] = $this->finding('reconciliation_without_payment_allocation', $diagnostic, 'critical', 'review_reconciliation_missing_payment_allocation');
        }

        if ($diagnostic['numero_recibo'] !== null && ! (bool) $trail['has_fiscal_request']) {
            $findings[] = $this->finding('receipt_number_without_fiscal_request', $diagnostic, 'warning', 'review_receipt_number_without_fiscal_request');
        }

        if ((bool) $trail['has_non_archived_fiscal_request'] && $diagnostic['estado_pagamento'] !== 'pago') {
            $findings[] = $this->finding('fiscal_request_without_invoice_paid', $diagnostic, 'warning', 'review_fiscal_request_for_non_paid_invoice');
        }

        if ((bool) $trail['has_archived_stale_fiscal_request']) {
            $findings[] = $this->finding('stale_fiscal_request_archived', $diagnostic, 'info', 'no_action_needed_stale_fiscal_request_archived');
        }

        if ($diagnostic['numero_recibo'] === null && $trail['external_document_numbers'] !== []) {
            $findings[] = $this->finding('external_fiscal_document_without_receipt_number', $diagnostic, 'critical', 'review_external_fiscal_document_snapshot_against_invoice_receipt_number');
        }

        if ($diagnostic['numero_recibo'] !== null && $trail['external_document_numbers'] !== [] && ! in_array($diagnostic['numero_recibo'], $trail['external_document_numbers'], true)) {
            $findings[] = $this->finding('receipt_number_differs_from_external_document', $diagnostic, 'critical', 'review_invoice_receipt_number_against_external_fiscal_document');
        }

        if ((int) $trail['fiscal_document_request_deleted_count'] > 0 && $diagnostic['numero_recibo'] !== null) {
            $findings[] = $this->finding('fiscal_request_deleted_but_invoice_keeps_receipt', $diagnostic, 'critical', 'review_deleted_fiscal_request_with_invoice_receipt_number');
        }

        if ((bool) $trail['has_pending_fiscal_request'] && $diagnostic['estado_pagamento'] !== 'pago') {
            $findings[] = $this->finding('fiscal_request_pending_for_unpaid_invoice', $diagnostic, 'warning', 'review_pending_fiscal_request_for_unpaid_invoice');
        }
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @param list<array<string,mixed>> $findings
     */
    private function checkOriginAndType(array $diagnostic, array &$findings): void
    {
        if ($diagnostic['tipo'] === '') {
            $findings[] = $this->finding('invoice_type_missing', $diagnostic, 'critical', 'review_missing_invoice_type');
        } elseif (! $diagnostic['known_type']) {
            $findings[] = $this->finding('invoice_type_unknown', $diagnostic, 'warning', 'review_invoice_type_configuration');
        }

        if ($diagnostic['tipo'] === 'mensalidade' && $diagnostic['mes'] === null) {
            $findings[] = $this->finding('monthly_invoice_without_month', $diagnostic, 'critical', 'review_monthly_invoice_period');
        }

        if ($diagnostic['tipo'] === 'mensalidade' && $diagnostic['origem_tipo'] === self::LEGACY_MONTHLY_FEE_ORIGIN) {
            $findings[] = $this->finding('monthly_invoice_legacy_classified', $diagnostic, 'info', 'no_action_needed_legacy_monthly_invoice_classified');
        } elseif ($diagnostic['tipo'] === 'mensalidade' && $diagnostic['origem_tipo'] !== self::MONTHLY_FEE_ORIGIN) {
            $findings[] = $this->finding('monthly_invoice_without_canonical_origin', $diagnostic, 'warning', 'review_monthly_invoice_origin_before_reconciliation');
        }

        if ($diagnostic['tipo'] === 'mensalidade' && in_array($diagnostic['origem_tipo'], ['manual', null], true)) {
            $findings[] = $this->finding('manual_invoice_with_monthly_type', $diagnostic, 'warning', 'review_monthly_invoice_created_outside_canonical_engine');
        }

        if ($diagnostic['origem_tipo'] === null) {
            $findings[] = $this->finding('invoice_origin_missing', $diagnostic, $diagnostic['tipo'] === 'mensalidade' ? 'warning' : 'info', 'review_legacy_invoice_without_origin');
        } elseif ($diagnostic['origem_tipo'] !== 'manual' && $diagnostic['origem_tipo'] !== self::LEGACY_MONTHLY_FEE_ORIGIN && $diagnostic['origem_id'] === null) {
            $findings[] = $this->finding('invoice_origin_reference_missing', $diagnostic, 'warning', 'review_invoice_origin_without_reference');
        } elseif ($diagnostic['origem_tipo'] !== 'manual' && $diagnostic['origin_reference_exists'] === false) {
            $findings[] = $this->finding('invoice_origin_reference_not_found', $diagnostic, 'warning', 'review_missing_origin_reference');
        }
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @param list<array<string,mixed>> $findings
     */
    private function checkDates(array $diagnostic, array &$findings): void
    {
        if ($diagnostic['data_emissao'] !== null && $diagnostic['data_vencimento'] !== null && $diagnostic['data_vencimento'] < $diagnostic['data_emissao']) {
            $findings[] = $this->finding('due_date_before_issue_date', $diagnostic, 'warning', 'review_invoice_due_date_before_issue_date');
        }

        if ($diagnostic['data_emissao'] === null && $diagnostic['data_fatura'] === null) {
            $findings[] = $this->finding('invoice_date_missing', $diagnostic, 'warning', 'review_missing_invoice_issue_date');
        }

        if ($diagnostic['data_vencimento'] === null) {
            $findings[] = $this->finding('due_date_missing', $diagnostic, 'warning', 'review_missing_due_date');
        }

        if ($diagnostic['mes'] !== null && ! preg_match('/^\d{4}-\d{2}$/', (string) $diagnostic['mes'])) {
            $findings[] = $this->finding('month_format_invalid', $diagnostic, 'warning', 'review_month_format_as_year_month');
        }

        if ($diagnostic['tipo'] === 'mensalidade' && $diagnostic['mes'] !== null && preg_match('/^\d{4}-\d{2}$/', (string) $diagnostic['mes']) && $diagnostic['data_emissao'] !== null) {
            $issueMonth = substr((string) $diagnostic['data_emissao'], 0, 7);
            if ($issueMonth !== $diagnostic['mes']) {
                $findings[] = $this->finding('monthly_invoice_month_mismatch_issue_date', $diagnostic, 'info', 'review_monthly_invoice_issue_month_snapshot');
            }
        }
    }

    /**
     * @param Collection<int,Invoice> $invoices
     * @param array<string,array<string,mixed>> $diagnostics
     * @return list<array<string,mixed>>
     */
    private function duplicateFindings(Collection $invoices, array $diagnostics): array
    {
        $findings = [];
        $active = $invoices->filter(static fn (Invoice $invoice): bool => $invoice->estado_pagamento !== 'cancelado');

        $active
            ->where('tipo', 'mensalidade')
            ->groupBy(static fn (Invoice $invoice): string => (string) $invoice->user_id . '|' . (string) $invoice->mes . '|' . (string) $invoice->tipo)
            ->filter(static fn (Collection $group, string $key): bool => ! str_contains($key, '||') && $group->count() > 1)
            ->each(function (Collection $group) use (&$findings, $diagnostics): void {
                $base = $diagnostics[(string) $group->first()->id] ?? null;
                if (! is_array($base)) {
                    return;
                }

                $findings[] = $this->finding('duplicate_active_monthly_invoice', $base, 'critical', 'review_duplicate_active_monthly_period', [
                    'duplicate_invoice_ids' => $group->pluck('id')->map('strval')->values()->all(),
                ]);
            });

        $active
            ->groupBy(static fn (Invoice $invoice): string => implode('|', [
                (string) $invoice->user_id,
                (string) $invoice->tipo,
                (string) $invoice->mes,
                number_format((float) $invoice->valor_total, 2, '.', ''),
                optional($invoice->data_vencimento)->toDateString() ?? '',
            ]))
            ->filter(static fn (Collection $group): bool => $group->count() > 1)
            ->each(function (Collection $group) use (&$findings, $diagnostics): void {
                $base = $diagnostics[(string) $group->first()->id] ?? null;
                if (! is_array($base)) {
                    return;
                }

                $findings[] = $this->finding('duplicate_active_invoice_same_signature', $base, 'warning', 'review_possible_duplicate_invoice_signature', [
                    'duplicate_invoice_ids' => $group->pluck('id')->map('strval')->values()->all(),
                ]);
            });

        return $findings;
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function finding(string $code, array $diagnostic, string $severity, string $recommendation, array $extra = []): array
    {
        return array_merge([
            'severity' => $severity,
            'code' => $code,
            'invoice_id' => $diagnostic['invoice_id'],
            'user_id' => $diagnostic['user_id'],
            'tipo' => $diagnostic['tipo'],
            'mes' => $diagnostic['mes'],
            'estado_pagamento' => $diagnostic['estado_pagamento'],
            'valor_total' => $diagnostic['valor_total'],
            'valor_pago' => $diagnostic['valor_pago'],
            'valor_em_aberto' => $diagnostic['valor_em_aberto'],
            'computed_open_amount' => $diagnostic['computed_open_amount'],
            'item_sum' => $diagnostic['item_sum'],
            'trail_flags' => $diagnostic['trail_flags'],
            'recommendation' => $recommendation,
            'protected' => $diagnostic['protected'],
            'protection_reasons' => $diagnostic['protection_reasons'],
        ], $extra);
    }

    /**
     * @param Collection<int,Invoice> $invoices
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function summary(Collection $invoices, array $findings): array
    {
        $active = $invoices->where('estado_pagamento', '!=', 'cancelado');
        $cancelled = $invoices->where('estado_pagamento', 'cancelado');
        $invoiceIdsWithFindings = collect($findings)->pluck('invoice_id')->unique();

        return [
            'total_invoices_scanned' => $invoices->count(),
            'total_active_invoices' => $active->count(),
            'total_cancelled_invoices' => $cancelled->count(),
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
            'invoices_with_findings' => $invoiceIdsWithFindings->count(),
            'protected_invoices_with_findings' => collect($findings)
                ->where('protected', true)
                ->pluck('invoice_id')
                ->unique()
                ->count(),
            'unprotected_invoices_with_findings' => collect($findings)
                ->where('protected', false)
                ->pluck('invoice_id')
                ->unique()
                ->count(),
            'total_amount_scanned' => $this->roundMoney($invoices->sum(static fn (Invoice $invoice): float => (float) $invoice->valor_total)),
            'total_open_amount_scanned' => $this->roundMoney($invoices->sum(static fn (Invoice $invoice): float => (float) ($invoice->valor_em_aberto ?? 0))),
            'total_item_sum_scanned' => $this->roundMoney($invoices->sum(static fn (Invoice $invoice): float => $invoice->items->sum(static fn ($item): float => (float) $item->total_linha))),
        ];
    }

    private function originReferenceExists(Invoice $invoice): ?bool
    {
        $originType = $this->normalizeNullableString($invoice->origem_tipo);
        $originId = $this->normalizeNullableString($invoice->origem_id);

        if ($originType === null || $originType === 'manual' || $originId === null) {
            return null;
        }

        $map = [
            'monthly_fee' => \App\Models\MonthlyFee::class,
            'movement' => \App\Models\Movement::class,
            'competition_registration' => \App\Models\CompetitionRegistration::class,
            'convocation_group' => \App\Models\ConvocationGroup::class,
            'logistics_request' => \App\Models\LogisticsRequest::class,
            'supplier_purchase' => \App\Models\SupplierPurchase::class,
            'sponsorship_money_item' => \App\Models\SponsorshipMoneyItem::class,
        ];

        $modelClass = $map[$originType] ?? null;
        if (! is_string($modelClass) || ! class_exists($modelClass)) {
            return null;
        }

        return $modelClass::query()->whereKey($originId)->exists();
    }

    private function isAdjustmentOrLegacyType(string $type): bool
    {
        return in_array($type, ['ajuste', 'legacy', 'legado', 'credito', 'nota_credito', 'credit_note'], true);
    }

    private function isCreditType(string $type): bool
    {
        return in_array($type, ['credito', 'nota_credito', 'credit_note'], true);
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function isArchivedStaleFiscalRequest(FiscalDocumentRequest $request): bool
    {
        return (bool) data_get($request->metadata, 'stale_cleanup') === true
            && in_array(data_get($request->metadata, 'stale_cleanup_version'), ['a3-6', 'a4-6'], true)
            && blank($request->external_document_number)
            && blank($request->external_document_id)
            && $request->issued_at === null;
    }
}

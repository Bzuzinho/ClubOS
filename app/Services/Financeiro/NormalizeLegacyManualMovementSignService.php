<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class NormalizeLegacyManualMovementSignService
{
    public const TARGET_MOVEMENT_ID = 'a1c55e47-bf5f-48b4-a115-e1655dbc7fb2';

    private const TARGET_ABS_VALUE = 1537.50;
    private const EPSILON = 0.01;

    public function __construct(
        private readonly FinancialReportingFactService $financialReportingFactService,
        private readonly FinanceReportService $financeReportService,
        private readonly FinanceDashboardService $financeDashboardService,
        private readonly CurrentAccountService $currentAccountService,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function normalize(string $movementId, bool $dryRun = false): array
    {
        return DB::transaction(function () use ($movementId, $dryRun): array {
            $movement = Movement::query()->whereKey($movementId)->lockForUpdate()->first();

            if (!$movement) {
                throw new RuntimeException(sprintf('Movement [%s] nao encontrado.', $movementId));
            }

            $entry = FinancialEntry::query()
                ->where('origem_tipo', 'movement')
                ->where('origem_id', $movementId)
                ->lockForUpdate()
                ->first();

            $items = MovementItem::query()
                ->where('movimento_id', $movementId)
                ->lockForUpdate()
                ->get();

            $allocations = $entry
                ? PaymentAllocation::query()
                    ->where('financial_entry_id', $entry->id)
                    ->lockForUpdate()
                    ->get()
                : collect();

            $paymentIds = $allocations->pluck('payment_id')->filter()->unique()->values()->all();

            $payments = empty($paymentIds)
                ? collect()
                : Payment::query()->whereIn('id', $paymentIds)->lockForUpdate()->get();

            $reconciliationRows = MapaConciliacao::query()
                ->where(function ($query) use ($entry, $movementId): void {
                    if ($entry) {
                        $query->where('lancamento_id', $entry->id);
                    }

                    $query->orWhere('movimento_id', $movementId);
                })
                ->lockForUpdate()
                ->get();

            $fiscalRows = FiscalDocumentRequest::query()
                ->where(function ($query) use ($entry): void {
                    if ($entry) {
                        $query->where('financial_entry_id', $entry->id);
                    }
                })
                ->get();

            $lifecycleFacts = $this->lifecycleFacts($movement, $entry);
            $guards = $this->buildGuards(
                $movement,
                $entry,
                $items,
                $allocations,
                $payments,
                $reconciliationRows,
                $fiscalRows,
                $lifecycleFacts,
            );

            $failedGuards = collect($guards)
                ->filter(fn (array $guard): bool => ($guard['passed'] ?? false) !== true)
                ->keys()
                ->values()
                ->all();

            if (!empty($failedGuards)) {
                throw new RuntimeException(sprintf(
                    'Normalizacao recusada para movement [%s]. Guards falhadas: %s',
                    $movementId,
                    implode(', ', $failedGuards)
                ));
            }

            $before = $this->buildSnapshot($movement->fresh(), $entry?->fresh(), $items, $allocations, $payments, $lifecycleFacts);
            $beforeAggregates = $this->buildAggregateSnapshot();

            $updates = $this->buildUpdatePlan($movement, $entry, $items);
            $proposedAfter = $this->buildProposedAfter($before, $updates);

            if (!$dryRun) {
                if (array_key_exists('valor_total', $updates['movement'])) {
                    $movement->valor_total = $updates['movement']['valor_total'];
                    $movement->save();
                }

                foreach ($updates['items'] as $itemId => $itemFields) {
                    $item = $items->firstWhere('id', $itemId);
                    if (!$item instanceof MovementItem) {
                        continue;
                    }

                    foreach ($itemFields as $field => $value) {
                        $item->{$field} = $value;
                    }
                    $item->save();
                }

                if ($entry && !empty($updates['entry'])) {
                    foreach ($updates['entry'] as $field => $value) {
                        $entry->{$field} = $value;
                    }
                    $entry->save();
                }
            }

            $afterMovement = $dryRun ? $movement->fresh() : $movement->fresh();
            $afterEntry = $entry?->fresh();
            $afterItems = MovementItem::query()->where('movimento_id', $movementId)->get();
            $afterAllocations = $entry
                ? PaymentAllocation::query()->where('financial_entry_id', $entry->id)->get()
                : collect();
            $afterPayments = empty($paymentIds)
                ? collect()
                : Payment::query()->whereIn('id', $paymentIds)->get();
            $afterFacts = $this->lifecycleFacts($afterMovement, $afterEntry);
            $after = $this->buildSnapshot($afterMovement, $afterEntry, $afterItems, $afterAllocations, $afterPayments, $afterFacts);
            $afterAggregates = $this->buildAggregateSnapshot();

            return [
                'movement_id' => (string) $movement->id,
                'dry_run' => $dryRun,
                'guards_passed' => true,
                'guards' => $guards,
                'before' => $before,
                'proposed_after' => $proposedAfter,
                'after' => $after,
                'changed_fields' => $updates,
                'financial_impact' => $this->buildFinancialImpact($beforeAggregates, $afterAggregates),
                'ids_preserved' => [
                    'movement_id' => (string) $movement->id,
                    'financial_entry_id' => $entry ? (string) $entry->id : null,
                    'payment_ids' => $payments->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all(),
                    'payment_allocation_ids' => $allocations->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all(),
                    'movement_item_ids' => $items->pluck('id')->map(static fn ($id): string => (string) $id)->values()->all(),
                ],
            ];
        });
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function buildGuards(
        Movement $movement,
        ?FinancialEntry $entry,
        Collection $items,
        Collection $allocations,
        Collection $payments,
        Collection $reconciliationRows,
        Collection $fiscalRows,
        Collection $lifecycleFacts,
    ): array {
        $absAmount = round(abs((float) $movement->valor_total), 2);
        $confirmedAllocations = $allocations->where('status', PaymentAllocation::STATUS_CONFIRMED);
        $confirmedAllocationTotal = round((float) $confirmedAllocations->sum('amount'), 2);

        $issuedFiscalExists = $fiscalRows->contains(fn (FiscalDocumentRequest $row): bool => $row->status === FiscalDocumentRequest::STATUS_ISSUED)
            || filled($movement->numero_recibo);

        $canonicalEntryCount = FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->where('origem_id', $movement->id)
            ->count();

        return [
            'target_movement_id' => [
                'passed' => (string) $movement->id === self::TARGET_MOVEMENT_ID,
                'actual' => (string) $movement->id,
                'expected' => self::TARGET_MOVEMENT_ID,
            ],
            'origin_manual' => [
                'passed' => $movement->origem_tipo === 'manual',
                'actual' => $movement->origem_tipo,
                'expected' => 'manual',
            ],
            'classification_expense' => [
                'passed' => $movement->classificacao === 'despesa',
                'actual' => $movement->classificacao,
                'expected' => 'despesa',
            ],
            'movement_value_negative' => [
                'passed' => (float) $movement->valor_total < 0,
                'actual' => (float) $movement->valor_total,
                'expected' => '< 0',
            ],
            'movement_abs_matches_known_value' => [
                'passed' => abs($absAmount - self::TARGET_ABS_VALUE) <= self::EPSILON,
                'actual' => $absAmount,
                'expected' => self::TARGET_ABS_VALUE,
            ],
            'fiscal_not_issued' => [
                'passed' => !$issuedFiscalExists,
                'actual' => $issuedFiscalExists,
                'expected' => false,
            ],
            'conciliation_state_compatible' => [
                'passed' => in_array($movement->estado_conciliacao, ['nao_conciliado', 'conciliado'], true)
                    && $reconciliationRows->where('movimento_id', $movement->id)->count() <= 1,
                'actual' => [
                    'estado_conciliacao' => $movement->estado_conciliacao,
                    'maps_for_movement' => $reconciliationRows->where('movimento_id', $movement->id)->count(),
                ],
                'expected' => 'estado compatível e sem duplicação ambígua no mapa',
            ],
            'canonical_financial_entry' => [
                'passed' => $entry instanceof FinancialEntry
                    && $entry->origem_tipo === 'movement'
                    && (string) $entry->origem_id === (string) $movement->id
                    && $canonicalEntryCount === 1,
                'actual' => [
                    'entry_id' => $entry?->id,
                    'entry_origin_type' => $entry?->origem_tipo,
                    'entry_origin_id' => $entry?->origem_id,
                    'entry_count_for_movement' => $canonicalEntryCount,
                ],
                'expected' => 'exactly one canonical entry origem=movement',
            ],
            'confirmed_allocations_coherent' => [
                'passed' => $movement->estado_pagamento !== 'pago'
                    || (
                        $confirmedAllocationTotal > 0
                        && abs($confirmedAllocationTotal - $absAmount) <= self::EPSILON
                        && $payments->every(fn (Payment $payment): bool => (float) $payment->amount >= 0)
                    ),
                'actual' => [
                    'estado_pagamento' => $movement->estado_pagamento,
                    'confirmed_allocations_total' => $confirmedAllocationTotal,
                    'payments_total' => round((float) $payments->sum('amount'), 2),
                ],
                'expected' => 'allocations confirmadas coerentes com pago',
            ],
            'single_financial_representation' => [
                'passed' => $canonicalEntryCount === 1,
                'actual' => $canonicalEntryCount,
                'expected' => 1,
            ],
            'single_reporting_fact_for_lifecycle' => [
                'passed' => $lifecycleFacts->count() === 1,
                'actual' => $lifecycleFacts->count(),
                'expected' => 1,
            ],
            'reporting_fact_expected_amount_and_type' => [
                'passed' => $lifecycleFacts->count() === 1
                    && abs((float) Arr::get($lifecycleFacts->first(), 'amount', 0) - self::TARGET_ABS_VALUE) <= self::EPSILON
                    && Arr::get($lifecycleFacts->first(), 'type') === 'despesa',
                'actual' => $lifecycleFacts->first(),
                'expected' => [
                    'amount' => self::TARGET_ABS_VALUE,
                    'type' => 'despesa',
                ],
            ],
            'items_attached_to_movement' => [
                'passed' => $items->every(fn (MovementItem $item): bool => (string) $item->movimento_id === (string) $movement->id),
                'actual' => $items->count(),
                'expected' => 'all attached to movement',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSnapshot(
        Movement $movement,
        ?FinancialEntry $entry,
        Collection $items,
        Collection $allocations,
        Collection $payments,
        Collection $lifecycleFacts,
    ): array {
        $confirmedAllocations = $allocations->where('status', PaymentAllocation::STATUS_CONFIRMED);

        return [
            'movement' => [
                'id' => (string) $movement->id,
                'origem_tipo' => $movement->origem_tipo,
                'origem_id' => $movement->origem_id,
                'tipo' => $movement->tipo,
                'classificacao' => $movement->classificacao,
                'valor_total' => round((float) $movement->valor_total, 2),
                'valor_pago' => $movement->valor_pago ?? null,
                'valor_em_aberto' => $movement->valor_em_aberto ?? null,
                'estado_pagamento' => $movement->estado_pagamento,
                'estado_conciliacao' => $movement->estado_conciliacao,
                'centro_custo_id' => $movement->centro_custo_id,
                'data_emissao' => optional($movement->data_emissao)->toDateString(),
                'data_vencimento' => optional($movement->data_vencimento)->toDateString(),
            ],
            'movement_items' => $items
                ->map(static fn (MovementItem $item): array => [
                    'id' => (string) $item->id,
                    'valor_unitario' => round((float) $item->valor_unitario, 2),
                    'quantidade' => (int) $item->quantidade,
                    'total_linha' => round((float) $item->total_linha, 2),
                ])
                ->values()
                ->all(),
            'financial_entry' => $entry ? [
                'id' => (string) $entry->id,
                'origem_tipo' => $entry->origem_tipo,
                'origem_id' => $entry->origem_id,
                'tipo' => $entry->tipo,
                'valor' => round((float) $entry->valor, 2),
                'valor_pago' => round((float) ($entry->valor_pago ?? 0), 2),
                'estado_pagamento' => $entry->estado,
                'fatura_id' => $entry->fatura_id,
                'payment_id' => $entry->payment_id,
            ] : null,
            'payment_allocations' => $allocations
                ->map(static fn (PaymentAllocation $allocation): array => [
                    'id' => (string) $allocation->id,
                    'estado' => $allocation->status,
                    'valor_alocado' => round((float) $allocation->amount, 2),
                    'payment_id' => $allocation->payment_id,
                    'financial_entry_id' => $allocation->financial_entry_id,
                    'invoice_id' => $allocation->invoice_id,
                ])
                ->values()
                ->all(),
            'payments' => $payments
                ->map(static fn (Payment $payment): array => [
                    'id' => (string) $payment->id,
                    'valor' => round((float) $payment->amount, 2),
                    'estado' => $payment->status,
                    'data' => optional($payment->payment_date)->toDateString(),
                ])
                ->values()
                ->all(),
            'snapshot' => [
                'movement_value' => round((float) $movement->valor_total, 2),
                'movement_abs_value' => round(abs((float) $movement->valor_total), 2),
                'movement_items_total' => round((float) $items->sum('total_linha'), 2),
                'financial_entry_value' => $entry ? round((float) $entry->valor, 2) : null,
                'financial_entry_paid_value' => $entry ? round((float) ($entry->valor_pago ?? 0), 2) : null,
                'confirmed_allocations_total' => round((float) $confirmedAllocations->sum('amount'), 2),
                'payment_total' => round((float) $payments->sum('amount'), 2),
                'reporting_fact_amount' => $lifecycleFacts->first()['amount'] ?? null,
                'reporting_fact_type' => $lifecycleFacts->first()['type'] ?? null,
            ],
            'field_sign_audit' => [
                'movements.valor_total' => $this->classifyValue((float) $movement->valor_total),
                'movement_items.valor_unitario' => $items
                    ->map(fn (MovementItem $item): array => ['id' => (string) $item->id, 'value' => (float) $item->valor_unitario, 'classification' => $this->classifyValue((float) $item->valor_unitario)])
                    ->values()
                    ->all(),
                'movement_items.total_linha' => $items
                    ->map(fn (MovementItem $item): array => ['id' => (string) $item->id, 'value' => (float) $item->total_linha, 'classification' => $this->classifyValue((float) $item->total_linha)])
                    ->values()
                    ->all(),
                'financial_entries.valor' => $entry ? $this->classifyValue((float) $entry->valor) : 'not_applicable',
                'financial_entries.valor_pago' => $entry ? $this->classifyValue((float) ($entry->valor_pago ?? 0)) : 'not_applicable',
                'payment_allocations.valor_alocado' => $allocations
                    ->map(fn (PaymentAllocation $allocation): array => ['id' => (string) $allocation->id, 'value' => (float) $allocation->amount, 'classification' => $this->classifyValue((float) $allocation->amount)])
                    ->values()
                    ->all(),
                'payments.valor' => $payments
                    ->map(fn (Payment $payment): array => ['id' => (string) $payment->id, 'value' => (float) $payment->amount, 'classification' => $this->classifyValue((float) $payment->amount)])
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @return array{movement: array<string,mixed>, entry: array<string,mixed>, items: array<string,array<string,mixed>>}
     */
    private function buildUpdatePlan(Movement $movement, ?FinancialEntry $entry, Collection $items): array
    {
        $movementUpdates = [];
        if ((float) $movement->valor_total < 0) {
            $movementUpdates['valor_total'] = round(abs((float) $movement->valor_total), 2);
        }

        $entryUpdates = [];
        if ($entry && $entry->tipo === 'despesa' && (float) $entry->valor < 0) {
            $entryUpdates['valor'] = round(abs((float) $entry->valor), 2);
        }

        if ($entry && $entry->tipo === 'despesa' && (float) ($entry->valor_pago ?? 0) < 0) {
            $entryUpdates['valor_pago'] = round(abs((float) ($entry->valor_pago ?? 0)), 2);
        }

        $itemUpdates = [];
        foreach ($items as $item) {
            if (!$item instanceof MovementItem) {
                continue;
            }

            $updates = [];
            if ((float) $item->valor_unitario < 0) {
                $updates['valor_unitario'] = round(abs((float) $item->valor_unitario), 2);
            }

            if ((float) $item->total_linha < 0) {
                $updates['total_linha'] = round(abs((float) $item->total_linha), 2);
            }

            if (!empty($updates)) {
                $itemUpdates[(string) $item->id] = $updates;
            }
        }

        return [
            'movement' => $movementUpdates,
            'entry' => $entryUpdates,
            'items' => $itemUpdates,
        ];
    }

    /**
     * @param array<string,mixed> $before
     * @param array{movement: array<string,mixed>, entry: array<string,mixed>, items: array<string,array<string,mixed>>} $updates
     * @return array<string,mixed>
     */
    private function buildProposedAfter(array $before, array $updates): array
    {
        $proposed = $before;

        foreach ($updates['movement'] as $field => $value) {
            $proposed['movement'][$field] = $value;
            if ($field === 'valor_total') {
                $proposed['snapshot']['movement_value'] = $value;
                $proposed['snapshot']['movement_abs_value'] = round(abs((float) $value), 2);
            }
        }

        if (!empty($proposed['financial_entry']) && !empty($updates['entry'])) {
            foreach ($updates['entry'] as $field => $value) {
                $proposed['financial_entry'][$field] = $value;
                if ($field === 'valor') {
                    $proposed['snapshot']['financial_entry_value'] = $value;
                }
                if ($field === 'valor_pago') {
                    $proposed['snapshot']['financial_entry_paid_value'] = $value;
                }
            }
        }

        if (!empty($updates['items'])) {
            $proposed['movement_items'] = collect($proposed['movement_items'])
                ->map(function (array $item) use ($updates): array {
                    $itemUpdates = $updates['items'][$item['id']] ?? [];
                    foreach ($itemUpdates as $field => $value) {
                        $item[$field] = $value;
                    }

                    return $item;
                })
                ->values()
                ->all();

            $proposed['snapshot']['movement_items_total'] = round((float) collect($proposed['movement_items'])->sum('total_linha'), 2);
        }

        $proposed['field_sign_audit']['movements.valor_total'] = $this->classifyValue((float) $proposed['movement']['valor_total']);

        return $proposed;
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function lifecycleFacts(Movement $movement, ?FinancialEntry $entry): Collection
    {
        return $this->financialReportingFactService
            ->paidFacts()
            ->filter(function (array $fact) use ($movement, $entry): bool {
                if (($fact['source_kind'] ?? null) === 'movement' && (string) ($fact['source_id'] ?? '') === (string) $movement->id) {
                    return true;
                }

                if (($fact['source_kind'] ?? null) === 'financial_entry' && $entry) {
                    return (string) ($fact['source_id'] ?? '') === (string) $entry->id;
                }

                return false;
            })
            ->values();
    }

    /**
     * @return array<string,float|int>
     */
    private function buildAggregateSnapshot(): array
    {
        $facts = $this->financialReportingFactService->paidFacts();
        $summary = $this->financeReportService->summary();
        $dashboard = $this->financeDashboardService->build();
        $currentAccount = $this->currentAccountService->summarize();

        return [
            'reporting_revenue_total' => round((float) $facts->where('type', 'receita')->sum('amount'), 2),
            'reporting_expense_total' => round((float) $facts->where('type', 'despesa')->sum('amount'), 2),
            'reporting_balance_total' => round((float) $facts->where('type', 'receita')->sum('amount') - (float) $facts->where('type', 'despesa')->sum('amount'), 2),
            'reporting_fact_count' => $facts->count(),
            'finance_report_total_receitas' => round((float) ($summary['totalReceitas'] ?? 0), 2),
            'finance_report_total_despesas' => round((float) ($summary['totalDespesas'] ?? 0), 2),
            'finance_report_saldo_atual' => round((float) ($summary['saldoAtual'] ?? 0), 2),
            'finance_dashboard_total_geral' => round((float) ($dashboard['total_geral'] ?? 0), 2),
            'finance_dashboard_receitas_mes' => round((float) ($dashboard['receitas_mes'] ?? 0), 2),
            'finance_dashboard_despesas_mes' => round((float) ($dashboard['despesas_mes'] ?? 0), 2),
            'current_account_net_debt' => round((float) ($currentAccount['net_debt'] ?? 0), 2),
        ];
    }

    /**
     * @param array<string,float|int> $before
     * @param array<string,float|int> $after
     * @return array<string,mixed>
     */
    private function buildFinancialImpact(array $before, array $after): array
    {
        return [
            'reporting_revenue_delta' => round((float) $after['reporting_revenue_total'] - (float) $before['reporting_revenue_total'], 2),
            'reporting_expense_delta' => round((float) $after['reporting_expense_total'] - (float) $before['reporting_expense_total'], 2),
            'reporting_balance_delta' => round((float) $after['reporting_balance_total'] - (float) $before['reporting_balance_total'], 2),
            'reporting_fact_count_delta' => (int) $after['reporting_fact_count'] - (int) $before['reporting_fact_count'],
            'finance_report_receitas_delta' => round((float) $after['finance_report_total_receitas'] - (float) $before['finance_report_total_receitas'], 2),
            'finance_report_despesas_delta' => round((float) $after['finance_report_total_despesas'] - (float) $before['finance_report_total_despesas'], 2),
            'finance_report_saldo_delta' => round((float) $after['finance_report_saldo_atual'] - (float) $before['finance_report_saldo_atual'], 2),
            'dashboard_total_geral_delta' => round((float) $after['finance_dashboard_total_geral'] - (float) $before['finance_dashboard_total_geral'], 2),
            'dashboard_receitas_mes_delta' => round((float) $after['finance_dashboard_receitas_mes'] - (float) $before['finance_dashboard_receitas_mes'], 2),
            'dashboard_despesas_mes_delta' => round((float) $after['finance_dashboard_despesas_mes'] - (float) $before['finance_dashboard_despesas_mes'], 2),
            'current_account_delta' => round((float) $after['current_account_net_debt'] - (float) $before['current_account_net_debt'], 2),
            'before' => $before,
            'after' => $after,
        ];
    }

    private function classifyValue(float $value): string
    {
        if (abs($value) <= self::EPSILON) {
            return 'zero';
        }

        if ($value < 0) {
            return 'legacy_negative';
        }

        return 'canonical_positive';
    }
}

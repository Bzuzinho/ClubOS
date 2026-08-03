<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankTransactionAllocation;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\MonthlyFee;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MemberMonthlyFeeLifecycleService
{
    public function __construct(
        private readonly MonthlyFeeGenerationService $monthlyFeeGenerationService,
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
        private readonly InvoiceFinancialGuardService $invoiceFinancialGuardService,
    ) {
    }

    public function deleteCleanMonthlyInvoice(Invoice $invoice): void
    {
        $userId = null;

        DB::transaction(function () use ($invoice, &$userId): void {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->tipo !== 'mensalidade') {
                throw ValidationException::withMessages([
                    'invoice' => 'A fatura selecionada nao e uma mensalidade.',
                ]);
            }

            if (! in_array($lockedInvoice->estado_pagamento, ['pendente', 'vencido'], true)) {
                throw ValidationException::withMessages([
                    'invoice' => 'Apenas mensalidades pendentes ou vencidas, sem rasto financeiro ou fiscal, podem ser apagadas.',
                ]);
            }

            if ($this->invoiceFinancialGuardService->hasFinancialOrFiscalTrail($lockedInvoice)) {
                throw ValidationException::withMessages([
                    'invoice' => 'A mensalidade tem rasto financeiro ou fiscal. Deve ser reaberta, cancelada ou anulada pelo fluxo proprio, nao apagada.',
                ]);
            }

            if ($lockedInvoice->items()->whereNotNull('produto_id')->exists()) {
                throw ValidationException::withMessages([
                    'invoice' => 'A mensalidade tem artigos de stock associados e nao pode ser apagada por este fluxo.',
                ]);
            }

            $userId = $lockedInvoice->user_id ? (string) $lockedInvoice->user_id : null;

            $lockedInvoice->items()->delete();
            $lockedInvoice->delete();
        });

        if ($userId !== null) {
            $this->forgetUserFinanceCaches($userId);
        }
    }

    /**
     * @return array{cancelled_count:int,cancelled_invoice_ids:list<string>,effective_month:string,cutoff_month:string}
     */
    public function reconcileEligibilityTransition(
        User $user,
        bool $previouslyEligible,
        bool $currentlyEligible,
        ?Carbon $effectiveDate = null,
    ): array {
        $effectiveDate = ($effectiveDate ?? Carbon::today())->copy()->startOfDay();
        $effectiveMonth = $effectiveDate->copy()->startOfMonth();
        $cutoffMonth = $effectiveMonth->copy()->addMonthNoOverflow();

        $summary = [
            'cancelled_count' => 0,
            'cancelled_invoice_ids' => [],
            'effective_month' => $effectiveMonth->format('Y-m'),
            'cutoff_month' => $cutoffMonth->format('Y-m'),
        ];

        if (! $previouslyEligible || $currentlyEligible) {
            return $summary;
        }

        return DB::transaction(function () use ($user, $effectiveMonth, $cutoffMonth, $summary): array {
            $invoices = Invoice::query()
                ->where('user_id', $user->id)
                ->where('tipo', 'mensalidade')
                ->whereIn('estado_pagamento', ['pendente', 'vencido'])
                ->where('mes', '>=', $cutoffMonth->format('Y-m'))
                ->orderBy('mes')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Invoice $invoice): bool => $this->isAfterEffectiveMonth($invoice, $effectiveMonth))
                ->filter(fn (Invoice $invoice): bool => $this->canReconcileFutureMonthlyInvoice($invoice))
                ->values();

            foreach ($invoices as $invoice) {
                $invoice->forceFill([
                    'estado_pagamento' => 'cancelado',
                    'valor_pago' => 0,
                    'valor_em_aberto' => 0,
                    'oculta' => false,
                    'pagamento_observacoes' => trim((string) $invoice->pagamento_observacoes) !== ''
                        ? $invoice->pagamento_observacoes
                        : sprintf(
                            'Cancelada automaticamente por perda de elegibilidade de mensalidade em %s.',
                            $effectiveMonth->format('Y-m'),
                        ),
                ])->save();

                $summary['cancelled_invoice_ids'][] = (string) $invoice->id;
            }

            $summary['cancelled_count'] = count($summary['cancelled_invoice_ids']);

            if ($summary['cancelled_count'] > 0) {
                $this->forgetUserFinanceCaches((string) $user->id);
            }

            return $summary;
        });
    }

    /**
     * @return array{cancelled_count:int,regenerated_count:int,cancelled_invoice_ids:list<string>,regenerated_invoice_ids:list<string>,periods:list<string>,effective_month:string,cutoff_month:string}
     */
    public function reconcileFutureMonthlyTerms(User $user, ?Carbon $effectiveDate = null): array
    {
        $effectiveDate = ($effectiveDate ?? Carbon::today())->copy()->startOfDay();
        $effectiveMonth = $effectiveDate->copy()->startOfMonth();
        $cutoffMonth = $effectiveMonth->copy()->addMonthNoOverflow();

        $summary = [
            'cancelled_count' => 0,
            'regenerated_count' => 0,
            'cancelled_invoice_ids' => [],
            'regenerated_invoice_ids' => [],
            'periods' => [],
            'effective_month' => $effectiveMonth->format('Y-m'),
            'cutoff_month' => $cutoffMonth->format('Y-m'),
        ];

        return DB::transaction(function () use ($user, $effectiveDate, $effectiveMonth, $cutoffMonth, $summary): array {
            $invoices = Invoice::query()
                ->with('items')
                ->where('user_id', $user->id)
                ->where('tipo', 'mensalidade')
                ->whereIn('estado_pagamento', ['pendente', 'vencido'])
                ->where('mes', '>=', $cutoffMonth->format('Y-m'))
                ->orderBy('mes')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Invoice $invoice): bool => $this->isAfterEffectiveMonth($invoice, $effectiveMonth))
                ->filter(fn (Invoice $invoice): bool => $this->canReconcileFutureMonthlyInvoice($invoice))
                ->reject(fn (Invoice $invoice): bool => $this->matchesCurrentMonthlyTerms($user, $invoice))
                ->values();

            $periods = $invoices
                ->pluck('mes')
                ->filter(fn (mixed $month): bool => is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1)
                ->unique()
                ->values()
                ->all();

            foreach ($invoices as $invoice) {
                $invoice->forceFill([
                    'estado_pagamento' => 'cancelado',
                    'valor_pago' => 0,
                    'valor_em_aberto' => 0,
                    'oculta' => false,
                    'pagamento_observacoes' => trim((string) $invoice->pagamento_observacoes) !== ''
                        ? $invoice->pagamento_observacoes
                        : sprintf(
                            'Cancelada automaticamente por alteracao de termos de mensalidade em %s.',
                            $effectiveMonth->format('Y-m'),
                        ),
                ])->save();

                $summary['cancelled_invoice_ids'][] = (string) $invoice->id;
            }

            $userForGeneration = $user->fresh(['dadosFinanceiros.mensalidade', 'centrosCusto', 'userTypes']) ?? $user;

            foreach ($periods as $period) {
                $periodStart = Carbon::createFromFormat('Y-m-d', $period . '-01')->startOfMonth();
                $generation = $this->monthlyFeeGenerationService->generateForUserWithSummary(
                    $userForGeneration,
                    $periodStart,
                    $periodStart,
                    [
                        'today' => $effectiveDate,
                        'start_date' => $periodStart->toDateString(),
                        'load_created_items' => true,
                    ],
                );

                $summary['regenerated_invoice_ids'] = array_values(array_merge(
                    $summary['regenerated_invoice_ids'],
                    array_map('strval', $generation['created_invoice_ids'] ?? []),
                ));
            }

            $summary['periods'] = $periods;
            $summary['cancelled_count'] = count($summary['cancelled_invoice_ids']);
            $summary['regenerated_count'] = count($summary['regenerated_invoice_ids']);

            if ($summary['cancelled_count'] > 0 || $summary['regenerated_count'] > 0) {
                $this->forgetUserFinanceCaches((string) $user->id);
            }

            return $summary;
        });
    }

    private function isAfterEffectiveMonth(Invoice $invoice, Carbon $effectiveMonth): bool
    {
        $month = trim((string) $invoice->mes);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return false;
        }

        return Carbon::createFromFormat('Y-m-d', $month . '-01')
            ->startOfMonth()
            ->greaterThan($effectiveMonth);
    }

    public function canReconcileFutureMonthlyInvoice(Invoice $invoice): bool
    {
        if ($invoice->tipo !== 'mensalidade') {
            return false;
        }

        if (! in_array($invoice->estado_pagamento, ['pendente', 'vencido'], true)) {
            return false;
        }

        if (round((float) ($invoice->valor_pago ?? 0), 2) > 0.0) {
            return false;
        }

        if (filled($invoice->numero_recibo) || filled($invoice->receipt_import_item_id)) {
            return false;
        }

        return ! PaymentAllocation::withTrashed()->where('invoice_id', $invoice->id)->exists()
            && ! MapaConciliacao::query()->where('fatura_id', $invoice->id)->exists()
            && ! BankTransactionAllocation::query()->where('invoice_id', $invoice->id)->exists()
            && ! FiscalDocumentRequest::withTrashed()->where('invoice_id', $invoice->id)->exists();
    }

    /**
     * @return list<string>
     */
    public function futureMonthlyInvoiceProtectionReasons(Invoice $invoice): array
    {
        $reasons = [];

        if ($invoice->tipo !== 'mensalidade') {
            $reasons[] = 'not_monthly_fee';
        }

        if (! in_array($invoice->estado_pagamento, ['pendente', 'vencido'], true)) {
            $reasons[] = 'estado_not_reconcilable';
        }

        if (round((float) ($invoice->valor_pago ?? 0), 2) > 0.0) {
            $reasons[] = 'paid_amount';
        }

        if (filled($invoice->numero_recibo)) {
            $reasons[] = 'receipt_number';
        }

        if (filled($invoice->receipt_import_item_id)) {
            $reasons[] = 'receipt_import';
        }

        if (PaymentAllocation::withTrashed()->where('invoice_id', $invoice->id)->exists()) {
            $reasons[] = 'payment_allocation';
        }

        if (MapaConciliacao::query()->where('fatura_id', $invoice->id)->exists()) {
            $reasons[] = 'mapa_conciliacao';
        }

        if (BankTransactionAllocation::query()->where('invoice_id', $invoice->id)->exists()) {
            $reasons[] = 'bank_transaction_allocation';
        }

        if (FiscalDocumentRequest::withTrashed()->where('invoice_id', $invoice->id)->exists()) {
            $reasons[] = 'fiscal_request';
        }

        return array_values(array_unique($reasons));
    }

    public function matchesCurrentMonthlyTerms(User $user, Invoice $invoice): bool
    {
        $user->loadMissing(['dadosFinanceiros.mensalidade', 'centrosCusto']);
        $planId = $user->dadosFinanceiros?->mensalidade_id;
        $plan = $user->dadosFinanceiros?->mensalidade;

        if (! $plan && $planId) {
            $plan = MonthlyFee::query()->find($planId);
        }

        if (! $plan) {
            return false;
        }

        if ($invoice->origem_tipo !== 'monthly_fee' || (string) $invoice->origem_id !== (string) $plan->id) {
            return false;
        }

        $baseAmount = round((float) $plan->valor, 2);
        $adjustment = $this->resolveFinancialAdjustment($user, $baseAmount);
        $expectedTotal = round(max(0, $baseAmount - $adjustment['applied_amount']), 2);

        if (round((float) $invoice->valor_total, 2) !== $expectedTotal) {
            return false;
        }

        $expectedLines = $this->expectedMonthlyTermLines($user, (string) $plan->designacao, $baseAmount, $adjustment);
        $actualLines = $invoice->items
            ->map(fn ($item): array => [
                'descricao' => (string) $item->descricao,
                'centro_custo_id' => $item->centro_custo_id ? (string) $item->centro_custo_id : null,
                'total_linha' => round((float) $item->total_linha, 2),
            ])
            ->sortBy(fn (array $line): string => implode('|', [
                $line['descricao'],
                (string) $line['centro_custo_id'],
                number_format($line['total_linha'], 2, '.', ''),
            ]))
            ->values()
            ->all();

        return $actualLines == $expectedLines;
    }

    /**
     * @param array{applied_amount:float,description:?string} $adjustment
     * @return list<array{descricao:string,centro_custo_id:?string,total_linha:float}>
     */
    private function expectedMonthlyTermLines(User $user, string $planDesignation, float $baseAmount, array $adjustment): array
    {
        $lines = collect($this->resolveCostCenterShares($user, $baseAmount))
            ->map(fn (array $share): array => [
                'descricao' => $planDesignation,
                'centro_custo_id' => $share['id'] ? (string) $share['id'] : null,
                'total_linha' => round((float) $share['amount'], 2),
            ]);

        if ($adjustment['applied_amount'] > 0) {
            $shares = $this->resolveCostCenterShares($user, $baseAmount);
            $lines->push([
                'descricao' => (string) $adjustment['description'],
                'centro_custo_id' => $shares[0]['id'] ? (string) $shares[0]['id'] : null,
                'total_linha' => -round((float) $adjustment['applied_amount'], 2),
            ]);
        }

        return $lines
            ->sortBy(fn (array $line): string => implode('|', [
                $line['descricao'],
                (string) $line['centro_custo_id'],
                number_format($line['total_linha'], 2, '.', ''),
            ]))
            ->values()
            ->all();
    }

    /**
     * @return list<array{id:?string,amount:float}>
     */
    private function resolveCostCenterShares(User $user, float $totalAmount): array
    {
        $resolved = $this->memberCostCenterResolver->resolveForUser($user);
        $shares = array_values(array_map(static fn (array $share): array => [
            'id' => $share['id'] ?? null,
            'weight' => (float) ($share['peso'] ?? 1),
        ], array_filter($resolved['centro_custo_pesos'] ?? [], static fn (array $share): bool => !empty($share['id']))));

        if ($shares === []) {
            return [[
                'id' => null,
                'amount' => round($totalAmount, 2),
            ]];
        }

        usort($shares, fn (array $left, array $right) => $right['weight'] <=> $left['weight']);
        $weightTotal = array_sum(array_column($shares, 'weight')) ?: 1.0;
        $allocated = 0.0;
        $lastIndex = count($shares) - 1;

        foreach ($shares as $index => &$share) {
            $amount = $index === $lastIndex
                ? round($totalAmount - $allocated, 2)
                : round($totalAmount * ($share['weight'] / $weightTotal), 2);

            $share['amount'] = $amount;
            $allocated += $amount;
        }
        unset($share);

        return array_map(static fn (array $share): array => [
            'id' => $share['id'] ? (string) $share['id'] : null,
            'amount' => (float) $share['amount'],
        ], $shares);
    }

    /**
     * @return array{applied_amount:float,description:?string}
     */
    private function resolveFinancialAdjustment(User $user, float $baseAmount): array
    {
        $type = $user->dadosFinanceiros?->discount_type;
        $value = round((float) ($user->dadosFinanceiros?->discount_value ?? 0), 2);

        if (! in_array($type, ['percent', 'fixed'], true) || $value <= 0) {
            return [
                'applied_amount' => 0.0,
                'description' => null,
            ];
        }

        $requestedAmount = $type === 'percent'
            ? round($baseAmount * ($value / 100), 2)
            : $value;

        $appliedAmount = round(min($baseAmount, $requestedAmount), 2);

        return [
            'applied_amount' => $appliedAmount,
            'description' => $type === 'percent'
                ? sprintf('Desconto/Correcao %s%%', $this->formatPercentage($value))
                : 'Desconto/Correcao financeira',
        ];
    }

    private function formatPercentage(float $value): string
    {
        $normalized = number_format($value, 2, '.', '');
        $normalized = rtrim(rtrim($normalized, '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }

    private function forgetUserFinanceCaches(string $userId): void
    {
        Cache::forget("athlete_dashboard:{$userId}:current_account");
        Cache::forget("athlete_dashboard:{$userId}:pending_invoice");
        Cache::forget("athlete_dashboard:{$userId}:invoices");
    }
}

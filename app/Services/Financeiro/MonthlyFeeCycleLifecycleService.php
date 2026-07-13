<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MonthlyFeeCycleLifecycleService
{
    private const RECONCILING_SETTING_KEYS = [
        'start_month',
        'end_month',
        'due_day',
        'hide_future',
        'respect_registration_date',
        'generate_months_ahead',
    ];

    public function __construct(
        private readonly MonthlyFeeSettingsService $settingsService,
        private readonly MonthlyFeeGenerationService $monthlyFeeGenerationService,
        private readonly MemberMonthlyFeeLifecycleService $memberMonthlyFeeLifecycleService,
        private readonly MemberMonthlyFeeEligibilityService $memberMonthlyFeeEligibilityService,
    ) {
    }

    /**
     * @param array<string, mixed> $previousSettings
     * @param array<string, mixed> $currentSettings
     * @return array{changed:bool,changed_keys:list<string>,cancelled_count:int,regenerated_count:int,cancelled_invoice_ids:list<string>,regenerated_invoice_ids:list<string>,cancelled_periods:list<string>,regenerated_periods:list<string>,effective_month:string,cutoff_month:string}
     */
    public function reconcileFutureInvoicesForSettingsChange(
        array $previousSettings,
        array $currentSettings,
        ?Carbon $effectiveDate = null,
    ): array {
        $previous = $this->settingsService->normalize($previousSettings);
        $current = $this->settingsService->normalize($currentSettings);
        $changedKeys = $this->changedReconcilingKeys($previous, $current);
        $effectiveDate = ($effectiveDate ?? Carbon::today())->copy()->startOfDay();
        $effectiveMonth = $effectiveDate->copy()->startOfMonth();
        $cutoffMonth = $effectiveMonth->copy()->addMonthNoOverflow();

        $summary = [
            'changed' => $changedKeys !== [],
            'changed_keys' => $changedKeys,
            'cancelled_count' => 0,
            'regenerated_count' => 0,
            'cancelled_invoice_ids' => [],
            'regenerated_invoice_ids' => [],
            'cancelled_periods' => [],
            'regenerated_periods' => [],
            'effective_month' => $effectiveMonth->format('Y-m'),
            'cutoff_month' => $cutoffMonth->format('Y-m'),
        ];

        if ($changedKeys === []) {
            return $summary;
        }

        return DB::transaction(function () use ($current, $effectiveDate, $effectiveMonth, $cutoffMonth, $summary): array {
            $window = $this->settingsService->resolveGenerationWindowFromSettings($current, $effectiveDate);

            User::query()
                ->with(['dadosFinanceiros.mensalidade', 'centrosCusto', 'userTypes'])
                ->whereHas('dadosFinanceiros', fn ($query) => $query->whereNotNull('mensalidade_id'))
                ->orderBy('id')
                ->chunkById(100, function ($members) use ($current, $effectiveDate, $effectiveMonth, $cutoffMonth, $window, &$summary): void {
                    foreach ($members as $member) {
                        if (! $this->memberMonthlyFeeEligibilityService->shouldHaveMonthlyFee($member)) {
                            continue;
                        }

                        $invoices = Invoice::query()
                            ->with('items')
                            ->where('user_id', $member->id)
                            ->where('tipo', 'mensalidade')
                            ->whereIn('estado_pagamento', ['pendente', 'vencido'])
                            ->where('mes', '>=', $cutoffMonth->format('Y-m'))
                            ->orderBy('mes')
                            ->lockForUpdate()
                            ->get()
                            ->filter(fn (Invoice $invoice): bool => $this->isAfterEffectiveMonth($invoice, $effectiveMonth))
                            ->filter(fn (Invoice $invoice): bool => $this->memberMonthlyFeeLifecycleService->canReconcileFutureMonthlyInvoice($invoice))
                            ->values();

                        $periodsToRegenerate = [];

                        foreach ($invoices as $invoice) {
                            $periodStart = $this->periodStart($invoice);
                            if (! $periodStart) {
                                continue;
                            }

                            $shouldExist = $this->periodShouldExistForMember($member, $periodStart, $current, $window);
                            $matchesProjection = $shouldExist && $this->matchesCurrentCycleProjection($member, $invoice, $periodStart, $current, $effectiveDate);

                            if ($matchesProjection) {
                                continue;
                            }

                            $this->cancelInvoice($invoice, $effectiveMonth);
                            $summary['cancelled_invoice_ids'][] = (string) $invoice->id;
                            $summary['cancelled_periods'][] = $periodStart->format('Y-m');

                            if ($shouldExist) {
                                $periodsToRegenerate[$periodStart->format('Y-m')] = $periodStart;
                            }
                        }

                        foreach ($periodsToRegenerate as $periodStart) {
                            $generation = $this->monthlyFeeGenerationService->generateForUserWithSummary(
                                $member->fresh(['dadosFinanceiros.mensalidade', 'centrosCusto', 'userTypes']) ?? $member,
                                $periodStart,
                                $periodStart,
                                [
                                    'today' => $effectiveDate,
                                    'start_date' => $periodStart->toDateString(),
                                    'settings' => $current,
                                    'load_created_items' => true,
                                ],
                            );

                            $createdIds = array_map('strval', $generation['created_invoice_ids'] ?? []);
                            if ($createdIds !== []) {
                                $summary['regenerated_invoice_ids'] = array_values(array_merge($summary['regenerated_invoice_ids'], $createdIds));
                                $summary['regenerated_periods'][] = $periodStart->format('Y-m');
                            }
                        }

                        if ($periodsToRegenerate !== [] || $invoices->isNotEmpty()) {
                            $this->forgetUserFinanceCaches((string) $member->id);
                        }
                    }
                });

            $summary['cancelled_invoice_ids'] = array_values(array_unique($summary['cancelled_invoice_ids']));
            $summary['regenerated_invoice_ids'] = array_values(array_unique($summary['regenerated_invoice_ids']));
            $summary['cancelled_periods'] = array_values(array_unique($summary['cancelled_periods']));
            $summary['regenerated_periods'] = array_values(array_unique($summary['regenerated_periods']));
            $summary['cancelled_count'] = count($summary['cancelled_invoice_ids']);
            $summary['regenerated_count'] = count($summary['regenerated_invoice_ids']);

            return $summary;
        });
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $current
     * @return list<string>
     */
    private function changedReconcilingKeys(array $previous, array $current): array
    {
        return array_values(array_filter(
            self::RECONCILING_SETTING_KEYS,
            fn (string $key): bool => ($previous[$key] ?? null) !== ($current[$key] ?? null),
        ));
    }

    /**
     * @param array<string, mixed> $settings
     * @param array{start: Carbon, end: Carbon} $window
     */
    private function periodShouldExistForMember(User $member, Carbon $periodStart, array $settings, array $window): bool
    {
        if ($periodStart->lessThan($window['start']) || $periodStart->greaterThan($window['end'])) {
            return false;
        }

        if (($settings['respect_registration_date'] ?? true) === true) {
            $signupMonth = $member->data_inscricao?->copy()?->startOfMonth();
            if ($signupMonth && $periodStart->lessThan($signupMonth)) {
                return false;
            }
        }

        return $member->dadosFinanceiros?->mensalidade_id !== null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function matchesCurrentCycleProjection(User $member, Invoice $invoice, Carbon $periodStart, array $settings, Carbon $effectiveDate): bool
    {
        $expectedDueDate = $this->settingsService->resolveDueDateFromSettings($periodStart, $settings);
        $expectedHidden = ($settings['hide_future'] ?? true) === true && $expectedDueDate->greaterThan($effectiveDate);

        return $invoice->data_vencimento?->toDateString() === $expectedDueDate->toDateString()
            && (bool) $invoice->oculta === $expectedHidden
            && $this->memberMonthlyFeeLifecycleService->matchesCurrentMonthlyTerms($member, $invoice);
    }

    private function periodStart(Invoice $invoice): ?Carbon
    {
        $month = trim((string) $invoice->mes);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();
    }

    private function isAfterEffectiveMonth(Invoice $invoice, Carbon $effectiveMonth): bool
    {
        $periodStart = $this->periodStart($invoice);

        return $periodStart !== null && $periodStart->greaterThan($effectiveMonth);
    }

    private function cancelInvoice(Invoice $invoice, Carbon $effectiveMonth): void
    {
        $invoice->forceFill([
            'estado_pagamento' => 'cancelado',
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
            'oculta' => false,
            'pagamento_observacoes' => trim((string) $invoice->pagamento_observacoes) !== ''
                ? $invoice->pagamento_observacoes
                : sprintf(
                    'Cancelada automaticamente por alteracao das definicoes do ciclo de mensalidades em %s.',
                    $effectiveMonth->format('Y-m'),
                ),
        ])->save();
    }

    private function forgetUserFinanceCaches(string $userId): void
    {
        Cache::forget("athlete_dashboard:{$userId}:current_account");
        Cache::forget("athlete_dashboard:{$userId}:pending_invoice");
        Cache::forget("athlete_dashboard:{$userId}:invoices");
    }
}

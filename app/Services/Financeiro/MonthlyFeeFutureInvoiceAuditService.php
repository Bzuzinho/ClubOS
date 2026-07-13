<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class MonthlyFeeFutureInvoiceAuditService
{
    private const VERSION = 'a2-6-future-monthly-fee-audit-v1';

    public function __construct(
        private readonly MonthlyFeeSettingsService $settingsService,
        private readonly MemberMonthlyFeeLifecycleService $memberMonthlyFeeLifecycleService,
        private readonly MemberMonthlyFeeEligibilityService $memberMonthlyFeeEligibilityService,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function audit(array $options = []): array
    {
        $effectiveDate = $this->resolveEffectiveDate($options['from'] ?? null);
        $effectiveMonth = $effectiveDate->copy()->startOfMonth();
        $cutoffMonth = $effectiveMonth->copy()->addMonthNoOverflow();
        $settings = $this->settingsService->get();
        $window = $this->settingsService->resolveGenerationWindowFromSettings($settings, $effectiveDate);
        $onlyReconcilable = (bool) ($options['only_reconcilable'] ?? false);
        $userId = $this->normalizeNullableString($options['user'] ?? null);

        $invoices = $this->futureMonthlyInvoices($cutoffMonth, $userId);
        $invoiceDiagnostics = [];
        $findings = [];
        $totalReconcilable = 0;
        $totalProtected = 0;

        foreach ($invoices as $invoice) {
            $diagnostic = $this->invoiceDiagnostic($invoice, $effectiveDate, $settings, $window);
            $invoiceDiagnostics[(string) $invoice->id] = $diagnostic;

            if ($diagnostic['reconcilable']) {
                $totalReconcilable++;
            } else {
                $totalProtected++;
            }

            if ($onlyReconcilable && ! $diagnostic['reconcilable']) {
                continue;
            }

            array_push($findings, ...$this->findingsForInvoice($diagnostic));
        }

        array_push($findings, ...$this->duplicateFindings($invoices, $invoiceDiagnostics, $onlyReconcilable));

        $summary = $this->summary($invoices->count(), $totalReconcilable, $totalProtected, $findings);

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'effective_date' => $effectiveDate->toDateString(),
            'cutoff_month' => $cutoffMonth->format('Y-m'),
            'settings' => $settings,
            'window' => [
                'start' => $window['start']->format('Y-m'),
                'end' => $window['end']->format('Y-m'),
            ],
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    private function resolveEffectiveDate(mixed $value): Carbon
    {
        $normalized = $this->normalizeNullableString($value);

        return $normalized === null
            ? Carbon::today()->startOfDay()
            : Carbon::parse($normalized)->startOfDay();
    }

    /**
     * @return Collection<int,Invoice>
     */
    private function futureMonthlyInvoices(Carbon $cutoffMonth, ?string $userId): Collection
    {
        $query = Invoice::query()
            ->with(['items', 'user.dadosFinanceiros.mensalidade', 'user.centrosCusto', 'user.userTypes'])
            ->where('tipo', 'mensalidade')
            ->where('estado_pagamento', '!=', 'cancelado')
            ->where('mes', '>=', $cutoffMonth->format('Y-m'))
            ->orderBy('user_id')
            ->orderBy('mes')
            ->orderBy('id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        return $query->get()
            ->filter(fn (Invoice $invoice): bool => $this->periodStart($invoice) !== null)
            ->values();
    }

    /**
     * @param array<string,mixed> $settings
     * @param array{start:Carbon,end:Carbon} $window
     * @return array<string,mixed>
     */
    private function invoiceDiagnostic(Invoice $invoice, Carbon $effectiveDate, array $settings, array $window): array
    {
        $invoice->loadMissing(['items', 'user.dadosFinanceiros.mensalidade', 'user.centrosCusto', 'user.userTypes']);
        $periodStart = $this->periodStart($invoice);
        $user = $invoice->user;
        $expectedDueDate = $periodStart
            ? $this->settingsService->resolveDueDateFromSettings($periodStart, $settings)
            : null;
        $expectedHidden = $expectedDueDate
            ? ($settings['hide_future'] ?? true) === true && $expectedDueDate->greaterThan($effectiveDate)
            : null;
        $insideWindow = $periodStart !== null
            && ! $periodStart->lessThan($window['start'])
            && ! $periodStart->greaterThan($window['end']);
        $registrationRespected = $this->registrationDateAllowsPeriod($user, $periodStart, $settings);
        $eligibility = $user ? $this->memberMonthlyFeeEligibilityService->evaluate($user) : null;
        $eligible = $user !== null && (bool) ($eligibility['should_have_monthly_fee'] ?? false);
        $termsMatch = $user !== null && $this->memberMonthlyFeeLifecycleService->matchesCurrentMonthlyTerms($user, $invoice);
        $reconcilable = $this->memberMonthlyFeeLifecycleService->canReconcileFutureMonthlyInvoice($invoice);
        $protectionReasons = $this->memberMonthlyFeeLifecycleService->futureMonthlyInvoiceProtectionReasons($invoice);

        return [
            'invoice' => $invoice,
            'invoice_id' => (string) $invoice->id,
            'user_id' => $invoice->user_id ? (string) $invoice->user_id : null,
            'member_name' => $this->memberName($user),
            'mes' => (string) $invoice->mes,
            'estado_pagamento' => (string) $invoice->estado_pagamento,
            'valor_total' => round((float) $invoice->valor_total, 2),
            'valor_em_aberto' => round((float) $invoice->valor_em_aberto, 2),
            'oculta' => (bool) $invoice->oculta,
            'data_vencimento' => $invoice->data_vencimento?->toDateString(),
            'reconcilable' => $reconcilable,
            'protection_reasons' => $protectionReasons,
            'eligible' => $eligible,
            'eligibility_reasons' => $eligibility['reason_codes'] ?? ['missing_member'],
            'inside_window' => $insideWindow,
            'registration_respected' => $registrationRespected,
            'terms_match' => $termsMatch,
            'due_date_matches' => $expectedDueDate !== null && $invoice->data_vencimento?->toDateString() === $expectedDueDate->toDateString(),
            'hidden_matches' => $expectedHidden !== null && (bool) $invoice->oculta === $expectedHidden,
            'legacy_origin' => $this->hasLegacyOrigin($invoice),
            'expected' => [
                'eligible' => true,
                'inside_window' => true,
                'registration_respected' => true,
                'monthly_terms_match' => true,
                'data_vencimento' => $expectedDueDate?->toDateString(),
                'oculta' => $expectedHidden,
                'window_start' => $window['start']->format('Y-m'),
                'window_end' => $window['end']->format('Y-m'),
                'origem_tipo' => 'monthly_fee',
                'origem_id' => $user?->dadosFinanceiros?->mensalidade_id,
            ],
            'actual' => [
                'eligible' => $eligible,
                'eligibility_reasons' => $eligibility['reason_codes'] ?? ['missing_member'],
                'inside_window' => $insideWindow,
                'registration_respected' => $registrationRespected,
                'monthly_terms_match' => $termsMatch,
                'data_vencimento' => $invoice->data_vencimento?->toDateString(),
                'oculta' => (bool) $invoice->oculta,
                'origem_tipo' => $invoice->origem_tipo,
                'origem_id' => $invoice->origem_id,
                'items' => $invoice->items->map(static fn ($item): array => [
                    'descricao' => (string) $item->descricao,
                    'centro_custo_id' => $item->centro_custo_id ? (string) $item->centro_custo_id : null,
                    'total_linha' => round((float) $item->total_linha, 2),
                ])->values()->all(),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @return list<array<string,mixed>>
     */
    private function findingsForInvoice(array $diagnostic): array
    {
        $findings = [];

        if (! $diagnostic['eligible']) {
            $findings[] = $this->finding('future_invoice_for_ineligible_member', $diagnostic);
        }

        if (! $diagnostic['inside_window']) {
            $findings[] = $this->finding('future_invoice_outside_current_window', $diagnostic);
        }

        if (! $diagnostic['terms_match']) {
            $findings[] = $this->finding('future_invoice_terms_diverge', $diagnostic);
        }

        if (! $diagnostic['due_date_matches'] || ! $diagnostic['hidden_matches'] || ! $diagnostic['registration_respected']) {
            $findings[] = $this->finding('future_invoice_cycle_projection_diverges', $diagnostic);
        }

        if ($diagnostic['legacy_origin']) {
            $findings[] = $this->finding(
                'future_invoice_legacy_origin',
                $diagnostic,
                $diagnostic['reconcilable'] ? 'warning' : 'info',
                'consider_backfill_origin_only_future_projection',
            );
        }

        if ($findings === []) {
            $findings[] = $this->finding(
                'future_invoice_projection_ok',
                $diagnostic,
                'info',
                'no_action_needed',
            );
        }

        return $findings;
    }

    /**
     * @param Collection<int,Invoice> $invoices
     * @param array<string,array<string,mixed>> $diagnostics
     * @return list<array<string,mixed>>
     */
    private function duplicateFindings(Collection $invoices, array $diagnostics, bool $onlyReconcilable): array
    {
        $findings = [];

        $invoices
            ->groupBy(fn (Invoice $invoice): string => (string) $invoice->user_id . '|' . (string) $invoice->mes)
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->each(function (Collection $group) use (&$findings, $diagnostics, $onlyReconcilable): void {
                $duplicateDiagnostics = $group
                    ->map(fn (Invoice $invoice): ?array => $diagnostics[(string) $invoice->id] ?? null)
                    ->filter()
                    ->values();
                $hasReconcilable = $duplicateDiagnostics->contains(fn (array $diagnostic): bool => (bool) $diagnostic['reconcilable']);

                if ($onlyReconcilable && ! $hasReconcilable) {
                    return;
                }

                $base = $duplicateDiagnostics->first();
                if (! is_array($base)) {
                    return;
                }

                $actual = $base['actual'];
                $actual['duplicate_invoice_ids'] = $group->pluck('id')->map('strval')->values()->all();
                $actual['duplicate_count'] = $group->count();
                $base['actual'] = $actual;

                $findings[] = $this->finding(
                    'duplicate_active_future_monthly_invoice',
                    $base,
                    $hasReconcilable ? 'critical' : 'warning',
                    $hasReconcilable ? 'cancel_or_regenerate_via_existing_lifecycle' : 'review_manually_protected_invoice',
                );
            });

        return $findings;
    }

    /**
     * @param array<string,mixed> $diagnostic
     * @return array<string,mixed>
     */
    private function finding(string $code, array $diagnostic, ?string $severity = null, ?string $recommendation = null): array
    {
        $severity ??= $diagnostic['reconcilable'] ? 'critical' : 'warning';
        $recommendation ??= $diagnostic['reconcilable']
            ? 'cancel_or_regenerate_via_existing_lifecycle'
            : 'review_manually_protected_invoice';

        return [
            'code' => $code,
            'severity' => $severity,
            'invoice_id' => $diagnostic['invoice_id'],
            'user_id' => $diagnostic['user_id'],
            'member_name' => $diagnostic['member_name'],
            'mes' => $diagnostic['mes'],
            'estado_pagamento' => $diagnostic['estado_pagamento'],
            'valor_total' => $diagnostic['valor_total'],
            'valor_em_aberto' => $diagnostic['valor_em_aberto'],
            'oculta' => $diagnostic['oculta'],
            'data_vencimento' => $diagnostic['data_vencimento'],
            'reconcilable' => $diagnostic['reconcilable'],
            'protection_reasons' => $diagnostic['protection_reasons'],
            'expected' => $diagnostic['expected'],
            'actual' => $diagnostic['actual'],
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function summary(int $total, int $reconcilable, int $protected, array $findings): array
    {
        return [
            'total_future_monthly_invoices' => $total,
            'total_reconcilable' => $reconcilable,
            'total_protected' => $protected,
            'total_findings' => count($findings),
            'critical_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical')),
            'warning_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning')),
            'info_count' => count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'info')),
        ];
    }

    private function hasLegacyOrigin(Invoice $invoice): bool
    {
        $originType = $this->normalizeNullableString($invoice->origem_tipo);
        $originId = $this->normalizeNullableString($invoice->origem_id);

        return $originType === null || $originType === 'manual' || $originId === null;
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function registrationDateAllowsPeriod(?User $user, ?Carbon $periodStart, array $settings): bool
    {
        if ($user === null || $periodStart === null) {
            return false;
        }

        if (($settings['respect_registration_date'] ?? true) !== true) {
            return true;
        }

        $signupMonth = $user->data_inscricao?->copy()?->startOfMonth();

        return $signupMonth === null || ! $periodStart->lessThan($signupMonth);
    }

    private function periodStart(Invoice $invoice): ?Carbon
    {
        $month = trim((string) $invoice->mes);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();
    }

    private function memberName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        return $this->normalizeNullableString($user->name)
            ?? (string) $user->id;
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

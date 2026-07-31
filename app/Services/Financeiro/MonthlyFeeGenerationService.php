<?php

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MonthlyFeeGenerationService
{
    public function __construct(
        private readonly MonthlyFeeSettingsService $settingsService,
        private readonly MemberCostCenterResolver $memberCostCenterResolver,
        private readonly MemberMonthlyFeeResolver $memberMonthlyFeeResolver,
        private readonly MemberMonthlyFeeEligibilityService $memberMonthlyFeeEligibilityService,
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
    ) {
    }

    public function generateForUser(User $user, Carbon $start, Carbon $end, array $options = []): Collection
    {
        return $this->generateForUserWithSummary($user, $start, $end, array_merge($options, [
            'load_created_items' => true,
        ]))['created'];
    }

    /**
     * @return array<string, mixed>
     */
    public function generateForUserWithSummary(User $user, Carbon $start, Carbon $end, array $options = []): array
    {
        $user->loadMissing(['dadosFinanceiros.mensalidade', 'centrosCusto', 'dadosPessoais']);
        $settings = $this->resolveSettings($options);
        $summary = $this->emptySummary();
        $summary['users_processed'] = 1;

        if ($this->shouldBlockAutomaticGeneration($settings, $options)) {
            $summary['generation_disabled'] = true;

            return $summary;
        }

        $plan = $this->resolveMonthlyFeePlan($user);
        if (!$plan) {
            $summary['skipped_without_plan'] = 1;
            $summary['skipped_users'][] = $this->skippedUser($user, 'missing_monthly_fee_plan');

            return $summary;
        }

        if (!$this->isEligibleUser($user, $options)) {
            $eligibility = $this->memberMonthlyFeeEligibilityService->evaluate($user);
            $summary['skipped_ineligible_count'] = 1;
            $summary['skipped_users'][] = $this->skippedUser(
                $user,
                'ineligible_member',
                is_array($eligibility['reason_codes'] ?? null) ? $eligibility['reason_codes'] : [],
            );

            return $summary;
        }

        if (!empty($options['monthly_fee_id']) && $plan->id !== (string) $options['monthly_fee_id']) {
            $summary['skipped_plan_filter_count'] = 1;
            $summary['skipped_users'][] = $this->skippedUser($user, 'monthly_fee_plan_filter_mismatch');

            return $summary;
        }

        $effectiveStart = $this->resolveEffectiveStart($user, $start, $options);
        if (!$effectiveStart) {
            $summary['skipped_without_start'] = 1;
            $summary['skipped_users'][] = $this->skippedUser($user, 'missing_generation_start');

            return $summary;
        }

        $effectiveEnd = $end->copy()->startOfMonth();
        if ($effectiveStart->greaterThan($effectiveEnd)) {
            $summary['skipped_outside_period_count'] = 1;
            $summary['skipped_users'][] = $this->skippedUser($user, 'registration_after_generation_period');

            return $summary;
        }

        $existingPeriods = Invoice::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'mensalidade')
            ->where('estado_pagamento', '!=', 'cancelado')
            ->whereBetween('mes', [$effectiveStart->format('Y-m'), $effectiveEnd->format('Y-m')])
            ->pluck('mes')
            ->filter()
            ->all();

        $existingPeriods = array_fill_keys($existingPeriods, true);
        $generated = collect();
        $today = isset($options['today']) && $options['today'] instanceof Carbon
            ? $options['today']->copy()->startOfDay()
            : Carbon::today();
        $loadCreatedItems = (bool) ($options['load_created_items'] ?? false);

        if (! $loadCreatedItems && DB::connection()->getDriverName() !== 'sqlite') {
            $generated = $this->createMonthlyInvoicesBatch(
                $user,
                $plan,
                $effectiveStart,
                $effectiveEnd,
                $today,
                $existingPeriods,
                $options,
            );

            if ($generated->isNotEmpty()) {
                $this->forgetUserFinanceCaches($user->id);
            }

            $summary['created'] = $generated;
            $summary['created_count'] = $generated->count();
            $summary['users_with_new_fees'] = $generated->isNotEmpty() ? 1 : 0;
            $summary['created_invoice_ids'] = $generated->pluck('id')->all();
            $summary['skipped_existing_count'] = count($existingPeriods);
            $summary['future_hidden_count'] = $generated->where('oculta', true)->count();

            return $summary;
        }

        DB::transaction(function () use ($user, $plan, $effectiveStart, $effectiveEnd, $today, $existingPeriods, $options, $loadCreatedItems, &$generated): void {
            $cursor = $effectiveStart->copy();

            while ($cursor->lessThanOrEqualTo($effectiveEnd)) {
                $periodKey = $cursor->format('Y-m');
                if (isset($existingPeriods[$periodKey])) {
                    $cursor->addMonthNoOverflow();
                    continue;
                }

                $generated->push($this->createMonthlyInvoice($user, $plan, $cursor, $today, $options, $loadCreatedItems));
                $cursor->addMonthNoOverflow();
            }
        });

        if ($generated->isNotEmpty()) {
            $this->forgetUserFinanceCaches($user->id);
        }

        $summary['created'] = $generated;
        $summary['created_count'] = $generated->count();
        $summary['users_with_new_fees'] = $generated->isNotEmpty() ? 1 : 0;
        $summary['created_invoice_ids'] = $generated->pluck('id')->all();
        $summary['skipped_existing_count'] = count($existingPeriods);
        $summary['future_hidden_count'] = $generated->where('oculta', true)->count();

        return $summary;
    }

    private function createMonthlyInvoicesBatch(
        User $user,
        MonthlyFee $plan,
        Carbon $effectiveStart,
        Carbon $effectiveEnd,
        Carbon $today,
        array $existingPeriods,
        array $options = [],
    ): Collection {
        $createdInvoices = [];
        $invoiceRows = [];
        $itemRows = [];
        $timestamp = now();
        $cursor = $effectiveStart->copy();

        DB::transaction(function () use (
            $user,
            $plan,
            $today,
            $existingPeriods,
            $options,
            &$createdInvoices,
            &$invoiceRows,
            &$itemRows,
            $timestamp,
            &$cursor,
            $effectiveEnd,
        ): void {
            while ($cursor->lessThanOrEqualTo($effectiveEnd)) {
                $periodKey = $cursor->format('Y-m');
                if (isset($existingPeriods[$periodKey])) {
                    $cursor->addMonthNoOverflow();
                    continue;
                }

                $invoiceId = (string) Str::uuid();
                $payload = $this->buildMonthlyInvoicePayload($invoiceId, $user, $plan, $cursor, $today, $options, $timestamp);

                $invoiceRows[] = $payload['invoice'];
                array_push($itemRows, ...$payload['items']);
                $createdInvoices[] = $payload['summary'];

                $cursor->addMonthNoOverflow();
            }

            if ($invoiceRows !== []) {
                Invoice::query()->insert($invoiceRows);
            }

            if ($itemRows !== []) {
                InvoiceItem::query()->insert($itemRows);
            }
        });

        return collect($createdInvoices);
    }

    public function generateForAllEligibleUsers(Carbon $start, Carbon $end, array $filters = []): array
    {
        $settings = $this->resolveSettings($filters);
        $summary = $this->emptySummary();

        if ($this->shouldBlockAutomaticGeneration($settings, $filters)) {
            $summary['generation_disabled'] = true;

            return $summary;
        }

        $query = User::query()
            ->with(['dadosFinanceiros.mensalidade', 'centrosCusto', 'userTypes', 'dadosPessoais'])
            ->where(function ($nested): void {
                $nested
                    ->whereHas('dadosFinanceiros', fn ($financeQuery) => $financeQuery->whereNotNull('mensalidade_id'));
            });

        if (($filters['only_active'] ?? true) === true) {
            $query->where(function ($nested): void {
                $nested
                    ->whereNull('estado')
                    ->orWhere('estado', 'ativo');
            });
        }

        if (!empty($filters['user_ids']) && is_array($filters['user_ids'])) {
            $query->whereIn('id', $filters['user_ids']);
        }

        if (!empty($filters['monthly_fee_id'])) {
            $monthlyFeeId = (string) $filters['monthly_fee_id'];

            $query->where(function ($nested) use ($monthlyFeeId): void {
                $nested
                    ->whereHas('dadosFinanceiros', fn ($financeQuery) => $financeQuery->where('mensalidade_id', $monthlyFeeId));
            });
        }

        $query->chunkById(100, function (Collection $users) use ($start, $end, $filters, &$summary): void {
            foreach ($users as $user) {
                try {
                    $userSummary = $this->generateForUserWithSummary($user, $start, $end, $filters);
                    $summary = $this->mergeSummary($summary, $userSummary);
                } catch (Throwable $exception) {
                    $summary['users_processed']++;
                    $summary['errors'][] = [
                        'user_id' => $user->id,
                        'message' => $exception->getMessage(),
                    ];

                    Log::error('Monthly fee generation failed for user.', [
                        'user_id' => $user->id,
                        'start' => $start->toDateString(),
                        'end' => $end->toDateString(),
                        'exception' => $exception,
                    ]);
                }
            }
        });

        return $summary;
    }

    public function generateConfiguredCycle(array $options = []): array
    {
        $today = isset($options['today']) && $options['today'] instanceof Carbon
            ? $options['today']->copy()->startOfDay()
            : Carbon::today()->startOfDay();
        $window = $this->settingsService->resolveGenerationWindow($today);

        return $this->generateForAllEligibleUsers($window['start'], $window['end'], $options);
    }

    public function generateCurrentSeason(array $options = []): array
    {
        return $this->generateConfiguredCycle($options);
    }

    public function activateDueInvoices(?Carbon $today = null, array $options = []): int
    {
        $settings = $this->resolveSettings($options);
        if ($this->shouldBlockAutomaticActivation($settings, $options)) {
            return 0;
        }

        $referenceDate = ($today ?? Carbon::today())->copy()->startOfDay();

        $updated = Invoice::query()
            ->where('tipo', 'mensalidade')
            ->where('oculta', true)
            ->where('estado_pagamento', '!=', 'cancelado')
            ->whereDate('data_vencimento', '<=', $referenceDate)
            ->update([
                'oculta' => false,
            ]);

        Invoice::query()
            ->where('tipo', 'mensalidade')
            ->where('oculta', false)
            ->where('estado_pagamento', 'pendente')
            ->whereDate('data_vencimento', '<', $referenceDate)
            ->update([
                'estado_pagamento' => 'vencido',
            ]);

        return $updated;
    }

    /**
     * @return array<string, mixed>
     */
    public function runScheduledGeneration(array $options = []): array
    {
        $scheduledOptions = array_merge($options, [
            'respect_generation_setting' => true,
        ]);

        return $this->generateConfiguredCycle($scheduledOptions);
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $options
     */
    private function shouldBlockAutomaticGeneration(array $settings, array $options): bool
    {
        if (($options['manual_trigger'] ?? false) === true) {
            return false;
        }

        if (($options['respect_generation_setting'] ?? false) !== true) {
            return false;
        }

        return ($settings['generation_enabled'] ?? false) !== true;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $options
     */
    private function shouldBlockAutomaticActivation(array $settings, array $options): bool
    {
        if (($options['force'] ?? false) === true) {
            return false;
        }

        if (($options['respect_auto_activation_setting'] ?? false) !== true) {
            return false;
        }

        return ($settings['auto_activate_due'] ?? false) !== true;
    }

    private function createMonthlyInvoice(User $user, MonthlyFee $plan, Carbon $period, Carbon $today, array $options = [], bool $loadItems = false): Invoice
    {
        $invoiceId = (string) Str::uuid();
        $payload = $this->buildMonthlyInvoicePayload($invoiceId, $user, $plan, $period, $today, $options, now());

        $invoice = Invoice::withoutEvents(function () use ($payload): Invoice {
            $invoice = new Invoice($payload['invoice']);
            $invoice->id = $payload['invoice']['id'];
            $invoice->save();

            return $invoice;
        });
        $items = collect();

        foreach ($payload['items'] as $itemPayload) {
            unset($itemPayload['id'], $itemPayload['created_at'], $itemPayload['updated_at']);
            $items->push(InvoiceItem::create($itemPayload));
        }

        if (! $loadItems) {
            return $invoice;
        }

        return $invoice->setRelation('items', $items);
    }

    /**
     * @return array{invoice: array<string, mixed>, items: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    private function buildMonthlyInvoicePayload(string $invoiceId, User $user, MonthlyFee $plan, Carbon $period, Carbon $today, array $options, Carbon $timestamp): array
    {
        if (blank($user->getKey()) || ! User::query()->whereKey($user->getKey())->exists()) {
            throw new \DomainException('Não foi possível determinar um titular válido para a mensalidade.');
        }

        $periodStart = $period->copy()->startOfMonth();
        $settings = $this->resolveSettings($options);
        $dueDate = $this->settingsService->resolveDueDateFromSettings($periodStart, $settings);
        $shouldHide = ($settings['hide_future'] ?? true) === true && $dueDate->greaterThan($today);
        $baseAmount = round((float) $plan->valor, 2);
        $shares = $this->resolveCostCenterShares($user, $baseAmount);
        $adjustment = $this->resolveFinancialAdjustment($user, $baseAmount);
        $finalAmount = round(max(0, $baseAmount - $adjustment['applied_amount']), 2);
        $notes = $options['notes'] ?? sprintf('Mensalidade %s', $periodStart->copy()->locale('pt_PT')->translatedFormat('F Y'));

        if ($adjustment['capped']) {
            $capNote = sprintf(
                'Desconto/correcao limitada ao valor base da mensalidade (base: %.2f; configurado: %.2f; aplicado: %.2f).',
                $baseAmount,
                $adjustment['requested_amount'],
                $adjustment['applied_amount'],
            );

            $notes = trim($notes . ' | ' . $capNote);

            Log::warning('Monthly fee discount capped to avoid negative invoice total.', [
                'user_id' => $user->id,
                'period' => $periodStart->format('Y-m'),
                'base_amount' => $baseAmount,
                'requested_discount' => $adjustment['requested_amount'],
                'applied_discount' => $adjustment['applied_amount'],
            ]);
        }

        $invoiceRow = [
            'id' => $invoiceId,
            'user_id' => $user->id,
            'data_fatura' => $periodStart->toDateString(),
            'mes' => $periodStart->format('Y-m'),
            'data_emissao' => $periodStart->toDateString(),
            'data_vencimento' => $dueDate->toDateString(),
            'valor_total' => $finalAmount,
            'valor_pago' => 0,
            'valor_em_aberto' => $finalAmount,
            'oculta' => $shouldHide,
            'estado_pagamento' => $this->resolveInitialStatus($dueDate, $today),
            'centro_custo_id' => $shares[0]['id'] ?? null,
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee',
            'origem_id' => $plan->id,
            'observacoes' => $notes,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        $itemRows = [];
        foreach ($shares as $share) {
            $itemRows[] = [
                'id' => (string) Str::uuid(),
                'fatura_id' => $invoiceId,
                'descricao' => $plan->designacao,
                'quantidade' => 1,
                'valor_unitario' => $share['amount'],
                'imposto_percentual' => 0,
                'total_linha' => $share['amount'],
                'centro_custo_id' => $share['id'],
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if ($adjustment['applied_amount'] > 0) {
            $itemRows[] = [
                'id' => (string) Str::uuid(),
                'fatura_id' => $invoiceId,
                'descricao' => $adjustment['description'],
                'quantidade' => 1,
                'valor_unitario' => -$adjustment['applied_amount'],
                'imposto_percentual' => 0,
                'total_linha' => -$adjustment['applied_amount'],
                'centro_custo_id' => $shares[0]['id'] ?? null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        return [
            'invoice' => $invoiceRow,
            'items' => $itemRows,
            'summary' => [
                'id' => $invoiceId,
                'mes' => $invoiceRow['mes'],
                'oculta' => $invoiceRow['oculta'],
                'estado_pagamento' => $invoiceRow['estado_pagamento'],
                'valor_total' => $invoiceRow['valor_total'],
            ],
        ];
    }

    private function resolveFinancialAdjustment(User $user, float $baseAmount): array
    {
        $type = $user->dadosFinanceiros?->discount_type;
        $value = round((float) ($user->dadosFinanceiros?->discount_value ?? 0), 2);

        if (!in_array($type, ['percent', 'fixed'], true) || $value <= 0) {
            return [
                'requested_amount' => 0.0,
                'applied_amount' => 0.0,
                'description' => null,
                'capped' => false,
            ];
        }

        $requestedAmount = $type === 'percent'
            ? round($baseAmount * ($value / 100), 2)
            : $value;

        $appliedAmount = round(min($baseAmount, $requestedAmount), 2);

        return [
            'requested_amount' => $requestedAmount,
            'applied_amount' => $appliedAmount,
            'description' => $type === 'percent'
                ? sprintf('Desconto/Correcao %s%%', $this->formatPercentage($value))
                : 'Desconto/Correcao financeira',
            'capped' => $requestedAmount > $baseAmount,
        ];
    }

    private function formatPercentage(float $value): string
    {
        $normalized = number_format($value, 2, '.', '');
        $normalized = rtrim(rtrim($normalized, '0'), '.');

        return $normalized === '' ? '0' : $normalized;
    }

    private function resolveMonthlyFeePlan(User $user): ?MonthlyFee
    {
        $user->loadMissing('dadosFinanceiros.mensalidade');

        $planId = $this->memberMonthlyFeeResolver->resolveForUser($user);
        if ($planId === null) {
            return null;
        }

        $canonicalPlan = $user->dadosFinanceiros?->mensalidade;

        return $canonicalPlan && (string) $canonicalPlan->id === $planId
            ? $canonicalPlan
            : MonthlyFee::query()->find($planId);
    }

    private function resolveEffectiveStart(User $user, Carbon $requestedStart, array $options = []): ?Carbon
    {
        if (!empty($options['start_date'])) {
            return Carbon::parse((string) $options['start_date'])->startOfMonth();
        }

        $settings = $this->resolveSettings($options);
        if (($settings['respect_registration_date'] ?? true) !== true) {
            return $requestedStart->copy()->startOfMonth();
        }

        $signupDate = $user->data_inscricao?->copy()?->startOfMonth();
        if (!$signupDate) {
            return $requestedStart->copy()->startOfMonth();
        }

        return $signupDate->greaterThan($requestedStart)
            ? $signupDate
            : $requestedStart->copy()->startOfMonth();
    }

    private function isEligibleUser(User $user, array $options = []): bool
    {
        if (($options['only_active'] ?? true) === true && !$this->memberMonthlyFeeEligibilityService->shouldHaveMonthlyFee($user)) {
            return false;
        }

        return true;
    }

    private function resolveInitialStatus(Carbon $periodStart, Carbon $today): string
    {
        if ($periodStart->greaterThan($today)) {
            return 'pendente';
        }

        if ($periodStart->isBefore($today)) {
            return 'vencido';
        }

        return 'pendente';
    }

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

        return $shares;
    }

    private function forgetUserFinanceCaches(?string $userId): void
    {
        if (!$userId) {
            return;
        }

        Cache::forget("athlete_dashboard:{$userId}:current_account");
        Cache::forget("athlete_dashboard:{$userId}:pending_invoice");
        Cache::forget("athlete_dashboard:{$userId}:invoices");
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'created' => collect(),
            'created_count' => 0,
            'skipped_existing_count' => 0,
            'skipped_without_start' => 0,
            'skipped_without_plan' => 0,
            'skipped_ineligible_count' => 0,
            'skipped_plan_filter_count' => 0,
            'skipped_outside_period_count' => 0,
            'skipped_users' => [],
            'users_processed' => 0,
            'users_with_new_fees' => 0,
            'future_hidden_count' => 0,
            'activated_count' => 0,
            'generation_disabled' => false,
            'created_invoice_ids' => [],
            'errors' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeSummary(array $base, array $extra): array
    {
        $base['users_processed'] += (int) ($extra['users_processed'] ?? 0);
        $base['users_with_new_fees'] += (int) ($extra['users_with_new_fees'] ?? 0);
        $base['created_count'] += (int) ($extra['created_count'] ?? 0);
        $base['skipped_existing_count'] += (int) ($extra['skipped_existing_count'] ?? 0);
        $base['skipped_without_start'] += (int) ($extra['skipped_without_start'] ?? 0);
        $base['skipped_without_plan'] += (int) ($extra['skipped_without_plan'] ?? 0);
        $base['skipped_ineligible_count'] += (int) ($extra['skipped_ineligible_count'] ?? 0);
        $base['skipped_plan_filter_count'] += (int) ($extra['skipped_plan_filter_count'] ?? 0);
        $base['skipped_outside_period_count'] += (int) ($extra['skipped_outside_period_count'] ?? 0);
        $base['future_hidden_count'] += (int) ($extra['future_hidden_count'] ?? 0);
        $base['activated_count'] += (int) ($extra['activated_count'] ?? 0);
        $base['generation_disabled'] = $base['generation_disabled'] || (bool) ($extra['generation_disabled'] ?? false);
        $base['created_invoice_ids'] = array_values(array_unique(array_merge(
            $base['created_invoice_ids'],
            $extra['created_invoice_ids'] ?? [],
        )));
        $base['errors'] = array_values(array_merge($base['errors'], $extra['errors'] ?? []));
        $base['skipped_users'] = array_values(array_merge($base['skipped_users'], $extra['skipped_users'] ?? []));

        return $base;
    }

    /**
     * @param list<string> $detailReasons
     * @return array{user_id:string,name:string,reason:string,detail_reasons:list<string>}
     */
    private function skippedUser(User $user, string $reason, array $detailReasons = []): array
    {
        return [
            'user_id' => (string) $user->id,
            'name' => $this->memberIdentityDisplayResolver->displayName($user),
            'reason' => $reason,
            'detail_reasons' => array_values(array_unique(array_filter(
                $detailReasons,
                static fn (mixed $detail): bool => is_string($detail) && trim($detail) !== '',
            ))),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function resolveSettings(array $options): array
    {
        $settings = isset($options['settings']) && is_array($options['settings'])
            ? $this->settingsService->normalize($options['settings'])
            : $this->settingsService->get();

        foreach ([
            'generation_enabled',
            'start_month',
            'end_month',
            'due_day',
            'hide_future',
            'auto_activate_due',
            'respect_registration_date',
            'generate_months_ahead',
            'default_period_mode',
        ] as $key) {
            if (array_key_exists($key, $options)) {
                $settings[$key] = $options[$key];
            }
        }

        return $this->settingsService->normalize([
            'monthly_fee_generation_enabled' => $settings['generation_enabled'] ?? null,
            'monthly_fee_start_month' => $settings['start_month'] ?? null,
            'monthly_fee_end_month' => $settings['end_month'] ?? null,
            'monthly_fee_due_day' => $settings['due_day'] ?? null,
            'monthly_fee_hide_future' => $settings['hide_future'] ?? null,
            'monthly_fee_auto_activate_due' => $settings['auto_activate_due'] ?? null,
            'monthly_fee_respect_registration_date' => $settings['respect_registration_date'] ?? null,
            'monthly_fee_generate_months_ahead' => $settings['generate_months_ahead'] ?? null,
            'monthly_fee_default_period_mode' => $settings['default_period_mode'] ?? null,
        ]);
    }
}

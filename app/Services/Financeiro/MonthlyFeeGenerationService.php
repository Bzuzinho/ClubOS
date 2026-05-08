<?php

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MonthlyFee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MonthlyFeeGenerationService
{
    public function generateForUser(User $user, Carbon $start, Carbon $end, array $options = []): Collection
    {
        $user->loadMissing(['dadosFinanceiros.mensalidade', 'centrosCusto']);

        $plan = $this->resolveMonthlyFeePlan($user);
        if (!$plan || !$this->isEligibleUser($user, $options)) {
            return collect();
        }

        $effectiveStart = $this->resolveEffectiveStart($user, $start, $options, false);
        if (!$effectiveStart) {
            return collect();
        }

        $effectiveEnd = $end->copy()->startOfMonth();
        if ($effectiveStart->greaterThan($effectiveEnd)) {
            return collect();
        }

        $existingPeriods = Invoice::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'mensalidade')
            ->whereBetween('mes', [$effectiveStart->format('Y-m'), $effectiveEnd->format('Y-m')])
            ->pluck('mes')
            ->filter()
            ->all();

        $existingPeriods = array_fill_keys($existingPeriods, true);
        $generated = collect();
        $today = isset($options['today']) && $options['today'] instanceof Carbon
            ? $options['today']->copy()->startOfDay()
            : Carbon::today();

        DB::transaction(function () use ($user, $plan, $effectiveStart, $effectiveEnd, $today, $existingPeriods, $options, &$generated): void {
            $cursor = $effectiveStart->copy();

            while ($cursor->lessThanOrEqualTo($effectiveEnd)) {
                $periodKey = $cursor->format('Y-m');
                if (isset($existingPeriods[$periodKey])) {
                    $cursor->addMonthNoOverflow();
                    continue;
                }

                $generated->push($this->createMonthlyInvoice($user, $plan, $cursor, $today, $options));
                $cursor->addMonthNoOverflow();
            }
        });

        return $generated;
    }

    public function generateForAllEligibleUsers(Carbon $start, Carbon $end, array $filters = []): array
    {
        $query = User::query()
            ->with(['dadosFinanceiros.mensalidade', 'centrosCusto'])
            ->where(function ($nested): void {
                $nested
                    ->whereNotNull('tipo_mensalidade')
                    ->orWhereHas('dadosFinanceiros', fn ($financeQuery) => $financeQuery->whereNotNull('mensalidade_id'));
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

        $summary = [
            'created_count' => 0,
            'skipped_without_start' => 0,
            'skipped_without_plan' => 0,
            'users_processed' => 0,
            'users_with_new_fees' => 0,
            'created_invoice_ids' => [],
        ];

        $query->orderBy('nome_completo')->chunkById(100, function (Collection $users) use ($start, $end, $filters, &$summary): void {
            foreach ($users as $user) {
                $summary['users_processed']++;

                if (!$this->resolveMonthlyFeePlan($user)) {
                    $summary['skipped_without_plan']++;
                    continue;
                }

                if (!$this->resolveEffectiveStart($user, $start, $filters, false)) {
                    $summary['skipped_without_start']++;
                    continue;
                }

                $created = $this->generateForUser($user, $start, $end, $filters);
                if ($created->isNotEmpty()) {
                    $summary['users_with_new_fees']++;
                    $summary['created_count'] += $created->count();
                    array_push($summary['created_invoice_ids'], ...$created->pluck('id')->all());
                }
            }
        });

        return $summary;
    }

    public function generateCurrentSeason(array $options = []): array
    {
        $today = isset($options['today']) && $options['today'] instanceof Carbon
            ? $options['today']->copy()
            : Carbon::today();
        $seasonStartYear = $today->month >= 7 ? $today->year : $today->year - 1;

        return $this->generateForAllEligibleUsers(
            Carbon::create($seasonStartYear, 7, 1)->startOfMonth(),
            Carbon::create($seasonStartYear + 1, 6, 1)->startOfMonth(),
            $options,
        );
    }

    public function activateDueInvoices(?Carbon $today = null): int
    {
        $referenceDate = ($today ?? Carbon::today())->copy()->startOfDay();

        return Invoice::query()
            ->where('tipo', 'mensalidade')
            ->where('oculta', true)
            ->whereDate('data_vencimento', '<=', $referenceDate)
            ->update([
                'oculta' => false,
            ]);
    }

    private function createMonthlyInvoice(User $user, MonthlyFee $plan, Carbon $period, Carbon $today, array $options = []): Invoice
    {
        $periodStart = $period->copy()->startOfMonth();
        $isFuture = $periodStart->greaterThan($today->copy()->startOfMonth());
        $shares = $this->resolveCostCenterShares($user, (float) $plan->valor);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => $periodStart->toDateString(),
            'mes' => $periodStart->format('Y-m'),
            'data_emissao' => $periodStart->toDateString(),
            'data_vencimento' => $periodStart->toDateString(),
            'valor_total' => round((float) $plan->valor, 2),
            'valor_pago' => 0,
            'valor_em_aberto' => round((float) $plan->valor, 2),
            'oculta' => $isFuture,
            'estado_pagamento' => $this->resolveInitialStatus($periodStart, $today),
            'centro_custo_id' => $shares[0]['id'] ?? null,
            'tipo' => 'mensalidade',
            'origem_tipo' => 'manual',
            'observacoes' => $options['notes'] ?? sprintf('Mensalidade %s', $periodStart->translatedFormat('F Y')),
        ]);

        foreach ($shares as $share) {
            InvoiceItem::create([
                'fatura_id' => $invoice->id,
                'descricao' => $plan->designacao,
                'quantidade' => 1,
                'valor_unitario' => $share['amount'],
                'imposto_percentual' => 0,
                'total_linha' => $share['amount'],
                'centro_custo_id' => $share['id'],
            ]);
        }

        return $invoice->fresh('items');
    }

    private function resolveMonthlyFeePlan(User $user): ?MonthlyFee
    {
        $planId = $user->dadosFinanceiros?->mensalidade_id ?? $user->tipo_mensalidade;

        return $planId
            ? ($user->dadosFinanceiros?->mensalidade ?? MonthlyFee::query()->find($planId))
            : null;
    }

    private function resolveEffectiveStart(User $user, Carbon $requestedStart, array $options = [], bool $fallbackToRequest = true): ?Carbon
    {
        if (!empty($options['start_date'])) {
            return Carbon::parse((string) $options['start_date'])->startOfMonth();
        }

        $signupDate = $user->data_inscricao?->copy()?->startOfMonth();
        if (!$signupDate) {
            return $fallbackToRequest ? $requestedStart->copy()->startOfMonth() : null;
        }

        return $signupDate->greaterThan($requestedStart)
            ? $signupDate
            : $requestedStart->copy()->startOfMonth();
    }

    private function isEligibleUser(User $user, array $options = []): bool
    {
        if (($options['only_active'] ?? true) === true && $user->estado !== null && $user->estado !== 'ativo') {
            return false;
        }

        return $this->resolveMonthlyFeePlan($user) !== null;
    }

    private function resolveInitialStatus(Carbon $periodStart, Carbon $today): string
    {
        if ($periodStart->greaterThan($today->copy()->startOfMonth())) {
            return 'pendente';
        }

        if ($periodStart->isBefore($today)) {
            return 'vencido';
        }

        return 'pendente';
    }

    private function resolveCostCenterShares(User $user, float $totalAmount): array
    {
        $shares = [];

        if ($user->relationLoaded('centrosCusto') && $user->centrosCusto->isNotEmpty()) {
            foreach ($user->centrosCusto as $center) {
                $shares[] = [
                    'id' => $center->id,
                    'weight' => (float) ($center->pivot->peso ?? 1),
                ];
            }
        } else {
            foreach ((array) ($user->centro_custo ?? []) as $center) {
                $shares[] = [
                    'id' => is_array($center) ? ($center['id'] ?? null) : $center,
                    'weight' => is_array($center) ? (float) ($center['peso'] ?? 1) : 1.0,
                ];
            }
        }

        $shares = array_values(array_filter($shares, fn (array $share) => !empty($share['id'])));
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
}
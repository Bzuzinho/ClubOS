<?php

namespace App\Services\Financeiro;

use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Movement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinanceReportQueryService
{
    public function visibleMonthlyInvoices(array $filters = []): Builder
    {
        return Invoice::query()
            ->where('tipo', 'mensalidade')
            ->where('oculta', false)
            ->when(!empty($filters['centro_custo_id']), fn (Builder $query) => $query->where('centro_custo_id', $filters['centro_custo_id']));
    }

    public function overdueMonthlyInvoices(array $filters = []): Builder
    {
        $today = $this->resolveReferenceDate($filters);

        return $this->visibleMonthlyInvoices($filters)
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
            ->whereDate('data_vencimento', '<', $today->toDateString());
    }

    public function paidMonthlyInvoices(?Carbon $start = null, ?Carbon $end = null, array $filters = []): Builder
    {
        return $this->visibleMonthlyInvoices($filters)
            ->where('estado_pagamento', 'pago')
            ->whereNotNull('data_pagamento')
            ->when($start && $end, fn (Builder $query) => $query->whereBetween('data_pagamento', [$start->toDateString(), $end->toDateString()]));
    }

    public function canonicalFinancialEntries(array $filters = []): Builder
    {
        return FinancialEntry::query()
            ->whereNull('fatura_id')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('origem_tipo')
                    ->orWhereNotIn('origem_tipo', ['payment_allocation', 'account_credit']);
            })
            ->when(!empty($filters['centro_custo_id']), fn (Builder $query) => $query->where('centro_custo_id', $filters['centro_custo_id']));
    }

    public function paidCanonicalFinancialEntries(string $type, ?Carbon $start = null, ?Carbon $end = null, array $filters = []): Builder
    {
        return $this->canonicalFinancialEntries($filters)
            ->where('tipo', $type)
            ->where('estado', 'pago')
            ->whereNotNull('data_pagamento')
            ->when($start && $end, fn (Builder $query) => $query->whereBetween('data_pagamento', [$start->toDateString(), $end->toDateString()]));
    }

    public function pendingCanonicalFinancialEntries(array $filters = []): Builder
    {
        return $this->canonicalFinancialEntries($filters)
            ->whereIn('estado', ['pendente', 'parcial']);
    }

    public function legacyMovementsWithoutEntries(array $filters = []): Builder
    {
        return Movement::query()
            ->whereDoesntHave('financialEntries')
            ->when(!empty($filters['centro_custo_id']), fn (Builder $query) => $query->where('centro_custo_id', $filters['centro_custo_id']));
    }

    public function paidLegacyMovements(string $type, ?Carbon $start = null, ?Carbon $end = null, array $filters = []): Builder
    {
        return $this->legacyMovementsWithoutEntries($filters)
            ->where('classificacao', $type)
            ->where('estado_pagamento', 'pago')
            ->when($start && $end, fn (Builder $query) => $query->whereBetween('data_emissao', [$start->toDateString(), $end->toDateString()]));
    }

    public function pendingLegacyMovements(array $filters = []): Builder
    {
        return $this->legacyMovementsWithoutEntries($filters)
            ->whereIn('estado_pagamento', ['pendente', 'parcial']);
    }

    public function costCenters(): Collection
    {
        return CostCenter::query()->orderBy('nome')->get(['id', 'nome']);
    }

    private function resolveReferenceDate(array $filters): Carbon
    {
        if (!empty($filters['reference_date'])) {
            return Carbon::parse($filters['reference_date'])->startOfDay();
        }

        return Carbon::today();
    }
}
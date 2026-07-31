<?php

namespace App\Services\Financeiro;

use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Movement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class FinancialReportingFactService
{
    public function paidFacts(?Carbon $start = null, ?Carbon $end = null, array $filters = []): Collection
    {
        [$startDate, $endDate] = $this->normalizeDateRange($start, $end);

        $paidInvoices = $this->paidInvoicesQuery($filters)
            ->when($startDate && $endDate, fn (Builder $query) => $query->whereBetween('data_pagamento', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]))
            ->get([
                'id',
                'tipo',
                'origem_tipo',
                'origem_id',
                'valor_total',
                'valor_pago',
                'data_pagamento',
                'user_id',
                'centro_custo_id',
            ]);

        $invoiceFacts = $paidInvoices
            ->map(function (Invoice $invoice): ?array {
                $amount = $this->toPositiveAmount($invoice->valor_pago ?? $invoice->valor_total, 'invoice', (string) $invoice->id);
                if ($amount <= 0.0) {
                    return null;
                }

                return [
                    'source_kind' => 'invoice',
                    'source_id' => (string) $invoice->id,
                    'type' => 'receita',
                    'amount' => $amount,
                    'paid_at' => Carbon::parse($invoice->data_pagamento),
                    'user_id' => $invoice->user_id,
                    'centro_custo_id' => $invoice->centro_custo_id,
                    'origem_modulo' => $invoice->origem_tipo ?? 'invoice',
                    'origem_tipo' => $invoice->tipo ?? 'invoice',
                    '__origin_key' => $this->originKey($invoice->origem_tipo, $invoice->origem_id),
                ];
            })
            ->filter()
            ->values();

        $includedInvoiceIds = $invoiceFacts
            ->pluck('source_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $paidEntries = $this->paidEntriesQuery($filters, $includedInvoiceIds)
            ->when($startDate && $endDate, fn (Builder $query) => $query->whereBetween('data_pagamento', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]))
            ->get([
                'id',
                'tipo',
                'valor',
                'valor_pago',
                'data_pagamento',
                'user_id',
                'centro_custo_id',
                'origem_modulo',
                'origem_tipo',
                'origem_id',
                'fatura_id',
            ]);

        $entryFacts = $paidEntries
            ->map(function (FinancialEntry $entry): ?array {
                $type = $this->normalizeType($entry->tipo);
                if ($type === null) {
                    return null;
                }

                $amount = $this->toPositiveAmount($entry->valor_pago ?? $entry->valor, 'financial_entry', (string) $entry->id);
                if ($amount <= 0.0) {
                    return null;
                }

                return [
                    'source_kind' => 'financial_entry',
                    'source_id' => (string) $entry->id,
                    'type' => $type,
                    'amount' => $amount,
                    'paid_at' => Carbon::parse($entry->data_pagamento),
                    'user_id' => $entry->user_id,
                    'centro_custo_id' => $entry->centro_custo_id,
                    'origem_modulo' => $entry->origem_modulo ?? 'financeiro',
                    'origem_tipo' => $entry->origem_tipo ?? 'financial_entry',
                    '__origin_key' => $this->originKey($entry->origem_tipo, $entry->origem_id),
                ];
            })
            ->filter()
            ->values();

        $coveredOriginKeys = $invoiceFacts
            ->pluck('__origin_key')
            ->merge($entryFacts->pluck('__origin_key'))
            ->filter()
            ->unique()
            ->values();

        $movementIdsWithCanonicalEntry = FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->whereNotNull('origem_id')
            ->pluck('origem_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $paidMovements = $this->paidMovementsQuery($filters)
            ->when($startDate && $endDate, fn (Builder $query) => $query->whereBetween('data_emissao', [$startDate->toDateTimeString(), $endDate->toDateTimeString()]))
            ->get([
                'id',
                'classificacao',
                'valor_total',
                'data_emissao',
                'user_id',
                'centro_custo_id',
                'origem_tipo',
                'origem_id',
            ]);

        $movementFacts = $paidMovements
            ->reject(function (Movement $movement) use ($movementIdsWithCanonicalEntry, $coveredOriginKeys): bool {
                if (in_array((string) $movement->id, $movementIdsWithCanonicalEntry, true)) {
                    return true;
                }

                $originKey = $this->originKey($movement->origem_tipo, $movement->origem_id);

                return $originKey !== null && $coveredOriginKeys->contains($originKey);
            })
            ->map(function (Movement $movement): ?array {
                $type = $this->normalizeType($movement->classificacao);
                if ($type === null) {
                    return null;
                }

                $amount = $this->toPositiveAmount($movement->valor_total, 'movement', (string) $movement->id);
                if ($amount <= 0.0) {
                    return null;
                }

                return [
                    'source_kind' => 'movement',
                    'source_id' => (string) $movement->id,
                    'type' => $type,
                    'amount' => $amount,
                    'paid_at' => Carbon::parse($movement->data_emissao),
                    'user_id' => $movement->user_id,
                    'centro_custo_id' => $movement->centro_custo_id,
                    'origem_modulo' => 'movement_legacy',
                    'origem_tipo' => $movement->origem_tipo ?? 'movement',
                    '__origin_key' => $this->originKey($movement->origem_tipo, $movement->origem_id),
                ];
            })
            ->filter()
            ->values();

        return $this->applyFactFilters(
            $invoiceFacts
                ->concat($entryFacts)
                ->concat($movementFacts)
                ->values(),
            $filters,
        )->map(function (array $fact): array {
            unset($fact['__origin_key']);

            return $fact;
        })->values();
    }

    private function paidInvoicesQuery(array $filters): Builder
    {
        return Invoice::query()
            ->whereNotIn('estado_pagamento', ['cancelada'])
            ->where('estado_pagamento', 'pago')
            ->whereNotNull('data_pagamento')
            ->where(function (Builder $query): void {
                $query->whereNull('oculta')->orWhere('oculta', false);
            })
            ->where('valor_pago', '>', 0)
            ->when(!empty($filters['user_id']), fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when(!empty($filters['centro_custo_id']), fn (Builder $query) => $query->where('centro_custo_id', $filters['centro_custo_id']));
    }

    /**
     * @param list<string> $includedInvoiceIds
     */
    private function paidEntriesQuery(array $filters, array $includedInvoiceIds): Builder
    {
        return FinancialEntry::query()
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('origem_tipo')
                    ->orWhereNotIn('origem_tipo', ['payment_allocation', 'account_credit']);
            })
            ->where('estado', 'pago')
            ->whereNotNull('data_pagamento')
            ->where(function (Builder $query): void {
                $query->where('valor_pago', '!=', 0)->orWhere('valor', '!=', 0);
            })
            ->when(!empty($includedInvoiceIds), function (Builder $query) use ($includedInvoiceIds): void {
                $query->where(function (Builder $nested) use ($includedInvoiceIds): void {
                    $nested->whereNull('fatura_id')->orWhereNotIn('fatura_id', $includedInvoiceIds);
                });
            })
            ->when(!empty($filters['user_id']), fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when(!empty($filters['centro_custo_id']), fn (Builder $query) => $query->where('centro_custo_id', $filters['centro_custo_id']));
    }

    private function paidMovementsQuery(array $filters): Builder
    {
        return Movement::query()
            ->where('estado_pagamento', 'pago')
            ->where('valor_total', '!=', 0)
            ->when(!empty($filters['user_id']), fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when(!empty($filters['centro_custo_id']), fn (Builder $query) => $query->where('centro_custo_id', $filters['centro_custo_id']));
    }

    private function applyFactFilters(Collection $facts, array $filters): Collection
    {
        return $facts
            ->when(!empty($filters['tipo']), fn (Collection $collection) => $collection->where('type', $filters['tipo']))
            ->when(!empty($filters['origem_modulo']), fn (Collection $collection) => $collection->where('origem_modulo', $filters['origem_modulo']))
            ->when(!empty($filters['origem_tipo']), fn (Collection $collection) => $collection->where('origem_tipo', $filters['origem_tipo']))
            ->values();
    }

    private function normalizeType(mixed $type): ?string
    {
        $normalized = is_string($type) ? strtolower(trim($type)) : '';

        return in_array($normalized, ['receita', 'despesa'], true)
            ? $normalized
            : null;
    }

    private function toPositiveAmount(mixed $value, string $sourceKind, string $sourceId): float
    {
        $raw = (float) ($value ?? 0);

        if ($raw < 0) {
            Log::warning('financial_reporting_negative_amount_normalized', [
                'source_kind' => $sourceKind,
                'source_id' => $sourceId,
                'raw_amount' => $raw,
            ]);
        }

        return round(abs($raw), 2);
    }

    private function originKey(mixed $originType, mixed $originId): ?string
    {
        if ($originType === null || $originId === null || $originType === '' || $originId === '') {
            return null;
        }

        return strtolower((string) $originType).'|'.(string) $originId;
    }

    /**
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function normalizeDateRange(?Carbon $start, ?Carbon $end): array
    {
        if (!$start || !$end) {
            return [$start, $end];
        }

        return [$start->copy()->startOfDay(), $end->copy()->endOfDay()];
    }
}
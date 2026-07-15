<?php

namespace App\Services\Financeiro;

use App\Models\AccountCredit;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class CurrentAccountService
{
    public function __construct(
        private readonly MemberManualAccountBalanceResolver $memberManualAccountBalanceResolver,
    ) {
    }

    public function openDebtInvoicesQuery(array $filters = []): Builder
    {
        $today = $this->resolveReferenceDate($filters);

        $query = $this->applyInvoiceFinancialSnapshotColumns(
            Invoice::query()
                ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
                ->where('oculta', false)
                ->where(function (Builder $invoiceQuery) use ($today): void {
                    $invoiceQuery
                        ->whereNull('data_fatura')
                        ->orWhereDate('data_fatura', '<=', $today->toDateString());
                })
        );

        return $this->applyUserAndFamilyFilters($query, $filters, 'user');
    }

    public function normalizeInvoiceFinancialAmounts(Invoice $invoice): Invoice
    {
        $trackedPaidAmount = in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
            ? (float) ($invoice->valor_pago ?? 0)
            : 0.0;
        $confirmedAllocationPaid = (float) ($invoice->confirmed_payment_allocations_sum ?? 0);
        $legacyEntryPaid = (float) ($invoice->legacy_financial_entries_sum ?? 0);
        $paidAmount = round(max($trackedPaidAmount, $confirmedAllocationPaid, $legacyEntryPaid), 2);
        $fallbackOutstanding = max((float) $invoice->valor_total - $paidAmount, 0);
        $persistedOutstanding = $invoice->valor_em_aberto !== null
            ? max((float) $invoice->valor_em_aberto, 0)
            : null;

        if ($paidAmount > 0 && ($persistedOutstanding === null || abs($persistedOutstanding - $fallbackOutstanding) > 0.009)) {
            $invoice->valor_em_aberto = $fallbackOutstanding;
        } elseif ($invoice->estado_pagamento !== 'pago' && $fallbackOutstanding > 0 && ($persistedOutstanding === null || $persistedOutstanding <= 0)) {
            $invoice->valor_em_aberto = $fallbackOutstanding;
        } elseif ($persistedOutstanding !== null) {
            $invoice->valor_em_aberto = $persistedOutstanding;
        } else {
            $invoice->valor_em_aberto = $fallbackOutstanding;
        }

        $invoice->valor_pago = $paidAmount;

        if ($invoice->estado_pagamento !== 'cancelado') {
            if ($paidAmount > 0 && (float) $invoice->valor_em_aberto <= 0.009) {
                $invoice->estado_pagamento = 'pago';
            } elseif ($paidAmount > 0) {
                $invoice->estado_pagamento = 'parcial';
            }
        }

        $dueDate = $invoice->data_vencimento !== null
            ? Carbon::parse($invoice->data_vencimento)->startOfDay()
            : null;

        if (
            $dueDate !== null
            && in_array($invoice->estado_pagamento, ['pendente', 'vencido'], true)
            && (float) $invoice->valor_em_aberto > 0.009
            && $dueDate->lt(now()->startOfDay())
        ) {
            $invoice->estado_pagamento = 'vencido';
        }

        return $invoice;
    }

    public function summarize(array $filters = []): array
    {
        $invoices = $this->openDebtInvoicesQuery($filters)
            ->orderByRaw('CASE WHEN data_vencimento IS NULL THEN 1 ELSE 0 END')
            ->orderBy('data_vencimento')
            ->orderByDesc('data_fatura')
            ->get()
            ->map(fn (Invoice $invoice) => $this->normalizeInvoiceFinancialAmounts($invoice))
            ->filter(fn (Invoice $invoice): bool => (float) ($invoice->valor_em_aberto ?? 0) > 0.009)
            ->values();

        $movements = $this->openDebtMovementsQuery($filters)
            ->orderByRaw('CASE WHEN data_vencimento IS NULL THEN 1 ELSE 0 END')
            ->orderBy('data_vencimento')
            ->orderBy('data_emissao')
            ->get()
            ->map(function (Movement $movement): array {
                $openAmount = $this->normalizeOpenMovementAmount($movement);

                return [
                    'id' => $movement->id,
                    'description' => $movement->observacoes ?: $movement->nome_manual ?: ('Movimento ' . $movement->tipo),
                    'open_amount' => $openAmount,
                    'estado_pagamento' => $movement->estado_pagamento,
                    'data_emissao' => optional($movement->data_emissao)?->toDateString(),
                    'data_vencimento' => optional($movement->data_vencimento)?->toDateString(),
                ];
            })
            ->filter(fn (array $movement): bool => (float) ($movement['open_amount'] ?? 0) > 0.009)
            ->values();

        $grossDebt = round(
            (float) $invoices->sum(fn (Invoice $invoice) => (float) ($invoice->valor_em_aberto ?? 0))
            + (float) $movements->sum('open_amount'),
            2
        );

        $availableCredit = round((float) $this->availableCreditsQuery($filters)->get()->sum(function (AccountCredit $credit): float {
            return (float) ($credit->remaining_amount ?? $credit->amount ?? 0);
        }), 2);
        // Manual account balance is an explicit adjustment component; it is not debt by itself.
        $manualAccountBalance = round($this->resolveManualAccountBalance($filters), 2);
        $netDebt = round(($grossDebt - $availableCredit) + $manualAccountBalance, 2);

        return [
            'gross_debt' => $grossDebt,
            'available_credit' => $availableCredit,
            'manual_account_balance' => $manualAccountBalance,
            'net_debt' => $netDebt,
            'overdue_debt' => round(
                (float) $invoices->where('estado_pagamento', 'vencido')->sum('valor_em_aberto')
                + (float) $movements->where('estado_pagamento', 'vencido')->sum('open_amount'),
                2
            ),
            'pending_debt' => round(
                (float) $invoices->where('estado_pagamento', 'pendente')->sum('valor_em_aberto')
                + (float) $movements->where('estado_pagamento', 'pendente')->sum('open_amount'),
                2
            ),
            'partial_debt' => round(
                (float) $invoices->where('estado_pagamento', 'parcial')->sum('valor_em_aberto')
                + (float) $movements->where('estado_pagamento', 'parcial')->sum('open_amount'),
                2
            ),
            'future_hidden_excluded_count' => $this->excludedInvoiceCount($filters),
            'open_invoice_count' => $invoices->count(),
            'open_movement_count' => $movements->count(),
            'breakdown' => [
                'invoices' => $invoices->map(fn (Invoice $invoice) => [
                    'id' => $invoice->id,
                    'mes' => $invoice->mes,
                    'tipo' => $invoice->tipo,
                    'estado_pagamento' => $invoice->estado_pagamento,
                    'valor_total' => round((float) $invoice->valor_total, 2),
                    'valor_pago' => round((float) ($invoice->valor_pago ?? 0), 2),
                    'valor_em_aberto' => round((float) ($invoice->valor_em_aberto ?? 0), 2),
                    'data_fatura' => optional($invoice->data_fatura)?->toDateString(),
                    'data_vencimento' => optional($invoice->data_vencimento)?->toDateString(),
                ])->all(),
                'movements' => $movements->all(),
            ],
        ];
    }

    public function normalizeOpenMovementAmount(Movement $movement): float
    {
        $entry = $movement->relationLoaded('latestFinancialEntry')
            ? $movement->latestFinancialEntry
            : $movement->latestFinancialEntry()->first();
        $entryOpenAmount = $entry !== null ? max((float) ($entry->valor_em_aberto ?? 0), 0) : null;
        $movementOpenAmount = round(max(abs((float) $movement->valor_total) - (float) ($entry->valor_pago ?? 0), 0), 2);

        return $entryOpenAmount !== null
            ? round($entryOpenAmount, 2)
            : $movementOpenAmount;
    }

    private function openDebtMovementsQuery(array $filters = []): Builder
    {
        $today = $this->resolveReferenceDate($filters);

        $query = Movement::query()
            ->with([
                'latestFinancialEntry:financial_entries.id,financial_entries.origem_id,financial_entries.origem_tipo,financial_entries.valor_em_aberto,financial_entries.valor_pago,financial_entries.estado,financial_entries.created_at',
            ])
            ->where('classificacao', 'receita')
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
            ->where(function (Builder $movementQuery) use ($today): void {
                $movementQuery
                    ->whereNull('data_emissao')
                    ->orWhereDate('data_emissao', '<=', $today->toDateString());
            });

        return $this->applyUserAndFamilyFilters($query, $filters, 'user');
    }

    private function availableCreditsQuery(array $filters = []): Builder
    {
        $query = AccountCredit::query()->available();
        $userIds = $this->resolveScopedUserIds($filters);

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['family_id'])) {
            $familyId = $filters['family_id'];

            $query->where(function (Builder $creditQuery) use ($familyId, $userIds): void {
                $creditQuery->where('family_id', $familyId);

                if ($userIds->isNotEmpty()) {
                    $creditQuery->orWhereIn('user_id', $userIds->all());
                }
            });
        }

        return $query;
    }

    private function resolveManualAccountBalance(array $filters = []): float
    {
        $userIds = $this->resolveScopedUserIds($filters);

        if ($userIds->isEmpty()) {
            return 0.0;
        }

        $users = User::query()
            ->with('dadosFinanceiros:id,user_id,conta_corrente_manual')
            ->whereIn('id', $userIds->all())
            ->get(['id']);

        return round((float) $users->sum(fn (User $user): float => $this->memberManualAccountBalanceResolver->resolveForUser($user)), 2);
    }

    private function excludedInvoiceCount(array $filters = []): int
    {
        $today = $this->resolveReferenceDate($filters);

        $query = Invoice::query()
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial'])
            ->where(function (Builder $invoiceQuery) use ($today): void {
                $invoiceQuery
                    ->where('oculta', true)
                    ->orWhere(function (Builder $futureQuery) use ($today): void {
                        $futureQuery
                            ->whereNotNull('data_fatura')
                            ->whereDate('data_fatura', '>', $today->toDateString());
                    });
            });

        return $this->applyUserAndFamilyFilters($query, $filters, 'user')->count();
    }

    private function resolveScopedUserIds(array $filters = []): Collection
    {
        if (!empty($filters['user_id'])) {
            return collect([(string) $filters['user_id']]);
        }

        if (!empty($filters['family_id'])) {
            return User::query()
                ->whereHas('families', function (Builder $familyQuery) use ($filters): void {
                    $familyQuery->where('familias.id', $filters['family_id']);
                })
                ->pluck('id');
        }

        return collect();
    }

    private function applyUserAndFamilyFilters(Builder $query, array $filters, string $userRelation): Builder
    {
        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['family_id'])) {
            $familyId = $filters['family_id'];

            $query->whereHas($userRelation . '.families', function (Builder $familyQuery) use ($familyId): void {
                $familyQuery->where('familias.id', $familyId);
            });
        }

        return $query;
    }

    private function applyInvoiceFinancialSnapshotColumns(Builder $query): Builder
    {
        return $query
            ->withSum([
                'paymentAllocations as confirmed_payment_allocations_sum' => function ($paymentAllocationQuery): void {
                    $paymentAllocationQuery->where('status', PaymentAllocation::STATUS_CONFIRMED);
                },
            ], 'amount')
            ->selectSub(
                $this->invoicePaymentEntriesQuery()
                    ->selectRaw('COALESCE(SUM(valor), 0)')
                    ->whereColumn('fatura_id', 'invoices.id'),
                'legacy_financial_entries_sum'
            );
    }

    private function invoicePaymentEntriesQuery(): Builder
    {
        return FinancialEntry::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('origem_tipo', 'payment_allocation')
                    ->orWhere('origem_tipo', 'account_credit_usage')
                    ->orWhere('origem_tipo', 'manual')
                    ->orWhere(function (Builder $legacyQuery): void {
                        $legacyQuery
                            ->whereNull('origem_tipo')
                            ->where('tipo', 'receita')
                            ->where('categoria', 'Pagamento de Fatura');
                    });
            });
    }

    private function resolveReferenceDate(array $filters = []): Carbon
    {
        if (!empty($filters['reference_date'])) {
            return Carbon::parse($filters['reference_date'])->startOfDay();
        }

        return now()->startOfDay();
    }
}

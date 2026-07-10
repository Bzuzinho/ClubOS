<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankTransactionAllocation;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\MapaConciliacao;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MemberMonthlyFeeLifecycleService
{
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
                ->filter(fn (Invoice $invoice): bool => $this->canCancelFutureMonthlyInvoice($invoice))
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

    private function canCancelFutureMonthlyInvoice(Invoice $invoice): bool
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

    private function forgetUserFinanceCaches(string $userId): void
    {
        Cache::forget("athlete_dashboard:{$userId}:current_account");
        Cache::forget("athlete_dashboard:{$userId}:pending_invoice");
        Cache::forget("athlete_dashboard:{$userId}:invoices");
    }
}

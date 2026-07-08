<?php

namespace App\Services\Desportivo;

use App\Models\CompetitionRegistration;
use App\Models\FiscalDocumentRequest;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteCompetitionRegistrationAction
{
    public function execute(CompetitionRegistration $competitionRegistration): void
    {
        DB::transaction(function () use ($competitionRegistration): void {
            $registration = CompetitionRegistration::query()
                ->whereKey($competitionRegistration->id)
                ->lockForUpdate()
                ->with(['fatura.items'])
                ->firstOrFail();

            $invoice = $registration->fatura;
            if (!$invoice) {
                $registration->delete();

                return;
            }

            if ($this->hasConfirmedAllocations($invoice->id)) {
                throw ValidationException::withMessages([
                    'competition_registration' => 'Nao e possivel remover a inscricao: a fatura ja tem alocacoes de pagamento confirmadas.',
                ]);
            }

            if ($this->hasFiscalBlockers($invoice->id)) {
                throw ValidationException::withMessages([
                    'competition_registration' => 'Nao e possivel remover a inscricao: existe documento fiscal emitido ou numero externo associado.',
                ]);
            }

            if ($this->hasReceiptBlockers($invoice)) {
                throw ValidationException::withMessages([
                    'competition_registration' => 'Nao e possivel remover a inscricao: a fatura ja tem recibo emitido.',
                ]);
            }

            if ($this->isPaidOrPartial($invoice)) {
                throw ValidationException::withMessages([
                    'competition_registration' => 'Nao e possivel remover a inscricao: a fatura encontra-se parcial ou paga.',
                ]);
            }

            if ($this->hasPaidAmount($invoice)) {
                throw ValidationException::withMessages([
                    'competition_registration' => 'Nao e possivel remover a inscricao: a fatura ja regista valor pago.',
                ]);
            }

            if (!$this->isDeletablePendingOrCancelled($invoice->estado_pagamento)) {
                throw ValidationException::withMessages([
                    'competition_registration' => 'Nao e possivel remover a inscricao: o estado financeiro da fatura nao permite anulacao.',
                ]);
            }

            $invoice->items()->delete();
            $invoice->delete();
            $registration->delete();
        });
    }

    private function isPaidOrPartial(object $invoice): bool
    {
        return in_array((string) $invoice->estado_pagamento, ['pago', 'parcial', 'pago_parcial'], true);
    }

    private function hasPaidAmount(object $invoice): bool
    {
        return (float) ($invoice->valor_pago ?? 0) > 0.009;
    }

    private function hasConfirmedAllocations(string $invoiceId): bool
    {
        return PaymentAllocation::query()
            ->where('invoice_id', $invoiceId)
            ->where('status', PaymentAllocation::STATUS_CONFIRMED)
            ->exists();
    }

    private function hasFiscalBlockers(string $invoiceId): bool
    {
        return FiscalDocumentRequest::query()
            ->where('invoice_id', $invoiceId)
            ->where(function ($query): void {
                $query
                    ->where('status', FiscalDocumentRequest::STATUS_ISSUED)
                    ->orWhere(function ($external): void {
                        $external
                            ->whereNotNull('external_document_number')
                            ->where('external_document_number', '!=', '');
                    });
            })
            ->exists();
    }

    private function hasReceiptBlockers(object $invoice): bool
    {
        if (filled($invoice->numero_recibo)) {
            return true;
        }

        return !empty($invoice->recibo_emitido_em);
    }

    private function isDeletablePendingOrCancelled(?string $status): bool
    {
        return in_array((string) $status, ['pendente', 'por_pagar', 'vencido', 'cancelado'], true);
    }
}

<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\Communication\CommunicationAutomationService;
use App\Services\Financeiro\FiscalDocumentRequestService;
use Illuminate\Support\Facades\Cache;

class InvoiceObserver
{
    public function created(Invoice $invoice): void
    {
        $this->forgetDashboardFinanceCaches($invoice);

        if ($invoice->estado_pagamento === 'pago') {
            app(FiscalDocumentRequestService::class)->syncInvoicePaymentStatus($invoice);
        }

        app(CommunicationAutomationService::class)->triggerInvoiceIssued($invoice);
    }

    public function updating(Invoice $invoice): void
    {
        if (! $invoice->isDirty('estado_pagamento')) {
            return;
        }

        app(FiscalDocumentRequestService::class)->ensureInvoiceStatusCanChangeFromPaid(
            $invoice,
            $invoice->estado_pagamento,
        );
    }

    public function updated(Invoice $invoice): void
    {
        $this->forgetDashboardFinanceCaches($invoice);

        if (! $invoice->wasChanged('estado_pagamento')) {
            return;
        }

        app(FiscalDocumentRequestService::class)->syncInvoicePaymentStatus(
            $invoice,
            $invoice->getOriginal('estado_pagamento'),
        );
    }

    public function deleted(Invoice $invoice): void
    {
        $this->forgetDashboardFinanceCaches($invoice);
    }

    private function forgetDashboardFinanceCaches(Invoice $invoice): void
    {
        if (! $invoice->user_id) {
            return;
        }

        Cache::forget("athlete_dashboard:{$invoice->user_id}:current_account");
        Cache::forget("athlete_dashboard:{$invoice->user_id}:pending_invoice");
        Cache::forget("athlete_dashboard:{$invoice->user_id}:invoices");
    }
}
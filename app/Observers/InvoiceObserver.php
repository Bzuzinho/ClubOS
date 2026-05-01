<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Services\Communication\CommunicationAutomationService;
use Illuminate\Support\Facades\Cache;

class InvoiceObserver
{
    public function created(Invoice $invoice): void
    {
        $this->forgetDashboardFinanceCaches($invoice);
        app(CommunicationAutomationService::class)->triggerInvoiceIssued($invoice);
    }

    public function updated(Invoice $invoice): void
    {
        $this->forgetDashboardFinanceCaches($invoice);
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
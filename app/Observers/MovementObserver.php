<?php

namespace App\Observers;

use App\Models\Movement;
use App\Services\Communication\CommunicationAutomationService;
use Illuminate\Support\Facades\Cache;

class MovementObserver
{
    public function created(Movement $movement): void
    {
        $this->forgetDashboardFinanceCaches($movement);
        app(CommunicationAutomationService::class)->triggerMovementIssued($movement);
    }

    public function updated(Movement $movement): void
    {
        $this->forgetDashboardFinanceCaches($movement);
    }

    public function deleted(Movement $movement): void
    {
        $this->forgetDashboardFinanceCaches($movement);
    }

    private function forgetDashboardFinanceCaches(Movement $movement): void
    {
        if (! $movement->user_id) {
            return;
        }

        Cache::forget("athlete_dashboard:{$movement->user_id}:current_account");
    }
}
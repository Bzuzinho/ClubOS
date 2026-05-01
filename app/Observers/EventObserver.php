<?php

namespace App\Observers;

use App\Models\Event;
use App\Models\EventAttendance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EventObserver
{
    /**
     * Handle the Event "created" event.
     */
    public function created(Event $event): void
    {
        $this->forgetEventosCaches($event);
    }

    /**
     * Handle the Event "updated" event.
     */
    public function updated(Event $event): void
    {
        $this->forgetEventosCaches($event);
    }

    /**
     * Handle the Event "deleted" event.
     * Cleanup related attendance records.
     */
    public function deleted(Event $event): void
    {
        $this->forgetEventosCaches($event);

        try {
            // Delete all attendance records for this event
            $deletedCount = EventAttendance::where('evento_id', $event->id)->delete();
            
            Log::info("Deleted {$deletedCount} attendance records for event: {$event->id}");
        } catch (\Exception $e) {
            Log::error("Error deleting attendance records for event {$event->id}: {$e->getMessage()}");
        }
    }

    private function forgetEventosCaches(Event $event): void
    {
        Cache::forget('eventos:index');
        Cache::forget('eventos:list');

        $statsMonths = collect([
            now()->format('Y-m'),
            optional($event->data_inicio)->format('Y-m'),
            optional($event->data_fim)->format('Y-m'),
        ])->filter()->unique();

        foreach ($statsMonths as $month) {
            Cache::forget('eventos:stats:' . $month);
        }
    }
}

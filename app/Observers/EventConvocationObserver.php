<?php

namespace App\Observers;

use App\Models\ConvocationGroup;
use App\Models\EventConvocation;
use App\Services\Communication\CommunicationAutomationService;

class EventConvocationObserver
{
    public function created(EventConvocation $convocation): void
    {
        $managedByCanonicalGroup = ConvocationGroup::query()
            ->where('evento_id', $convocation->evento_id)
            ->get(['atletas_ids'])
            ->contains(fn (ConvocationGroup $group): bool => in_array(
                (string) $convocation->user_id,
                array_map('strval', $group->atletas_ids ?? []),
                true,
            ));

        if ($managedByCanonicalGroup) {
            return;
        }

        // Transitional compatibility only. Canonical grouped convocations are
        // published explicitly through the F6 Sports -> Communication contract.
        app(CommunicationAutomationService::class)->triggerEventConvocationCreated($convocation);
    }
}

<?php

namespace App\Observers;

use App\Models\ConvocationGroup;

final class ConvocationGroupPublicationObserver
{
    private const PUBLICATION_FIELDS = [
        'evento_id',
        'atletas_ids',
        'hora_encontro',
        'local_encontro',
        'observacoes',
    ];

    public function updating(ConvocationGroup $group): void
    {
        if ((string) $group->getOriginal('publication_status') !== 'published') {
            return;
        }

        if (! $group->isDirty(self::PUBLICATION_FIELDS)) {
            return;
        }

        $group->publication_status = 'draft';
        $group->publication_version = max(1, (int) $group->getOriginal('publication_version') + 1);
    }
}

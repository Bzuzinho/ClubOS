<?php

namespace App\Observers;

use App\Models\MovementDocument;
use App\Services\Financeiro\MovementDocumentControlService;

class MovementDocumentObserver
{
    public function created(MovementDocument $document): void
    {
        $this->refreshMovement($document);
    }

    public function updated(MovementDocument $document): void
    {
        $this->refreshMovement($document);
    }

    public function deleted(MovementDocument $document): void
    {
        $this->refreshMovement($document);
    }

    private function refreshMovement(MovementDocument $document): void
    {
        $movement = $document->movement()->first();

        if (!$movement) {
            return;
        }

        app(MovementDocumentControlService::class)->refresh($movement);
    }
}
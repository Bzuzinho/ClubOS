<?php

namespace App\Contracts\Communication;

interface SportsCommunicationGateway
{
    public function publish(SportsCommunicationIntentRequest $request): SportsCommunicationIntentResult;
}

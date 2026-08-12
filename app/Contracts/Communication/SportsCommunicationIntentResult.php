<?php

namespace App\Contracts\Communication;

final readonly class SportsCommunicationIntentResult
{
    public function __construct(
        public string $intentId,
        public string $status,
        public ?string $campaignId = null,
        public ?string $failureReason = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'intent_id' => $this->intentId,
            'status' => $this->status,
            'campaign_id' => $this->campaignId,
            'failure_reason' => $this->failureReason,
        ];
    }
}

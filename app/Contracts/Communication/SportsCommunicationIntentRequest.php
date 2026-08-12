<?php

namespace App\Contracts\Communication;

final readonly class SportsCommunicationIntentRequest
{
    /**
     * @param list<string> $recipientUserIds
     * @param array<string,mixed> $context
     */
    public function __construct(
        public string $clubId,
        public string $sourceType,
        public string $sourceId,
        public int $sourceVersion,
        public string $intentType,
        public array $recipientUserIds,
        public array $context,
        public ?string $requestedBy = null,
    ) {
    }

    public function idempotencyKey(): string
    {
        return hash('sha256', implode('|', [
            $this->clubId,
            $this->sourceType,
            $this->sourceId,
            (string) $this->sourceVersion,
            $this->intentType,
        ]));
    }
}

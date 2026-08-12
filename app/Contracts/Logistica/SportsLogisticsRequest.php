<?php

namespace App\Contracts\Logistica;

final readonly class SportsLogisticsRequest
{
    /** @param list<array{article_id:string,quantity:int,unit_price?:float|int|null}> $items */
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public int $sourceVersion,
        public ?string $requesterUserId,
        public string $requesterNameSnapshot,
        public ?string $requesterType,
        public array $items,
        public ?string $notes = null,
        public ?string $actorId = null,
        public string $requesterArea = 'Desportivo',
    ) {
    }

    public function idempotencyKey(): string
    {
        return hash('sha256', implode('|', [
            'sports_logistics',
            $this->sourceType,
            $this->sourceId,
            (string) $this->sourceVersion,
        ]));
    }
}

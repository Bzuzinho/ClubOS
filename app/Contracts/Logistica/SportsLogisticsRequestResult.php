<?php

namespace App\Contracts\Logistica;

final readonly class SportsLogisticsRequestResult
{
    public function __construct(
        public string $requestId,
        public string $status,
        public float $totalAmount,
        public bool $reused,
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'status' => $this->status,
            'total_amount' => $this->totalAmount,
            'reused' => $this->reused,
        ];
    }
}

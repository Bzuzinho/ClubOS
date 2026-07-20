<?php

declare(strict_types=1);

namespace App\Services\Financeiro\FiscalProviders;

final readonly class FiscalProviderResult
{
    /**
     * @param array<string,mixed> $rawResponse
     */
    public function __construct(
        public bool $success,
        public ?string $externalDocumentNumber = null,
        public ?string $externalDocumentId = null,
        public ?string $externalDocumentUrl = null,
        public ?string $externalSeries = null,
        public ?string $issuedAt = null,
        public ?string $error = null,
        public array $rawResponse = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Financeiro\FiscalProvider;

final class FiscalDocumentProviderResult
{
    /**
     * @param array<string,mixed> $rawResponse
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $externalDocumentNumber = null,
        public readonly ?string $externalDocumentId = null,
        public readonly ?string $externalDocumentUrl = null,
        public readonly ?string $externalSeries = null,
        public readonly ?string $issuedAt = null,
        public readonly ?string $error = null,
        public readonly array $rawResponse = [],
    ) {
    }

    /**
     * @param array<string,mixed> $rawResponse
     */
    public static function success(
        string $externalDocumentNumber,
        ?string $externalDocumentId = null,
        ?string $externalDocumentUrl = null,
        ?string $externalSeries = null,
        ?string $issuedAt = null,
        array $rawResponse = [],
    ): self {
        return new self(
            success: true,
            externalDocumentNumber: $externalDocumentNumber,
            externalDocumentId: $externalDocumentId,
            externalDocumentUrl: $externalDocumentUrl,
            externalSeries: $externalSeries,
            issuedAt: $issuedAt,
            rawResponse: $rawResponse,
        );
    }

    /**
     * @param array<string,mixed> $rawResponse
     */
    public static function failure(string $error, array $rawResponse = []): self
    {
        return new self(
            success: false,
            error: $error,
            rawResponse: $rawResponse,
        );
    }
}

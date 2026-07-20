<?php

declare(strict_types=1);

namespace App\Services\Financeiro\FiscalProviders;

interface FiscalDocumentProviderInterface
{
    /**
     * @param array<string,mixed> $payload
     */
    public function issueReceipt(array $payload): FiscalProviderResult;

    public function getProviderName(): string;
}

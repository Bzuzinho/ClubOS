<?php

declare(strict_types=1);

namespace App\Services\Financeiro\FiscalProvider;

interface FiscalDocumentProviderAdapter
{
    public function provider(): string;

    /**
     * @param array<string,mixed> $payload
     */
    public function issueReceipt(array $payload): FiscalDocumentProviderResult;
}

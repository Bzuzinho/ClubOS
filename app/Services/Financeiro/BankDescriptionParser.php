<?php

namespace App\Services\Financeiro;

use Illuminate\Support\Str;

class BankDescriptionParser
{
    public function extractAliasAfterDe(string $description): ?string
    {
        $normalizedWhitespace = trim(preg_replace('/\s+/u', ' ', $description) ?? '');

        if ($normalizedWhitespace === '') {
            return null;
        }

        if (!preg_match('/\bDE\s+(.+?)(?=\s+(?:REF|REFERENCIA|DOC|DOCUMENTO|ID|IBAN|NIF|MBWAY|SEPA|TRF|TRANSFERENCIA)\b|$)/iu', $normalizedWhitespace, $matches)) {
            return null;
        }

        $alias = trim((string) ($matches[1] ?? ''));

        return $alias !== '' ? $alias : null;
    }

    public function normalizeAlias(?string $alias): string
    {
        if ($alias === null) {
            return '';
        }

        $normalized = Str::lower(Str::ascii($alias));
        $normalized = preg_replace('/[^a-z0-9\s]/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return trim($normalized);
    }
}
<?php

namespace App\Services\Financeiro;

use Illuminate\Support\Str;

class BankAliasNormalizer
{
    public function normalize(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = Str::upper(Str::ascii($value));
        $normalized = preg_replace('/[^A-Z0-9\s]/', ' ', $normalized) ?? '';
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return trim($normalized);
    }
}
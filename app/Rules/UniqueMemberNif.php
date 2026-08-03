<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class UniqueMemberNif implements ValidationRule
{
    public function __construct(private readonly ?string $ignoreUserId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalizedNif = self::normalize($value);
        if ($normalizedNif === null) {
            return;
        }

        if ($this->existsInCanonicalPersonalData($normalizedNif) || $this->existsInLegacyUsers($normalizedNif)) {
            $fail('Já existe um membro registado com este NIF.');
        }
    }

    public static function normalize(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = mb_strtolower((string) preg_replace('/\s+/u', '', trim((string) $value)));

        return $normalized !== '' ? $normalized : null;
    }

    private function existsInCanonicalPersonalData(string $normalizedNif): bool
    {
        if (!Schema::hasTable('dados_pessoais') || !Schema::hasColumn('dados_pessoais', 'nif')) {
            return false;
        }

        return DB::table('dados_pessoais')
            ->whereNotNull('nif')
            ->when(
                $this->ignoreUserId,
                fn ($query) => $query->where('user_id', '!=', $this->ignoreUserId),
            )
            ->whereRaw("LOWER(REPLACE(TRIM(nif), ' ', '')) = ?", [$normalizedNif])
            ->exists();
    }

    private function existsInLegacyUsers(string $normalizedNif): bool
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'nif')) {
            return false;
        }

        return DB::table('users')
            ->whereNotNull('nif')
            ->when(
                $this->ignoreUserId,
                fn ($query) => $query->where('id', '!=', $this->ignoreUserId),
            )
            ->whereRaw("LOWER(REPLACE(TRIM(nif), ' ', '')) = ?", [$normalizedNif])
            ->exists();
    }
}

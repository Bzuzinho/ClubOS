<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\User;
use Illuminate\Support\Collection;

final class MemberIdentityDisplayResolver
{
    public function __construct(
        private readonly MemberDataReadService $memberDataReadService,
    ) {
    }

    public function displayName(User $user): string
    {
        return $this->displayNameOrFallback($user, null);
    }

    public function displayNameOrFallback(User $user, ?string $fallback = null): string
    {
        $personal = $this->memberDataReadService->personalPayload($user);

        $canonical = $this->normalized($personal['nome_completo'] ?? null);
        if ($canonical !== null) {
            return $canonical;
        }

        $legacy = $this->normalized($user->nome_completo);
        if ($legacy !== null) {
            return $legacy;
        }

        $authName = $this->normalized($user->name);
        if ($authName !== null) {
            return $authName;
        }

        $fallbackValue = $this->normalized($fallback);
        if ($fallbackValue !== null) {
            return $fallbackValue;
        }

        return sprintf('Membro #%s', (string) ($user->id ?? '?'));
    }

    /**
     * @param Collection<int|string, User> $users
     * @return array<int|string, string>
     */
    public function mapDisplayNames(Collection $users): array
    {
        $names = [];

        foreach ($users as $key => $user) {
            if (! $user instanceof User) {
                continue;
            }

            $id = $user->getKey();
            $resultKey = $id !== null ? $id : $key;
            $names[$resultKey] = $this->displayName($user);
        }

        return $names;
    }

    private function normalized(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
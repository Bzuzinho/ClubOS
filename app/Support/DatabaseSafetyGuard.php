<?php

namespace App\Support;

final class DatabaseSafetyGuard
{
    public const DEV_RESET_CONFIRMATION = 'RESET_LOCAL_DEV';

    public static function evaluateDestructiveCommand(string $commandName, ?string $confirmToken): array
    {
        $commandName = strtolower(trim($commandName));

        if (! in_array($commandName, self::blockedCommands(), true)) {
            return ['allowed' => true, 'reason' => null];
        }

        if (self::isProduction()) {
            return [
                'allowed' => false,
                'reason' => 'Comando destrutivo bloqueado: APP_ENV=production nunca permite execucao.',
            ];
        }

        if (self::databaseUrlContainsProtectedSignature()) {
            return [
                'allowed' => false,
                'reason' => 'Comando destrutivo bloqueado: DATABASE_URL protegido (ex.: neon.tech).',
            ];
        }

        if ((bool) config('database_safety.protect_destructive_commands', true)) {
            return [
                'allowed' => false,
                'reason' => 'Comando destrutivo bloqueado: DB_PROTECT_DESTRUCTIVE_COMMANDS=true.',
            ];
        }

        $isLocalOrTesting = app()->environment(['local', 'testing']);
        $allowDestructive = (bool) config('database_safety.allow_destructive_commands', false);
        $expectedToken = trim((string) config('database_safety.destructive_confirmation', ''));

        if ($expectedToken === '') {
            $expectedToken = app()->runningUnitTests() ? '' : 'DESTROY_LOCAL_DATABASE';
        }

        if ($isLocalOrTesting && $allowDestructive && is_string($confirmToken) && hash_equals($expectedToken, $confirmToken)) {
            return ['allowed' => true, 'reason' => null];
        }

        return [
            'allowed' => false,
            'reason' => sprintf(
                'Comando destrutivo bloqueado. Requisitos: APP_ENV local/testing, DB_ALLOW_DESTRUCTIVE_COMMANDS=true e --confirm=%s.',
                $expectedToken
            ),
        ];
    }

    public static function isProduction(): bool
    {
        return app()->environment('production');
    }

    public static function databaseUrlContainsProtectedSignature(): bool
    {
        $url = strtolower((string) self::databaseUrl());

        if ($url === '') {
            return false;
        }

        foreach ((array) config('database_safety.protected_database_signatures', []) as $signature) {
            $signature = strtolower(trim((string) $signature));

            if ($signature !== '' && str_contains($url, $signature)) {
                return true;
            }
        }

        return false;
    }

    public static function databaseUrl(): ?string
    {
        $databaseUrl = config('database.connections.' . config('database.default') . '.url');

        if (is_string($databaseUrl) && $databaseUrl !== '') {
            return $databaseUrl;
        }

        $fallback = env('DATABASE_URL');

        return is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    /**
     * @return array<int, string>
     */
    private static function blockedCommands(): array
    {
        return array_map(
            static fn ($command) => strtolower((string) $command),
            (array) config('database_safety.blocked_commands', [])
        );
    }
}

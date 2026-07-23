<?php

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Throwable;

class DatabaseHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function check(): array
    {
        $connectionName = (string) config('database.default');
        $connectionConfig = (array) config("database.connections.{$connectionName}", []);
        $host = $this->hostFromConfig($connectionConfig);
        $port = $connectionConfig['port'] ?? null;
        $driver = (string) ($connectionConfig['driver'] ?? 'unknown');
        $startedAt = microtime(true);

        $checks = [
            'connect' => $this->emptyCheck(),
            'select_1' => $this->emptyCheck(),
            'club_settings' => $this->emptyCheck(),
        ];

        try {
            $connectStartedAt = microtime(true);
            $connection = DB::connection($connectionName);
            $connection->getPdo();
            $checks['connect'] = $this->successfulCheck($connectStartedAt);

            $selectStartedAt = microtime(true);
            $row = $connection->selectOne('select 1 as health_check');
            $checks['select_1'] = $this->successfulCheck($selectStartedAt, [
                'ok' => (int) ($row->health_check ?? 0) === 1,
            ]);

            $settingsStartedAt = microtime(true);
            $connection->table('club_settings')->limit(1)->first();
            $checks['club_settings'] = $this->successfulCheck($settingsStartedAt);
        } catch (Throwable $exception) {
            $failedCheck = $checks['connect']['ok'] ? ($checks['select_1']['ok'] ? 'club_settings' : 'select_1') : 'connect';
            $checks[$failedCheck] = $this->failedCheck($failedCheck === 'connect' ? $startedAt : microtime(true), $exception);
        }

        $summary = [
            'ok' => collect($checks)->every(fn (array $check): bool => (bool) ($check['ok'] ?? false)),
            'connection' => [
                'name' => $connectionName,
                'driver' => $driver,
                'host' => $this->maskedHost($host),
                'port' => $port,
                'database' => $this->maskedDatabase($connectionConfig['database'] ?? null),
                'sslmode' => $connectionConfig['sslmode'] ?? null,
                'connect_timeout' => $connectionConfig['connect_timeout'] ?? data_get($connectionConfig, 'options.'.\PDO::ATTR_TIMEOUT),
                'pooler' => $this->isPooler($host, $port),
                'neon' => $this->isNeon($host),
            ],
            'checks' => $checks,
            'network' => [
                'ipv6_network_unreachable_detected' => $this->containsNeedle($checks, 'Network is unreachable') || $this->containsNeedle($checks, 'IPv6'),
            ],
            'recommendation' => $this->recommendation($checks, $host),
            'duration_ms' => $this->elapsedMs($startedAt),
        ];

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyCheck(): array
    {
        return [
            'ok' => false,
            'duration_ms' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function successfulCheck(float $startedAt, array $extra = []): array
    {
        return array_merge([
            'ok' => true,
            'duration_ms' => $this->elapsedMs($startedAt),
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function failedCheck(float $startedAt, Throwable $exception): array
    {
        return [
            'ok' => false,
            'duration_ms' => $this->elapsedMs($startedAt),
            'error_class' => $exception::class,
            'error_message' => $this->safeExceptionMessage($exception),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function hostFromConfig(array $config): ?string
    {
        $url = $config['url'] ?? null;
        if (is_string($url) && $url !== '') {
            $parsedHost = parse_url($url, PHP_URL_HOST);
            if (is_string($parsedHost) && $parsedHost !== '') {
                return $parsedHost;
            }
        }

        $host = $config['host'] ?? null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    private function maskedHost(?string $host): ?string
    {
        if ($host === null) {
            return null;
        }

        if (str_contains($host, 'neon.tech')) {
            return preg_replace('/^[^.]+/', '***', $host) ?: '***.neon.tech';
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $host;
        }

        $parts = explode('.', $host);
        if (count($parts) <= 2) {
            return $host;
        }

        $parts[0] = '***';

        return implode('.', $parts);
    }

    private function maskedDatabase(mixed $database): ?string
    {
        if (! is_string($database) || $database === '') {
            return null;
        }

        if (str_contains($database, DIRECTORY_SEPARATOR) || str_contains($database, '/')) {
            return basename($database);
        }

        return $database;
    }

    private function isPooler(?string $host, mixed $port): bool
    {
        return (is_string($host) && str_contains($host, 'pooler'))
            || (string) $port === '6543';
    }

    private function isNeon(?string $host): bool
    {
        return is_string($host) && str_contains($host, 'neon.tech');
    }

    private function recommendation(array $checks, ?string $host): string
    {
        if (! collect($checks)->every(fn (array $check): bool => (bool) ($check['ok'] ?? false))) {
            return $this->isNeon($host)
                ? 'check_neon_pooler_connectivity_and_keep_club_settings_fallback_enabled'
                : 'check_database_connectivity_and_schema_availability';
        }

        $connectMs = (float) ($checks['connect']['duration_ms'] ?? 0);
        if ($connectMs > 1000) {
            return 'review_database_provider_latency_or_pooler_configuration';
        }

        return 'database_health_ok';
    }

    private function containsNeedle(array $checks, string $needle): bool
    {
        return collect($checks)
            ->pluck('error_message')
            ->filter()
            ->contains(fn (string $message): bool => str_contains($message, $needle));
    }

    private function safeExceptionMessage(Throwable $exception): string
    {
        return str($exception->getMessage())
            ->replaceMatches('/password=[^\\s;]+/i', 'password=[masked]')
            ->replaceMatches('/user(name)?=[^\\s;]+/i', 'user=[masked]')
            ->replaceMatches('/dbname=[^\\s;]+/i', 'dbname=[masked]')
            ->limit(500)
            ->toString();
    }

    private function elapsedMs(float $startedAt): float
    {
        return round((microtime(true) - $startedAt) * 1000, 2);
    }
}

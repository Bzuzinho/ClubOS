<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use ReflectionClass;
use RuntimeException;

class LegacySportsGuard
{
    public const FORBIDDEN_ACTIVE_TABLES = [
        'training_sessions',
        'presences',
        'event_results',
        'event_attendances',
        'teams',
        'team_members',
        'call_ups',
    ];

    public function forbiddenTables(): array
    {
        return self::FORBIDDEN_ACTIVE_TABLES;
    }

    public function assertTableAllowed(string $table, string $context = ''): void
    {
        if (in_array($table, self::FORBIDDEN_ACTIVE_TABLES, true)) {
            $message = $this->buildMessage([$table], $context);
            $this->failOrWarn($message);
        }
    }

    public function assertNoForbiddenTablesInSql(string $sql, string $context = ''): void
    {
        $sqlLower = strtolower($sql);
        $hits = [];

        foreach (self::FORBIDDEN_ACTIVE_TABLES as $table) {
            if (str_contains($sqlLower, $table)) {
                $hits[] = $table;
            }
        }

        if ($hits !== []) {
            $message = $this->buildMessage($hits, $context);
            $this->failOrWarn($message);
        }
    }

    public function assertServiceSourceIsLegacyFree(string $className): void
    {
        if (!class_exists($className)) {
            return;
        }

        $reflection = new ReflectionClass($className);
        $path = $reflection->getFileName();

        if (!$path || !is_file($path)) {
            return;
        }

        $source = strtolower((string) file_get_contents($path));
        $hits = [];

        foreach (self::FORBIDDEN_ACTIVE_TABLES as $table) {
            if (str_contains($source, $table)) {
                $hits[] = $table;
            }
        }

        if ($hits !== []) {
            $message = $this->buildMessage($hits, "in {$className}");
            $this->failOrWarn($message);
        }
    }

    private function buildMessage(array $tables, string $context): string
    {
        $tableList = implode(', ', array_unique($tables));

        return trim("LegacySportsGuard blocked forbidden sports tables [{$tableList}] {$context}");
    }

    private function failOrWarn(string $message): void
    {
        if (app()->environment(['local', 'testing']) || config('app.debug')) {
            throw new RuntimeException($message);
        }

        Log::warning($message);
    }
}

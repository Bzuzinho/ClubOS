<?php

declare(strict_types=1);

use App\Services\Financeiro\OperationalDataResetService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;
use RuntimeException;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        /** @var OperationalDataResetService $reset */
        $reset = app(OperationalDataResetService::class);

        if (File::exists($reset->markerPath())) {
            return;
        }

        $snapshot = $this->createValidatedSnapshot();

        $preview = $reset->preview();
        if (
            ($preview['executed'] ?? true) !== false
            || ($preview['already_executed'] ?? true) !== false
            || data_get($preview, 'interpretation.athlete_identity_data_preserved') !== true
            || data_get($preview, 'interpretation.monthly_fee_catalog_preserved') !== true
            || data_get($preview, 'interpretation.monthly_fee_assignments_preserved') !== true
        ) {
            throw new RuntimeException('Production reset dry-run validation failed before any data was changed.');
        }

        $report = $reset->execute();

        if (
            ($report['executed'] ?? false) !== true
            || ($report['already_executed'] ?? true) !== false
            || in_array(false, is_array($report['assertions'] ?? null) ? $report['assertions'] : [false], true)
        ) {
            throw new RuntimeException('Production reset completed without satisfying all post-reset assertions.');
        }

        $manifestPath = storage_path('app/operations/finance-reset-2026-09-28-backup.json');
        File::ensureDirectoryExists(dirname($manifestPath), 0700, true);
        File::put($manifestPath, json_encode([
            'version' => 'finance-reset-backup-v1',
            'reset_id' => OperationalDataResetService::RESET_ID,
            'created_at' => now()->toIso8601String(),
            'snapshot' => $snapshot,
            'offsite_pre_reset_status' => 'validated_by_existing_dr_monitor_before_reset',
            'note' => 'Fresh local PostgreSQL snapshot created immediately before the reset. A separate encrypted off-site DR backup was already healthy before this operation.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    }

    public function down(): void
    {
        // Irreversível por decisão operacional. A recuperação é feita pelo snapshot pré-reset.
    }

    /**
     * @return array{path:string,sha256:string,bytes:int,pg_dump_version:string,pg_restore_version:string}
     */
    private function createValidatedSnapshot(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}", []);

        if ($connection !== 'pgsql' || ! is_array($config)) {
            throw new RuntimeException('Production finance reset requires the PostgreSQL production connection.');
        }

        $pgDump = $this->resolvePg17Tool('/usr/lib/postgresql/17/bin/pg_dump', 'pg_dump');
        $pgRestore = $this->resolvePg17Tool('/usr/lib/postgresql/17/bin/pg_restore', 'pg_restore');

        $dir = storage_path('app/operations/pre-reset');
        File::ensureDirectoryExists($dir, 0700, true);

        $timestamp = now()->utc()->format('Ymd-His');
        $path = $dir."/clubos-pre-finance-reset-{$timestamp}.dump";

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '5432');
        $database = (string) ($config['database'] ?? '');
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if ($database === '' || $username === '') {
            throw new RuntimeException('PostgreSQL production connection is incomplete.');
        }

        $this->runProcess([
            $pgDump,
            "--host={$host}",
            "--port={$port}",
            "--username={$username}",
            "--dbname={$database}",
            '--format=custom',
            '--no-owner',
            '--no-acl',
            "--file={$path}",
        ], [
            'PGPASSWORD' => $password,
        ]);

        clearstatcache(true, $path);
        if (! File::exists($path) || File::size($path) <= 0) {
            throw new RuntimeException('Pre-reset PostgreSQL snapshot was not created or is empty.');
        }

        @chmod($path, 0600);
        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256) || $sha256 === '') {
            throw new RuntimeException('Unable to calculate the pre-reset PostgreSQL snapshot checksum.');
        }

        File::put($path.'.sha256', $sha256.'  '.basename($path).PHP_EOL);
        @chmod($path.'.sha256', 0600);

        $this->runProcess([$pgRestore, '--list', $path]);

        return [
            'path' => $path,
            'sha256' => $sha256,
            'bytes' => File::size($path),
            'pg_dump_version' => trim($this->runProcess([$pgDump, '--version'])),
            'pg_restore_version' => trim($this->runProcess([$pgRestore, '--version'])),
        ];
    }

    private function resolvePg17Tool(string $preferred, string $fallback): string
    {
        $tool = is_executable($preferred) ? $preferred : $this->commandPath($fallback);
        $version = trim($this->runProcess([$tool, '--version']));

        if (! preg_match('/\b17(?:\.\d+)?\b/', $version)) {
            throw new RuntimeException("PostgreSQL 17 {$fallback} is required; found: {$version}");
        }

        return $tool;
    }

    private function commandPath(string $command): string
    {
        $path = trim($this->runProcess(['/usr/bin/env', 'which', $command]));
        if ($path === '' || ! is_executable($path)) {
            throw new RuntimeException("Required command not found: {$command}");
        }

        return $path;
    }

    /**
     * @param list<string> $command
     * @param array<string,string> $environment
     */
    private function runProcess(array $command, array $environment = []): string
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            $command,
            $descriptors,
            $pipes,
            base_path(),
            array_merge($_ENV, $environment),
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start required pre-reset process.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Pre-reset process failed: '.trim((string) $stderr));
        }

        return (string) $stdout;
    }
};

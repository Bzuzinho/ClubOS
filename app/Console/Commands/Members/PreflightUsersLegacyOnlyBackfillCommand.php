<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\UsersLegacyOnlyBackfillPreflightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class PreflightUsersLegacyOnlyBackfillCommand extends Command
{
    protected $signature = 'members:preflight-users-legacy-only-backfill
        {--field= : Limita o diagnostico a um campo permitido}
        {--commit : Tentativa de commit, sempre bloqueada nesta sprint}
        {--confirm= : Token de confirmacao opcional para commit}
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Preflight read-only dos campos legacy_only e dos destinos canónicos existentes';

    public function __construct(private readonly UsersLegacyOnlyBackfillPreflightService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $fieldFilter = is_string($this->option('field')) ? trim((string) $this->option('field')) : '';
        $commitRequested = (bool) $this->option('commit');
        $confirmToken = is_string($this->option('confirm')) ? trim((string) $this->option('confirm')) : '';

        try {
            $payload = $this->service->preflight($fieldFilter !== '' ? $fieldFilter : null);
        } catch (\InvalidArgumentException $exception) {
            $payload = [
                'version' => 'M4.14',
                'mode' => 'preflight',
                'writable' => false,
                'commit_allowed' => false,
                'fields' => [],
                'summary' => [
                    'fields_analyzed' => 0,
                    'total_legacy_only_count' => 0,
                    'total_divergent_count' => 0,
                    'fields_with_missing_canonical_target' => 0,
                    'fields_requiring_architecture_decision' => 0,
                    'fields_with_defined_but_write_blocked_target' => 0,
                    'passed' => false,
                    'failure_reason' => $exception->getMessage(),
                ],
            ];

            $this->writeReportFileIfRequested($payload);
            $this->renderOutput($payload, true);

            return self::FAILURE;
        }

        $failureReason = null;
        if ($commitRequested) {
            $failureReason = $confirmToken === ''
                ? 'Commit bloqueado nesta sprint: esta sprint e read-only. Use --commit --confirm=BACKFILL_LEGACY_ONLY_FIELDS apenas quando o destino canónico estiver aprovado.'
                : 'Commit bloqueado nesta sprint: o preflight nao permite escrita enquanto existir qualquer destino canónico nao aprovado.';
        }

        $payload['summary']['passed'] = $failureReason === null;
        $payload['summary']['failure_reason'] = $failureReason;

        $this->writeReportFileIfRequested($payload);
        $this->renderOutput($payload, $failureReason !== null);

        return $failureReason === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderOutput(array $payload, bool $isError): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return;
        }

        $this->renderHumanReport($payload, $isError);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload, bool $isError): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];

        $this->info('Preflight users legacy-only backfill (read-only)');
        $this->line('Modo: preflight');
        $this->line('Writable: false');
        $this->line('Commit allowed: false');

        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['version', (string) ($payload['version'] ?? 'M4.14')],
                ['fields_analyzed', (int) ($summary['fields_analyzed'] ?? 0)],
                ['total_legacy_only_count', (int) ($summary['total_legacy_only_count'] ?? 0)],
                ['total_divergent_count', (int) ($summary['total_divergent_count'] ?? 0)],
                ['fields_with_missing_canonical_target', (int) ($summary['fields_with_missing_canonical_target'] ?? 0)],
                ['fields_requiring_architecture_decision', (int) ($summary['fields_requiring_architecture_decision'] ?? 0)],
                ['fields_with_defined_but_write_blocked_target', (int) ($summary['fields_with_defined_but_write_blocked_target'] ?? 0)],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ]
        );

        $this->newLine();
        $this->table(
            [
                'field',
                'legacy_field',
                'proposed_canonical_area',
                'proposed_canonical_field',
                'canonical_target_status',
                'legacy_only_count',
                'divergent_count',
            ],
            array_map(static fn (array $field): array => [
                (string) ($field['field'] ?? ''),
                (string) ($field['legacy_field'] ?? ''),
                (string) ($field['proposed_canonical_area'] ?? ''),
                (string) ($field['proposed_canonical_field'] ?? ''),
                (string) ($field['canonical_target_status'] ?? ''),
                (int) ($field['legacy_only_count'] ?? 0),
                (int) ($field['divergent_count'] ?? 0),
            ], $fields)
        );

        $failureReason = is_string($summary['failure_reason'] ?? null) ? trim((string) $summary['failure_reason']) : '';
        if ($failureReason !== '') {
            $this->newLine();
            $this->line($failureReason);
            if ($isError) {
                $this->error($failureReason);
            } else {
                $this->warn($failureReason);
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportFileIfRequested(array $payload): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPath === '') {
            return;
        }

        $path = $this->resolveReportPath($reportPath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->toJson($payload));
    }

    private function resolveReportPath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
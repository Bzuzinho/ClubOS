<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\UsersLegacyOnlyBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class BackfillUsersLegacyOnlyFieldsCommand extends Command
{
    protected $signature = 'members:backfill-users-legacy-only-fields
        {--field= : Limita o processamento a um campo permitido}
        {--commit : Ativa modo de escrita}
        {--confirm= : Token obrigatorio para escrita (BACKFILL_LEGACY_ONLY_FIELDS)}
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Backfill controlado e idempotente dos campos legacy_only para destinos canonicos';

    public function __construct(private readonly UsersLegacyOnlyBackfillService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $fieldFilter = is_string($this->option('field')) ? trim((string) $this->option('field')) : '';
        $commitRequested = (bool) $this->option('commit');
        $confirmToken = is_string($this->option('confirm')) ? trim((string) $this->option('confirm')) : '';

        try {
            $analysis = $this->service->analyze($fieldFilter !== '' ? $fieldFilter : null);
        } catch (\InvalidArgumentException $exception) {
            $payload = [
                'version' => UsersLegacyOnlyBackfillService::VERSION,
                'mode' => 'dry-run',
                'dry_run' => true,
                'committed' => false,
                'fields' => [],
                'summary' => [
                    'fields_analyzed' => 0,
                    'commit_allowed' => false,
                    'passed' => false,
                    'failure_reason' => $exception->getMessage(),
                ],
            ];

            $this->writeReportIfRequested($payload);
            $this->renderOutput($payload);

            return self::FAILURE;
        }

        $commitAllowed = (bool) ($analysis['summary']['commit_allowed'] ?? false);
        $failureReason = null;

        if ($commitRequested && $confirmToken !== UsersLegacyOnlyBackfillService::CONFIRM_TOKEN) {
            $failureReason = 'Escrita bloqueada: use --commit --confirm=BACKFILL_LEGACY_ONLY_FIELDS.';
        }

        if ($failureReason === null && $commitRequested && !$commitAllowed) {
            $failureReason = 'Escrita bloqueada: preflight do backfill nao permite commit para os campos/estado atuais.';
        }

        $shouldCommit = $failureReason === null && $commitRequested;
        $payload = $this->service->execute($fieldFilter !== '' ? $fieldFilter : null, $shouldCommit);

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $payload['summary'] = array_merge($summary, [
            'passed' => $failureReason === null,
            'failure_reason' => $failureReason,
        ]);

        $this->writeReportIfRequested($payload);
        $this->renderOutput($payload);

        return $failureReason === null ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderOutput(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return;
        }

        $this->renderHumanOutput($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanOutput(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];

        $mode = (bool) ($payload['dry_run'] ?? true) ? 'dry-run' : 'commit';

        $this->info(sprintf('Backfill users legacy-only fields (%s)', $mode));
        $this->line(sprintf('Version: %s', (string) ($payload['version'] ?? UsersLegacyOnlyBackfillService::VERSION)));

        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['fields_analyzed', (int) ($summary['fields_analyzed'] ?? 0)],
                ['total_legacy_non_empty_count', (int) ($summary['total_legacy_non_empty_count'] ?? 0)],
                ['total_legacy_only_count', (int) ($summary['total_legacy_only_count'] ?? 0)],
                ['total_candidates_count', (int) ($summary['total_candidates_count'] ?? 0)],
                ['total_would_update_count', (int) ($summary['total_would_update_count'] ?? 0)],
                ['total_updated_count', (int) ($summary['total_updated_count'] ?? 0)],
                ['total_already_matching_count', (int) ($summary['total_already_matching_count'] ?? 0)],
                ['total_divergent_count', (int) ($summary['total_divergent_count'] ?? 0)],
                ['total_skipped_missing_target_count', (int) ($summary['total_skipped_missing_target_count'] ?? 0)],
                ['total_skipped_ambiguous_target_count', (int) ($summary['total_skipped_ambiguous_target_count'] ?? 0)],
                ['commit_allowed', ((bool) ($summary['commit_allowed'] ?? false)) ? 'true' : 'false'],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ]
        );

        $rows = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $rows[] = [
                (string) ($field['field'] ?? ''),
                (string) ($field['target_area'] ?? ''),
                (string) ($field['target_field'] ?? ''),
                (int) ($field['legacy_only_count'] ?? 0),
                (int) ($field['would_update_count'] ?? 0),
                (int) ($field['updated_count'] ?? 0),
                (int) ($field['already_matching_count'] ?? 0),
                (int) ($field['divergent_count'] ?? 0),
                (int) ($field['skipped_missing_target_count'] ?? 0),
                (int) ($field['skipped_ambiguous_target_count'] ?? 0),
            ];
        }

        $this->newLine();
        $this->table(
            [
                'field',
                'target_area',
                'target_field',
                'legacy_only',
                'would_update',
                'updated',
                'already_matching',
                'divergent',
                'skipped_missing_target',
                'skipped_ambiguous_target',
            ],
            $rows,
        );

        $failureReason = is_string($summary['failure_reason'] ?? null)
            ? trim((string) $summary['failure_reason'])
            : '';

        if ($failureReason !== '') {
            $this->newLine();
            $this->error($failureReason);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeReportIfRequested(array $payload): void
    {
        $reportPath = is_string($this->option('report-path')) ? trim((string) $this->option('report-path')) : '';
        if ($reportPath === '') {
            return;
        }

        $path = $this->resolvePath($reportPath);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $this->toJson($payload));
    }

    private function resolvePath(string $path): string
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

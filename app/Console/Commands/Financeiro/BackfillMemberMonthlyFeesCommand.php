<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\MemberMonthlyFeeLegacyBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class BackfillMemberMonthlyFeesCommand extends Command
{
    protected $signature = 'finance:backfill-member-monthly-fees
        {--apply : Executa escrita canónica em dados_financeiros.mensalidade_id}
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar relatorio JSON}
        {--fail-on-skipped : Falha com codigo 1 quando existem membros classificados como skipped}
        {--user= : Limita a analise/execucao a um user_id especifico}';

    protected $description = 'Backfill conservador de mensalidades legacy-only para dados_financeiros.mensalidade_id';

    public function __construct(private readonly MemberMonthlyFeeLegacyBackfillService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $userFilter = is_string($this->option('user')) ? trim((string) $this->option('user')) : null;
        $analysis = $this->service->analyze($userFilter !== '' ? $userFilter : null);

        $apply = (bool) $this->option('apply');
        $payload = $analysis;

        if ($apply) {
            if (!$this->service->preflightAllowsApply($analysis)) {
                $payload['mode'] = 'apply';
                $payload['migration'] = [
                    'migrated_count' => 0,
                    'migrated_user_ids' => [],
                    'already_canonical_count' => 0,
                    'already_canonical_user_ids' => [],
                    'skipped_count' => 0,
                    'skipped_user_ids' => [],
                    'failed_count' => 0,
                    'failed' => [],
                ];
                $payload['error'] = 'Apply blocked by preflight. Resolve divergent and invalid legacy references first.';

                $this->writeReportIfRequested($payload);
                $this->renderOutput($payload);

                return self::FAILURE;
            }

            $payload = $this->service->apply($analysis);
        }

        $this->writeReportIfRequested($payload);
        $this->renderOutput($payload);

        if ((bool) $this->option('fail-on-skipped')) {
            $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
            $migration = is_array($payload['migration'] ?? null) ? $payload['migration'] : [];
            $hasSummarySkipped = (int) ($summary['skipped_count'] ?? 0) > 0;
            $hasMigrationSkipped = (int) ($migration['skipped_count'] ?? 0) > 0;

            if ($hasSummarySkipped || $hasMigrationSkipped) {
                return self::FAILURE;
            }
        }

        $migration = is_array($payload['migration'] ?? null) ? $payload['migration'] : [];
        if ((int) ($migration['failed_count'] ?? 0) > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderOutput(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->toJson($payload));

            return;
        }

        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $preflight = is_array($payload['preflight'] ?? null) ? $payload['preflight'] : [];
        $migration = is_array($payload['migration'] ?? null) ? $payload['migration'] : [];

        $this->info('Backfill member monthly fees');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['mode', (string) ($payload['mode'] ?? 'dry-run')],
                ['total', (int) ($summary['total'] ?? 0)],
                ['ready_for_backfill_count', (int) ($summary['ready_for_backfill_count'] ?? 0)],
                ['already_canonical_count', (int) ($summary['already_canonical_count'] ?? 0)],
                ['divergent_count', (int) ($summary['divergent_count'] ?? 0)],
                ['invalid_legacy_reference_count', (int) ($summary['invalid_legacy_reference_count'] ?? 0)],
                ['missing_required_count', (int) ($summary['missing_required_count'] ?? 0)],
                ['not_required_count', (int) ($summary['not_required_count'] ?? 0)],
                ['no_source_count', (int) ($summary['no_source_count'] ?? 0)],
                ['skipped_count', (int) ($summary['skipped_count'] ?? 0)],
                ['preflight_can_apply', ((bool) ($preflight['can_apply'] ?? false)) ? 'true' : 'false'],
                ['migrated_count', (int) ($migration['migrated_count'] ?? 0)],
                ['migration_already_canonical_count', (int) ($migration['already_canonical_count'] ?? 0)],
                ['migration_skipped_count', (int) ($migration['skipped_count'] ?? 0)],
                ['failed_count', (int) ($migration['failed_count'] ?? 0)],
            ]
        );

        $cases = is_array($payload['cases'] ?? null) ? $payload['cases'] : [];
        if ($cases !== []) {
            $rows = [];
            foreach ($cases as $case) {
                if (!is_array($case)) {
                    continue;
                }

                $rows[] = [
                    (string) ($case['user_id'] ?? ''),
                    (string) ($case['classification'] ?? ''),
                    (string) ($case['canonical_monthly_fee_id'] ?? ''),
                    (string) ($case['legacy_monthly_fee_id'] ?? ''),
                    (string) ($case['resolved_monthly_fee_id'] ?? ''),
                    ((bool) ($case['reference_valid'] ?? false)) ? 'true' : 'false',
                    (string) ($case['reason'] ?? ''),
                    $this->toJson(is_array($case['canonical_payload_candidate'] ?? null) ? $case['canonical_payload_candidate'] : []),
                ];
            }

            $this->newLine();
            $this->table(
                ['user_id', 'classification', 'canonical', 'legacy', 'resolved', 'reference_valid', 'reason', 'canonical_payload_candidate'],
                array_slice($rows, 0, 100)
            );
        }

        if (is_string($payload['error'] ?? null) && trim((string) $payload['error']) !== '') {
            $this->newLine();
            $this->error((string) $payload['error']);
        }
    }

    /**
     * @param array<string, mixed> $payload
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
     * @param array<string, mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}

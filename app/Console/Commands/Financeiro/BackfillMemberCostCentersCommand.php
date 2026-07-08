<?php

declare(strict_types=1);

namespace App\Console\Commands\Financeiro;

use App\Services\Financeiro\MemberCostCenterLegacyBackfillService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class BackfillMemberCostCentersCommand extends Command
{
    protected $signature = 'finance:backfill-member-cost-centers
        {--apply : Executa escrita canónica na pivot centro_custo_user}
        {--json : Devolve o relatorio em JSON}
        {--report-path= : Caminho para guardar relatorio JSON}
        {--fail-on-skipped : Falha com codigo 1 quando existem membros classificados como skipped}
        {--user= : Limita a analise/execucao a um user_id especifico}';

    protected $description = 'Backfill conservador de centros de custo legacy-only para a pivot canónica';

    public function __construct(private readonly MemberCostCenterLegacyBackfillService $service)
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
                $payload['dry_run'] = false;
                $payload['apply_requested'] = true;
                $payload['migration'] = [
                    'migrated_count' => 0,
                    'migrated_user_ids' => [],
                    'skipped_count' => 0,
                    'skipped_user_ids' => [],
                    'failed_count' => 0,
                    'failed' => [],
                ];
                $payload['error'] = 'Apply blocked by preflight. Resolve divergent/invalid_legacy/invalid_weights first.';

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
            if ((int) ($summary['skipped_count'] ?? 0) > 0) {
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
     * @param array<string,mixed> $payload
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

        $this->info('Backfill member cost centers');
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['mode', (string) ($payload['mode'] ?? 'dry-run')],
                ['total_users_analyzed', (int) ($summary['total_users_analyzed'] ?? 0)],
                ['ready_for_backfill_count', (int) ($summary['ready_for_backfill_count'] ?? 0)],
                ['already_canonical_count', (int) ($summary['already_canonical_count'] ?? 0)],
                ['divergent_count', (int) ($summary['divergent_count'] ?? 0)],
                ['invalid_legacy_count', (int) ($summary['invalid_legacy_count'] ?? 0)],
                ['invalid_weights_count', (int) ($summary['invalid_weights_count'] ?? 0)],
                ['no_source_count', (int) ($summary['no_source_count'] ?? 0)],
                ['skipped_count', (int) ($summary['skipped_count'] ?? 0)],
                ['preflight_can_apply', ((bool) ($preflight['can_apply'] ?? false)) ? 'true' : 'false'],
                ['migrated_count', (int) ($migration['migrated_count'] ?? 0)],
                ['migration_skipped_count', (int) ($migration['skipped_count'] ?? 0)],
                ['failed_count', (int) ($migration['failed_count'] ?? 0)],
            ]
        );

        $members = is_array($payload['members'] ?? null) ? $payload['members'] : [];
        if ($members !== []) {
            $rows = [];
            foreach ($members as $member) {
                if (!is_array($member)) {
                    continue;
                }

                $rows[] = [
                    (string) ($member['id'] ?? ''),
                    (string) ($member['numero_socio'] ?? ''),
                    (string) ($member['name'] ?? ''),
                    (string) ($member['classification'] ?? ''),
                    implode(',', array_values(array_map(static fn (array $row): string => (string) ($row['id'] ?? ''), is_array($member['legacy_cost_centers_found'] ?? null) ? $member['legacy_cost_centers_found'] : []))),
                    $this->toJson(is_array($member['canonical_payload_candidate'] ?? null) ? $member['canonical_payload_candidate'] : []),
                ];
            }

            $this->newLine();
            $this->table(
                ['id', 'numero_socio', 'name', 'classification', 'legacy_ids', 'candidate_payload'],
                array_slice($rows, 0, 50)
            );
        }

        if (is_string($payload['error'] ?? null) && trim((string) $payload['error']) !== '') {
            $this->newLine();
            $this->error((string) $payload['error']);
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

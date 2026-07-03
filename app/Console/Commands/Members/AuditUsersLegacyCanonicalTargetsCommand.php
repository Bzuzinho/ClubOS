<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\UsersLegacyCanonicalTargetDecisionAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AuditUsersLegacyCanonicalTargetsCommand extends Command
{
    protected $signature = 'members:audit-users-legacy-canonical-targets
        {--json : Devolve o relatorio em JSON}
        {--field= : Limita a auditoria a um campo permitido}
        {--fail-on-missing-decision : Falha com codigo 1 quando existir campo sem decisao explicita}
        {--fail-on-write-allowed : Falha com codigo 1 quando existir campo com write_allowed=true}
        {--report-path= : Caminho para guardar relatorio JSON}';

    protected $description = 'Audita decisoes canonicas M4.15 para campos legacy_only bloqueados no preflight';

    public function __construct(private readonly UsersLegacyCanonicalTargetDecisionAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $fieldFilter = is_string($this->option('field')) ? trim((string) $this->option('field')) : '';

        try {
            $audit = $this->auditor->audit($fieldFilter !== '' ? $fieldFilter : null);
        } catch (\InvalidArgumentException $exception) {
            $payload = [
                'version' => 'M4.15',
                'config_version' => 'M4.15',
                'preflight_version' => 'M4.14',
                'summary' => [
                    'fields_analyzed' => 0,
                    'explicit_decisions_count' => 0,
                    'missing_decisions_count' => 0,
                    'write_allowed_count' => 0,
                    'blocked_write_count' => 0,
                    'architecture_decision_required_count' => 0,
                    'canonical_payload_key_pending_count' => 0,
                    'canonical_payload_key_defined_count' => 0,
                    'unknown_target_status_count' => 0,
                    'passed' => false,
                    'failure_reason' => $exception->getMessage(),
                ],
                'fields' => [],
            ];

            $this->writeReportFileIfRequested($payload);
            $this->renderOutput($payload);

            return self::FAILURE;
        }

        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];
        $failureReasons = [];

        if ((bool) $this->option('fail-on-missing-decision') && ((int) ($summary['missing_decisions_count'] ?? 0)) > 0) {
            $failureReasons[] = 'Canonical target decision audit failed. Existem campos sem decisao explicita no config M4.15.';
        }

        if ((bool) $this->option('fail-on-write-allowed') && ((int) ($summary['write_allowed_count'] ?? 0)) > 0) {
            $failureReasons[] = 'Canonical target decision audit failed. Existem campos com write_allowed=true numa fase read-only.';
        }

        if (((int) ($summary['unknown_target_status_count'] ?? 0)) > 0) {
            $failureReasons[] = 'Canonical target decision audit failed. Existem campos com target_status desconhecido.';
        }

        $passed = $failureReasons === [];
        $payload = [
            'version' => (string) ($audit['version'] ?? 'M4.15'),
            'config_version' => (string) ($audit['config_version'] ?? 'M4.15'),
            'preflight_version' => (string) ($audit['preflight_version'] ?? 'M4.14'),
            'summary' => array_merge($summary, [
                'passed' => $passed,
                'failure_reason' => $passed ? null : implode(' ', $failureReasons),
            ]),
            'fields' => is_array($audit['fields'] ?? null) ? $audit['fields'] : [],
        ];

        $this->writeReportFileIfRequested($payload);
        $this->renderOutput($payload);

        return $passed ? self::SUCCESS : self::FAILURE;
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

        $this->renderHumanReport($payload);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $fields = is_array($payload['fields'] ?? null) ? $payload['fields'] : [];

        $this->info('Audit users legacy canonical targets (read-only)');
        $this->line(sprintf('Version: %s', (string) ($payload['version'] ?? 'M4.15')));
        $this->line(sprintf('Config version: %s', (string) ($payload['config_version'] ?? 'M4.15')));
        $this->line('Writable: false');

        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['fields_analyzed', (int) ($summary['fields_analyzed'] ?? 0)],
                ['explicit_decisions_count', (int) ($summary['explicit_decisions_count'] ?? 0)],
                ['missing_decisions_count', (int) ($summary['missing_decisions_count'] ?? 0)],
                ['write_allowed_count', (int) ($summary['write_allowed_count'] ?? 0)],
                ['blocked_write_count', (int) ($summary['blocked_write_count'] ?? 0)],
                ['architecture_decision_required_count', (int) ($summary['architecture_decision_required_count'] ?? 0)],
                ['canonical_payload_key_pending_count', (int) ($summary['canonical_payload_key_pending_count'] ?? 0)],
                ['canonical_payload_key_defined_count', (int) ($summary['canonical_payload_key_defined_count'] ?? 0)],
                ['unknown_target_status_count', (int) ($summary['unknown_target_status_count'] ?? 0)],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ],
        );

        $this->newLine();
        $this->table(
            [
                'field',
                'target_area',
                'target_field',
                'target_status',
                'decision',
                'write_allowed',
                'owner_area',
            ],
            array_map(static fn (array $field): array => [
                (string) ($field['field'] ?? ''),
                (string) ($field['target_area'] ?? ''),
                (string) ($field['target_field'] ?? ''),
                (string) ($field['target_status'] ?? ''),
                (string) ($field['decision'] ?? ''),
                ((bool) ($field['write_allowed'] ?? false)) ? 'true' : 'false',
                (string) ($field['owner_area'] ?? ''),
            ], $fields),
        );

        $failureReason = is_string($summary['failure_reason'] ?? null) ? trim((string) $summary['failure_reason']) : '';
        if ($failureReason !== '') {
            $this->newLine();
            $this->error($failureReason);
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
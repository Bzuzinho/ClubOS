<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Members\LegacyCleanupReadinessAuditor;
use Illuminate\Console\Command;

final class AuditLegacyCleanupReadinessCommand extends Command
{
    protected $signature = 'members:audit-legacy-cleanup-readiness
        {--json : Devolve o relatorio em JSON}
        {--fail-on-not-ready : Falha com codigo 1 quando existir campo nao ready_for_cleanup}
        {--field= : Limita a auditoria a um campo permitido (estado_civil|numero_irmaos|declaracao_transporte)}';

    protected $description = 'Audita readiness de cleanup fisico futuro para campos legacy de membros em users (read-only)';

    public function __construct(private readonly LegacyCleanupReadinessAuditor $auditor)
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
                'version' => 'M5',
                'summary' => [
                    'fields_analyzed' => 0,
                    'ready_for_cleanup_count' => 0,
                    'blocked_count' => 0,
                    'needs_manual_review_count' => 0,
                    'not_ready_count' => 0,
                    'passed' => false,
                    'failure_reason' => $exception->getMessage(),
                ],
                'fields' => [],
            ];

            $this->renderOutput($payload);

            return self::FAILURE;
        }

        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];
        $notReadyCount = (int) ($summary['not_ready_count'] ?? 0);
        $shouldFail = (bool) $this->option('fail-on-not-ready') && $notReadyCount > 0;

        $payload = [
            'version' => (string) ($audit['version'] ?? 'M5'),
            'summary' => array_merge($summary, [
                'passed' => !$shouldFail,
                'failure_reason' => $shouldFail
                    ? 'Legacy cleanup readiness audit failed. Existem campos ainda nao ready_for_cleanup.'
                    : null,
            ]),
            'fields' => is_array($audit['fields'] ?? null) ? $audit['fields'] : [],
        ];

        $this->renderOutput($payload);

        return $shouldFail ? self::FAILURE : self::SUCCESS;
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

        $this->info('Audit legacy cleanup readiness (M5, read-only)');
        $this->line(sprintf('Version: %s', (string) ($payload['version'] ?? 'M5')));

        $this->newLine();
        $this->table(
            ['Metrica', 'Valor'],
            [
                ['fields_analyzed', (int) ($summary['fields_analyzed'] ?? 0)],
                ['ready_for_cleanup_count', (int) ($summary['ready_for_cleanup_count'] ?? 0)],
                ['blocked_count', (int) ($summary['blocked_count'] ?? 0)],
                ['needs_manual_review_count', (int) ($summary['needs_manual_review_count'] ?? 0)],
                ['not_ready_count', (int) ($summary['not_ready_count'] ?? 0)],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ],
        );

        $this->newLine();
        $this->table(
            [
                'field',
                'canonical_area',
                'canonical_field',
                'readiness_status',
                'cleanup_status',
                'legacy_only',
                'divergent',
                'forbidden_reads',
                'forbidden_writes',
                'ready',
            ],
            array_map(static fn (array $row): array => [
                (string) ($row['field'] ?? ''),
                (string) ($row['canonical_area'] ?? 'n/a'),
                (string) ($row['canonical_field'] ?? 'n/a'),
                (string) ($row['readiness_status'] ?? 'unknown'),
                (string) ($row['cleanup_status'] ?? 'unknown'),
                (int) ($row['legacy_only_count'] ?? 0),
                (int) ($row['divergent_count'] ?? 0),
                (int) ($row['forbidden_legacy_read_count'] ?? 0),
                (int) ($row['forbidden_legacy_write_count'] ?? 0),
                ((bool) ($row['ready_for_cleanup'] ?? false)) ? 'true' : 'false',
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
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}

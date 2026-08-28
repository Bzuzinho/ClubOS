<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Family\FamilyLegacyRelationshipAuditor;
use Illuminate\Console\Command;

final class AuditFamilyLegacyRelationshipsCommand extends Command
{
    protected $signature = 'members:audit-family-legacy-relationships
        {--json : Devolve o relatório em JSON}
        {--fail-on-uncovered : Falha quando existir algum registo legacy sem projeção canónica}';

    protected $description = 'Audita user_relationships contra user_guardian e familias/familia_user sem alterar dados';

    public function __construct(private readonly FamilyLegacyRelationshipAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $audit = $this->auditor->audit();
        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];
        $uncovered = (int) ($summary['uncovered_count'] ?? 0);
        $unknown = (int) ($summary['unknown_type_count'] ?? 0);
        $shouldFail = (bool) $this->option('fail-on-uncovered') && ($uncovered > 0 || $unknown > 0);

        $payload = [
            'version' => (string) ($audit['version'] ?? 'H2.2'),
            'summary' => array_merge($summary, [
                'passed' => ! $shouldFail,
                'failure_reason' => $shouldFail
                    ? 'Existem relações legacy sem projeção canónica ou com tipo desconhecido.'
                    : null,
            ]),
            'by_type' => is_array($audit['by_type'] ?? null) ? $audit['by_type'] : [],
            'unresolved' => is_array($audit['unresolved'] ?? null) ? $audit['unresolved'] : [],
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ) ?: '{}');
        } else {
            $this->renderHumanReport($payload);
        }

        return $shouldFail ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderHumanReport(array $payload): void
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $byType = is_array($payload['by_type'] ?? null) ? $payload['by_type'] : [];
        $unresolved = is_array($payload['unresolved'] ?? null) ? $payload['unresolved'] : [];

        $this->info('Audit Família/EE legacy relationships (H2.2, read-only)');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['table_present', ((bool) ($summary['table_present'] ?? false)) ? 'true' : 'false'],
                ['total_rows', (int) ($summary['total_rows'] ?? 0)],
                ['canonical_covered_count', (int) ($summary['canonical_covered_count'] ?? 0)],
                ['uncovered_count', (int) ($summary['uncovered_count'] ?? 0)],
                ['unknown_type_count', (int) ($summary['unknown_type_count'] ?? 0)],
                ['reciprocal_missing_count', (int) ($summary['reciprocal_missing_count'] ?? 0)],
                ['ready_for_physical_cleanup', ((bool) ($summary['ready_for_physical_cleanup'] ?? false)) ? 'true' : 'false'],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ],
        );

        if ($byType !== []) {
            $this->newLine();
            $this->table(
                ['type', 'total', 'covered', 'uncovered', 'reciprocal_missing'],
                array_map(static fn (array $row): array => [
                    (string) ($row['type'] ?? ''),
                    (int) ($row['total_rows'] ?? 0),
                    (int) ($row['canonical_covered_count'] ?? 0),
                    (int) ($row['uncovered_count'] ?? 0),
                    (int) ($row['reciprocal_missing_count'] ?? 0),
                ], $byType),
            );
        }

        if ($unresolved !== []) {
            $this->newLine();
            $this->warn(sprintf(
                'Amostra de relações por resolver: %d (máximo 100).',
                count($unresolved),
            ));
            $this->table(
                ['relationship_id', 'user_id', 'related_user_id', 'type', 'reason'],
                array_map(static fn (array $row): array => [
                    (string) ($row['relationship_id'] ?? ''),
                    (string) ($row['user_id'] ?? ''),
                    (string) ($row['related_user_id'] ?? ''),
                    (string) ($row['type'] ?? ''),
                    (string) ($row['reason'] ?? ''),
                ], $unresolved),
            );
        }

        $failureReason = is_string($summary['failure_reason'] ?? null)
            ? trim((string) $summary['failure_reason'])
            : '';

        if ($failureReason !== '') {
            $this->newLine();
            $this->error($failureReason);
        }
    }
}

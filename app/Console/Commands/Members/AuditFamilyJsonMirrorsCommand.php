<?php

declare(strict_types=1);

namespace App\Console\Commands\Members;

use App\Services\Family\FamilyJsonMirrorAuditor;
use Illuminate\Console\Command;

final class AuditFamilyJsonMirrorsCommand extends Command
{
    protected $signature = 'members:audit-family-json-mirrors
        {--json : Devolve o relatório em JSON}
        {--fail-on-finding : Falha quando existe uso runtime direto ou link JSON sem projeção canónica}';

    protected $description = 'Audita os mirrors JSON encarregado_educacao/educandos contra runtime e user_guardian sem alterar dados';

    public function __construct(private readonly FamilyJsonMirrorAuditor $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $audit = $this->auditor->audit();
        $summary = is_array($audit['summary'] ?? null) ? $audit['summary'] : [];
        $hasFinding = (int) ($summary['source_findings_count'] ?? 0) > 0
            || (int) ($summary['uncovered_pairs_count'] ?? 0) > 0
            || (int) ($summary['invalid_reference_count'] ?? 0) > 0
            || (int) ($summary['self_reference_count'] ?? 0) > 0;
        $shouldFail = (bool) $this->option('fail-on-finding') && $hasFinding;

        $payload = array_merge($audit, [
            'summary' => array_merge($summary, [
                'passed' => ! $shouldFail,
                'failure_reason' => $shouldFail
                    ? 'Existem usos runtime diretos dos mirrors JSON ou relações históricas ainda sem cobertura canónica.'
                    : null,
            ]),
        ]);

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
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $this->info('Audit Família/EE JSON mirrors (H2.3, read-only)');
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['source_findings_count', (int) ($summary['source_findings_count'] ?? 0)],
                ['declared_json_links_count', (int) ($summary['declared_json_links_count'] ?? 0)],
                ['unique_json_pairs_count', (int) ($summary['unique_json_pairs_count'] ?? 0)],
                ['canonical_covered_pairs_count', (int) ($summary['canonical_covered_pairs_count'] ?? 0)],
                ['uncovered_pairs_count', (int) ($summary['uncovered_pairs_count'] ?? 0)],
                ['invalid_reference_count', (int) ($summary['invalid_reference_count'] ?? 0)],
                ['self_reference_count', (int) ($summary['self_reference_count'] ?? 0)],
                ['ready_for_physical_cleanup', ((bool) ($summary['ready_for_physical_cleanup'] ?? false)) ? 'true' : 'false'],
                ['passed', ((bool) ($summary['passed'] ?? false)) ? 'true' : 'false'],
            ],
        );

        $findings = is_array($source['findings'] ?? null) ? $source['findings'] : [];
        if ($findings !== []) {
            $this->newLine();
            $this->warn('Usos runtime diretos encontrados:');
            $this->table(
                ['file', 'field', 'pattern', 'line', 'snippet'],
                array_map(static fn (array $row): array => [
                    (string) ($row['file'] ?? ''),
                    (string) ($row['field'] ?? ''),
                    (string) ($row['pattern'] ?? ''),
                    (int) ($row['line'] ?? 0),
                    (string) ($row['snippet'] ?? ''),
                ], array_slice($findings, 0, 30)),
            );
        }

        $unresolved = is_array($data['unresolved'] ?? null) ? $data['unresolved'] : [];
        if ($unresolved !== []) {
            $this->newLine();
            $this->warn('Relações JSON por resolver:');
            $this->table(
                ['member_id', 'guardian_id', 'sources', 'reason'],
                array_map(static fn (array $row): array => [
                    (string) ($row['member_id'] ?? ''),
                    (string) ($row['guardian_id'] ?? ''),
                    implode(',', is_array($row['sources'] ?? null) ? $row['sources'] : []),
                    (string) ($row['reason'] ?? ''),
                ], array_slice($unresolved, 0, 30)),
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

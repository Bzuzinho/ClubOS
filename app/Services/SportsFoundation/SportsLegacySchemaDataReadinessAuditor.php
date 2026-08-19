<?php

declare(strict_types=1);

namespace App\Services\SportsFoundation;

use App\Support\LegacySportsGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class SportsLegacySchemaDataReadinessAuditor
{
    /** @var array<string,array{owner:string,classification:string,removal_candidate:bool,reason:string}> */
    private const TABLE_POLICY = [
        'presences' => [
            'owner' => 'desportivo_legacy',
            'classification' => 'migrate_or_preserve_history',
            'removal_candidate' => true,
            'reason' => 'Training attendance is canonical in training_athletes; legacy rows must be reconciled before any drop.',
        ],
        'training_sessions' => [
            'owner' => 'desportivo_legacy',
            'classification' => 'parallel_session_domain',
            'removal_candidate' => true,
            'reason' => 'Scheduled sports sessions are canonical in trainings; any remaining rows require explicit disposition.',
        ],
        'event_results' => [
            'owner' => 'eventos_legacy',
            'classification' => 'cross_module_history',
            'removal_candidate' => false,
            'reason' => 'Sports results are canonical elsewhere, but this table may contain event-owned historical data.',
        ],
        'event_attendances' => [
            'owner' => 'eventos',
            'classification' => 'external_module_owned',
            'removal_candidate' => false,
            'reason' => 'Forbidden only as a Sports source of truth; Eventos may still own and use this table.',
        ],
        'teams' => [
            'owner' => 'equipas',
            'classification' => 'external_module_owned',
            'removal_candidate' => false,
            'reason' => 'Forbidden as a Sports source of truth but still potentially owned by Equipas/Formacao.',
        ],
        'team_members' => [
            'owner' => 'equipas',
            'classification' => 'external_module_owned',
            'removal_candidate' => false,
            'reason' => 'Forbidden as a Sports source of truth but still potentially owned by Equipas/Formacao.',
        ],
        'call_ups' => [
            'owner' => 'desportivo_legacy',
            'classification' => 'legacy_convocation_domain',
            'removal_candidate' => true,
            'reason' => 'Canonical convocations use the current event/sports convocation flows; historical rows must be classified first.',
        ],
    ];

    /** @var list<string> */
    private const AUDIT_TOOLING_FILES = [
        'SportsLegacySchemaDataReadinessAuditor.php',
        'MigrateLegacyPresencesAction.php',
        'SportsFoundationCutoverAuditor.php',
        'SportsLegacyCutoverLedgerService.php',
    ];

    public function __construct(private readonly LegacySportsGuard $guard)
    {
    }

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $tables = [];

        foreach ($this->guard->forbiddenTables() as $table) {
            $policy = self::TABLE_POLICY[$table] ?? [
                'owner' => 'unknown',
                'classification' => 'manual_review',
                'removal_candidate' => false,
                'reason' => 'No explicit disposition policy exists.',
            ];

            $exists = Schema::hasTable($table);
            $rows = $exists ? (int) DB::table($table)->count() : 0;
            $runtimeReferences = $this->runtimeReferences($table);
            $removalReady = $policy['removal_candidate'] && $exists && $rows === 0 && $runtimeReferences === [];
            $removalComplete = $policy['removal_candidate'] && ! $exists && $runtimeReferences === [];
            $retirementState = match (true) {
                ! $policy['removal_candidate'] => 'not_applicable',
                $removalComplete => 'removed',
                $removalReady => 'ready',
                default => 'blocked',
            };

            $tables[$table] = [
                'exists' => $exists,
                'row_count' => $rows,
                'owner' => $policy['owner'],
                'classification' => $policy['classification'],
                'removal_candidate' => $policy['removal_candidate'],
                'runtime_reference_count' => count($runtimeReferences),
                'runtime_references' => $runtimeReferences,
                'reason' => $policy['reason'],
                'removal_ready' => $removalReady,
                'removal_complete' => $removalComplete,
                'retirement_state' => $retirementState,
            ];
        }

        $presenceReconciliation = $this->presenceReconciliation();
        $aliases = $this->legacyAliasColumns();

        $removalCandidates = collect($tables)->filter(fn (array $row): bool => $row['removal_candidate'])->count();
        $removalReady = collect($tables)->filter(fn (array $row): bool => $row['retirement_state'] === 'ready')->count();
        $removalComplete = collect($tables)->filter(fn (array $row): bool => $row['retirement_state'] === 'removed')->count();
        $removalBlocked = collect($tables)->filter(fn (array $row): bool => $row['retirement_state'] === 'blocked')->count();
        $manualReviewRows = collect($tables)
            ->filter(fn (array $row): bool => $row['removal_candidate'] && $row['row_count'] > 0)
            ->sum(fn (array $row): int => $row['row_count']);

        return [
            'version' => 'sports-legacy-schema-data-readiness-v1',
            'read_only' => true,
            'summary' => [
                'forbidden_table_count' => count($tables),
                'removal_candidate_count' => $removalCandidates,
                'removal_complete_count' => $removalComplete,
                'removal_ready_count' => $removalReady,
                'removal_blocked_count' => $removalBlocked,
                'candidate_rows_requiring_review' => $manualReviewRows,
                'presence_unreconciled_count' => $presenceReconciliation['unreconciled_count'],
                'legacy_alias_columns_present' => collect($aliases)->where('present', true)->count(),
            ],
            'tables' => $tables,
            'presence_reconciliation' => $presenceReconciliation,
            'legacy_alias_columns' => $aliases,
            'decision_rule' => 'A removal candidate is complete when the table is absent and runtime references are zero; a still-present candidate may only be removed after row counts and runtime references are zero or explicitly reconciled/classified.',
        ];
    }

    /** @return array{legacy_count:int,reconciled_count:int,unreconciled_count:int,applicable:bool} */
    private function presenceReconciliation(): array
    {
        if (! Schema::hasTable('presences') || ! Schema::hasTable('training_athletes')) {
            return ['legacy_count' => 0, 'reconciled_count' => 0, 'unreconciled_count' => 0, 'applicable' => false];
        }

        $legacyCount = (int) DB::table('presences')->whereNotNull('treino_id')->count();
        $reconciled = (int) DB::table('presences as p')
            ->join('training_athletes as ta', function ($join): void {
                $join->on('ta.treino_id', '=', 'p.treino_id')
                    ->on('ta.user_id', '=', 'p.user_id');
            })
            ->whereNotNull('p.treino_id')
            ->count();

        return [
            'legacy_count' => $legacyCount,
            'reconciled_count' => $reconciled,
            'unreconciled_count' => max(0, $legacyCount - $reconciled),
            'applicable' => true,
        ];
    }

    /** @return list<array{table:string,canonical:string,legacy:string,present:bool,non_null_count:int,mismatch_count:int}> */
    private function legacyAliasColumns(): array
    {
        $definitions = [
            ['training_athletes', 'treino_id', 'training_id'],
            ['trainings', 'macrocycle_id', 'macrociclo_id'],
            ['trainings', 'mesociclo_id', 'mesocycle_id'],
            ['competitions', 'id', 'evento_id'],
            ['competition_registrations', 'id', 'fatura_id'],
        ];

        $rows = [];
        foreach ($definitions as [$table, $canonical, $legacy]) {
            $present = Schema::hasTable($table) && Schema::hasColumn($table, $legacy);
            $nonNull = $present ? (int) DB::table($table)->whereNotNull($legacy)->count() : 0;
            $mismatch = 0;

            if ($present && Schema::hasColumn($table, $canonical) && ! in_array($legacy, ['evento_id', 'fatura_id'], true)) {
                $mismatch = (int) DB::table($table)
                    ->whereNotNull($legacy)
                    ->where(function ($query) use ($canonical, $legacy): void {
                        $query->whereNull($canonical)->orWhereColumn($canonical, '!=', $legacy);
                    })
                    ->count();
            }

            $rows[] = [
                'table' => $table,
                'canonical' => $canonical,
                'legacy' => $legacy,
                'present' => $present,
                'non_null_count' => $nonNull,
                'mismatch_count' => $mismatch,
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    private function runtimeReferences(string $table): array
    {
        $roots = [base_path('app/Http'), base_path('app/Services'), base_path('app/Actions')];
        $references = [];

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                $path = $file->getPathname();
                if ($this->isAuditTooling($path)) {
                    continue;
                }

                $source = strtolower((string) File::get($path));
                if ($this->sourceReferencesLegacyTable($source, $table)) {
                    $references[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
                }
            }
        }

        sort($references);

        return array_values(array_unique($references));
    }

    private function isAuditTooling(string $path): bool
    {
        foreach (self::AUDIT_TOOLING_FILES as $file) {
            if (str_contains($path, $file)) {
                return true;
            }
        }

        return false;
    }

    private function sourceReferencesLegacyTable(string $source, string $table): bool
    {
        $quotedTable = preg_quote(strtolower($table), '/');
        $patterns = [
            '/(?:db|schema)::(?:table|hastable)\s*\(\s*[\'\"]'.$quotedTable.'(?:\s+as\s+[a-z0-9_]+)?[\'\"]/i',
            '/->(?:from|join|leftjoin|rightjoin|crossjoin)\s*\(\s*[\'\"]'.$quotedTable.'(?:\s+as\s+[a-z0-9_]+)?[\'\"]/i',
            '/(?:from|join|into|update|delete\s+from)\s+[\"`]?'.$quotedTable.'[\"`]?\b/i',
            '/\$table\s*=\s*[\'\"]'.$quotedTable.'[\'\"]/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }
}

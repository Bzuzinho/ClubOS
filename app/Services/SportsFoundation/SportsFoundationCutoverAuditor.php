<?php

declare(strict_types=1);

namespace App\Services\SportsFoundation;

use App\Models\CompetitionEventProjection;
use App\Models\CompetitionFinancialObligation;
use App\Models\SportsLegacyCutoverLedger;
use App\Support\SportsArchitectureBoundaryGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

final class SportsFoundationCutoverAuditor
{
    public function __construct(
        private readonly SportsLegacyCutoverLedgerService $ledgerService,
        private readonly SportsArchitectureBoundaryGuard $boundaryGuard,
    ) {
    }

    /** @return array<string,mixed> */
    public function audit(): array
    {
        $ledgerStatus = $this->ledgerService->refresh();
        $runtimeViolations = $this->runtimeSourceViolations();
        $boundaryViolations = $this->boundaryGuard->violations();
        $aliases = $this->aliasAudit();
        $compatibilityReads = $this->classifiedCompatibilityReads();
        $legacyRouteProtection = $this->legacyRouteProtectionEnabled();
        $aliasMismatchCount = collect($aliases)->sum(fn (array $row): int => (int) ($row['mismatch_count'] ?? 0));

        $projectionStatus = Schema::hasTable('competition_event_projections')
            ? CompetitionEventProjection::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->map(fn ($count): int => (int) $count)
                ->all()
            : [];

        $financeReviewCount = Schema::hasTable('competition_financial_obligations')
            ? CompetitionFinancialObligation::query()->where('status', 'manual_review')->count()
            : 0;

        $ledgerTotal = Schema::hasTable('sports_legacy_cutover_ledger')
            ? SportsLegacyCutoverLedger::query()->count()
            : 0;
        $ledgerClassified = Schema::hasTable('sports_legacy_cutover_ledger')
            ? SportsLegacyCutoverLedger::query()->whereNotNull('status')->count()
            : 0;
        $unclassified = max(0, $ledgerTotal - $ledgerClassified);

        $blockers = [
            'architecture_boundary_violations' => count($boundaryViolations),
            'runtime_source_violations' => count($runtimeViolations),
            'active_legacy_write_endpoints' => $legacyRouteProtection ? 0 : 4,
            'alias_mismatches' => $aliasMismatchCount,
            'unclassified_legacy_rows' => $unclassified,
        ];

        $foundationGreen = collect($blockers)->every(fn (int $count): bool => $count === 0);

        return [
            'version' => 'F7',
            'foundation_green' => $foundationGreen,
            'summary' => [
                'blockers' => $blockers,
                'legacy_ledger_total' => $ledgerTotal,
                'legacy_ledger_status' => $ledgerStatus,
                'competition_projection_status' => $projectionStatus,
                'finance_manual_review_count' => $financeReviewCount,
                'legacy_route_protection_enabled' => $legacyRouteProtection,
                'classified_compatibility_read_count' => count($compatibilityReads),
            ],
            'aliases' => $aliases,
            'classified_compatibility_reads' => $compatibilityReads,
            'runtime_source_violations' => $runtimeViolations,
            'architecture_boundary_violations' => $boundaryViolations,
            'manual_review_is_classified_not_guessed' => true,
        ];
    }

    /** @return list<array{scope:string,classification:string,reason:string}> */
    private function classifiedCompatibilityReads(): array
    {
        $reads = [];
        $builder = base_path('app/Services/Desportivo/DesportivoPagePayloadBuilder.php');

        if (File::exists($builder)) {
            $source = (string) File::get($builder);

            if (str_contains($source, "->whereJsonContains('tipo_membro', 'atleta')")) {
                $reads[] = [
                    'scope' => 'app/Services/Desportivo/DesportivoPagePayloadBuilder.php',
                    'classification' => 'read_only_presentation_adapter',
                    'reason' => 'legacy_member_type_filter_retained_only_in_existing_page_payload_until_functional_ui_cutover',
                ];
            }

            if (str_contains($source, "->get(['id', 'nome', 'data_inicio', 'local', 'tipo', 'evento_id'])")) {
                $reads[] = [
                    'scope' => 'app/Services/Desportivo/DesportivoPagePayloadBuilder.php',
                    'classification' => 'read_only_projection_alias',
                    'reason' => 'competition_event_legacy_column_is_not_written_after_f7_and_is_equivalence_audited',
                ];
            }

            if (str_contains($source, "Event::query()") && str_contains($source, "->where('tipo', 'prova')")) {
                $reads[] = [
                    'scope' => 'app/Services/Desportivo/DesportivoPagePayloadBuilder.php',
                    'classification' => 'legacy_event_presentation_adapter',
                    'reason' => 'unowned_event_rows_are_display_compatibility_only_and_never_create_or_mutate_competition_masters',
                ];
            }

            if (str_contains($source, 'MemberDocumentDataResolver') || str_contains($source, 'data_atestado_medico')) {
                $reads[] = [
                    'scope' => 'app/Services/Desportivo/DesportivoPagePayloadBuilder.php',
                    'classification' => 'member_document_presentation_adapter',
                    'reason' => 'medical_document_display_remains_read_only_from_membros_and_is_not_a_sports_clinical_source_of_truth',
                ];
            }
        }

        return $reads;
    }

    /** @return list<array{scope:string,needle:string}> */
    private function runtimeSourceViolations(): array
    {
        $checks = [
            'app/Services/Desportivo/CompetitionEventProjectionService.php' => [
                '->evento_id',
                'competition->evento_id',
            ],
            'app/Services/Desportivo/CompetitionFinanceContextService.php' => [
                'fatura_id',
                'legacyEvent',
                "with(['evento'",
            ],
            'app/Services/Financeiro/CompetitionFinancialObligationService.php' => [
                'syncCompatibilityInvoicePointers',
                'clearCompatibilityInvoicePointers',
                'legacyEventFee',
                'legacyCostCenterId',
                'use App\\Models\\CompetitionRegistration;',
            ],
            'app/Services/Communication/SegmentResolverService.php' => [
                'use App\\Models\\TeamMember;',
                'usersHaveAgeGroupColumn',
                "whereIn('age_group_id'",
            ],
        ];

        $violations = [];
        foreach ($checks as $relativePath => $needles) {
            $absolutePath = base_path($relativePath);
            if (! File::exists($absolutePath)) {
                continue;
            }

            $source = (string) File::get($absolutePath);
            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $violations[] = ['scope' => $relativePath, 'needle' => $needle];
                }
            }
        }

        if (File::exists(base_path('app/Services/Desportivo/SyncTrainingToEventAction.php'))) {
            $violations[] = [
                'scope' => 'app/Services/Desportivo/SyncTrainingToEventAction.php',
                'needle' => 'deprecated_training_event_attendance_sync_service_still_present',
            ];
        }

        return $violations;
    }

    /** @return list<array<string,mixed>> */
    private function aliasAudit(): array
    {
        return [
            $this->competitionEventAliasAudit(),
            $this->competitionFinanceAliasAudit(),
            $this->columnAliasAudit('training_athletes', 'treino_id', 'training_id'),
            $this->columnAliasAudit('trainings', 'macrocycle_id', 'macrociclo_id'),
            $this->columnAliasAudit('trainings', 'mesociclo_id', 'mesocycle_id'),
        ];
    }

    /** @return array<string,mixed> */
    private function competitionEventAliasAudit(): array
    {
        $result = [
            'relation' => 'competition_event',
            'legacy_field' => 'competitions.evento_id',
            'canonical_relation' => 'competition_event_projections.event_id',
            'legacy_non_null_count' => 0,
            'equivalent_count' => 0,
            'mismatch_count' => 0,
        ];

        if (! Schema::hasTable('competitions')
            || ! Schema::hasColumn('competitions', 'evento_id')
            || ! Schema::hasTable('competition_event_projections')) {
            return $result;
        }

        $rows = DB::table('competitions')
            ->whereNotNull('evento_id')
            ->get(['id', 'evento_id']);
        $result['legacy_non_null_count'] = $rows->count();

        foreach ($rows as $row) {
            $projection = DB::table('competition_event_projections')
                ->where('competition_id', $row->id)
                ->first(['event_id', 'legacy_event_id', 'status']);

            $matchesCurrent = $projection !== null
                && filled($projection->event_id)
                && (string) $projection->event_id === (string) $row->evento_id;
            $matchesHistorical = $projection !== null
                && ! filled($projection->event_id)
                && filled($projection->legacy_event_id)
                && (string) $projection->legacy_event_id === (string) $row->evento_id;

            if ($matchesCurrent || $matchesHistorical) {
                $result['equivalent_count']++;
            } else {
                $result['mismatch_count']++;
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function competitionFinanceAliasAudit(): array
    {
        $result = [
            'relation' => 'competition_registration_invoice',
            'legacy_field' => 'competition_registrations.fatura_id',
            'canonical_relation' => 'competition_financial_obligations.invoice_id',
            'legacy_non_null_count' => 0,
            'equivalent_count' => 0,
            'mismatch_count' => 0,
        ];

        if (! Schema::hasTable('competition_registrations')
            || ! Schema::hasColumn('competition_registrations', 'fatura_id')
            || ! Schema::hasTable('competition_financial_obligations')
            || ! Schema::hasTable('provas')) {
            return $result;
        }

        $rows = DB::table('competition_registrations as cr')
            ->join('provas as p', 'p.id', '=', 'cr.prova_id')
            ->whereNotNull('cr.fatura_id')
            ->get(['cr.id', 'cr.user_id', 'cr.fatura_id', 'p.competicao_id']);
        $result['legacy_non_null_count'] = $rows->count();

        foreach ($rows as $row) {
            $canonical = DB::table('competition_financial_obligations')
                ->where('competition_id', $row->competicao_id)
                ->where('user_id', $row->user_id)
                ->value('invoice_id');

            if ((string) $canonical === (string) $row->fatura_id) {
                $result['equivalent_count']++;
            } else {
                $result['mismatch_count']++;
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function columnAliasAudit(string $table, string $canonicalColumn, string $legacyColumn): array
    {
        $result = [
            'relation' => $table.'.'.$canonicalColumn,
            'legacy_field' => $table.'.'.$legacyColumn,
            'canonical_relation' => $table.'.'.$canonicalColumn,
            'legacy_non_null_count' => 0,
            'equivalent_count' => 0,
            'mismatch_count' => 0,
            'legacy_column_present' => false,
        ];

        if (! Schema::hasTable($table)
            || ! Schema::hasColumn($table, $canonicalColumn)
            || ! Schema::hasColumn($table, $legacyColumn)) {
            return $result;
        }

        $result['legacy_column_present'] = true;
        $rows = DB::table($table)
            ->whereNotNull($legacyColumn)
            ->get(['id', $canonicalColumn, $legacyColumn]);
        $result['legacy_non_null_count'] = $rows->count();

        foreach ($rows as $row) {
            if ((string) ($row->{$canonicalColumn} ?? '') === (string) ($row->{$legacyColumn} ?? '')) {
                $result['equivalent_count']++;
            } else {
                $result['mismatch_count']++;
            }
        }

        return $result;
    }

    private function legacyRouteProtectionEnabled(): bool
    {
        $providerPath = base_path('app/Providers/AppServiceProvider.php');
        $middlewarePath = base_path('app/Http/Middleware/EnforceSportsLegacyCutover.php');

        if (! File::exists($providerPath) || ! File::exists($middlewarePath)) {
            return false;
        }

        $provider = (string) File::get($providerPath);
        $middlewareRegistered = str_contains($provider, "pushMiddlewareToGroup('web', EnforceSportsLegacyCutover::class)")
            || str_contains($provider, "prependMiddlewareToGroup('web', EnforceSportsLegacyCutover::class)");

        return str_contains($provider, 'EnforceSportsLegacyCutover::class')
            && $middlewareRegistered;
    }
}

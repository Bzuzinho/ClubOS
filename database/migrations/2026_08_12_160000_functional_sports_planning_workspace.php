<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $this->expandPlanningCycles();
        $this->expandSchedulingContext();
        $this->expandCanonicalLaneReferences();
        $this->backfillCycleTenancy();
        $this->backfillCanonicalLanes();
        $this->backfillPoolContext();
        $this->finalizeLaneCutover();
    }

    private function expandPlanningCycles(): void
    {
        if (Schema::hasTable('macrocycles')) {
            Schema::table('macrocycles', function (Blueprint $table): void {
                if (! Schema::hasColumn('macrocycles', 'club_id')) $table->string('club_id', 64)->nullable()->index();
                if (! Schema::hasColumn('macrocycles', 'active')) $table->boolean('active')->default(true)->index();
                if (! Schema::hasColumn('macrocycles', 'archived_at')) $table->timestamp('archived_at')->nullable();
                if (! Schema::hasColumn('macrocycles', 'created_by')) $table->uuid('created_by')->nullable();
                if (! Schema::hasColumn('macrocycles', 'updated_by')) $table->uuid('updated_by')->nullable();
            });
        }

        if (Schema::hasTable('mesocycles')) {
            Schema::table('mesocycles', function (Blueprint $table): void {
                if (! Schema::hasColumn('mesocycles', 'club_id')) $table->string('club_id', 64)->nullable()->index();
                if (! Schema::hasColumn('mesocycles', 'active')) $table->boolean('active')->default(true)->index();
                if (! Schema::hasColumn('mesocycles', 'archived_at')) $table->timestamp('archived_at')->nullable();
                if (! Schema::hasColumn('mesocycles', 'created_by')) $table->uuid('created_by')->nullable();
                if (! Schema::hasColumn('mesocycles', 'updated_by')) $table->uuid('updated_by')->nullable();
            });
        }

        if (Schema::hasTable('microcycles')) {
            Schema::table('microcycles', function (Blueprint $table): void {
                if (! Schema::hasColumn('microcycles', 'club_id')) $table->string('club_id', 64)->nullable()->index();
                if (! Schema::hasColumn('microcycles', 'data_inicio')) $table->date('data_inicio')->nullable()->index();
                if (! Schema::hasColumn('microcycles', 'data_fim')) $table->date('data_fim')->nullable()->index();
                if (! Schema::hasColumn('microcycles', 'objetivo_principal')) $table->string('objetivo_principal')->nullable();
                if (! Schema::hasColumn('microcycles', 'objetivo_secundario')) $table->string('objetivo_secundario')->nullable();
                if (! Schema::hasColumn('microcycles', 'is_recovery_week')) $table->boolean('is_recovery_week')->default(false)->index();
                if (! Schema::hasColumn('microcycles', 'active')) $table->boolean('active')->default(true)->index();
                if (! Schema::hasColumn('microcycles', 'archived_at')) $table->timestamp('archived_at')->nullable();
                if (! Schema::hasColumn('microcycles', 'created_by')) $table->uuid('created_by')->nullable();
                if (! Schema::hasColumn('microcycles', 'updated_by')) $table->uuid('updated_by')->nullable();
            });
        }
    }

    private function expandSchedulingContext(): void
    {
        if (Schema::hasTable('training_recurrences')) {
            Schema::table('training_recurrences', function (Blueprint $table): void {
                if (! Schema::hasColumn('training_recurrences', 'season_id')) $table->uuid('season_id')->nullable()->index();
                if (! Schema::hasColumn('training_recurrences', 'macrocycle_id')) $table->uuid('macrocycle_id')->nullable()->index();
                if (! Schema::hasColumn('training_recurrences', 'mesocycle_id')) $table->uuid('mesocycle_id')->nullable()->index();
                if (! Schema::hasColumn('training_recurrences', 'microcycle_id')) $table->uuid('microcycle_id')->nullable()->index();
                if (! Schema::hasColumn('training_recurrences', 'sports_pool_id')) $table->uuid('sports_pool_id')->nullable()->index();
                if (! Schema::hasColumn('training_recurrences', 'updated_by')) $table->uuid('updated_by')->nullable();
                if (! Schema::hasColumn('training_recurrences', 'archived_at')) $table->timestamp('archived_at')->nullable();
            });
        }

        if (Schema::hasTable('trainings')) {
            Schema::table('trainings', function (Blueprint $table): void {
                if (! Schema::hasColumn('trainings', 'sports_pool_id')) $table->uuid('sports_pool_id')->nullable()->index();
            });
        }
    }

    private function expandCanonicalLaneReferences(): void
    {
        if (Schema::hasTable('training_session_group_lanes')) {
            Schema::table('training_session_group_lanes', function (Blueprint $table): void {
                if (! Schema::hasColumn('training_session_group_lanes', 'sports_pool_lane_id')) {
                    $table->uuid('sports_pool_lane_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('training_recurrence_group_lanes')) {
            Schema::table('training_recurrence_group_lanes', function (Blueprint $table): void {
                if (! Schema::hasColumn('training_recurrence_group_lanes', 'sports_pool_lane_id')) {
                    $table->uuid('sports_pool_lane_id')->nullable()->index();
                }
            });
        }

        if (Schema::hasTable('sports_venue_closures')) {
            Schema::table('sports_venue_closures', function (Blueprint $table): void {
                if (! Schema::hasColumn('sports_venue_closures', 'sports_pool_id')) $table->uuid('sports_pool_id')->nullable()->index();
                if (! Schema::hasColumn('sports_venue_closures', 'sports_pool_lane_id')) $table->uuid('sports_pool_lane_id')->nullable()->index();
            });
        }
    }

    private function backfillCycleTenancy(): void
    {
        if (Schema::hasTable('macrocycles') && Schema::hasTable('seasons')) {
            foreach (DB::table('macrocycles')->whereNull('club_id')->get(['id', 'epoca_id']) as $row) {
                $clubId = DB::table('seasons')->where('id', $row->epoca_id)->value('club_id');
                if ($clubId) DB::table('macrocycles')->where('id', $row->id)->update(['club_id' => $clubId]);
            }
        }

        if (Schema::hasTable('mesocycles') && Schema::hasTable('macrocycles')) {
            foreach (DB::table('mesocycles')->whereNull('club_id')->get(['id', 'macrociclo_id']) as $row) {
                $clubId = DB::table('macrocycles')->where('id', $row->macrociclo_id)->value('club_id');
                if ($clubId) DB::table('mesocycles')->where('id', $row->id)->update(['club_id' => $clubId]);
            }
        }

        if (Schema::hasTable('microcycles') && Schema::hasTable('mesocycles')) {
            foreach (DB::table('microcycles')->whereNull('club_id')->get(['id', 'mesociclo_id']) as $row) {
                $clubId = DB::table('mesocycles')->where('id', $row->mesociclo_id)->value('club_id');
                if ($clubId) DB::table('microcycles')->where('id', $row->id)->update(['club_id' => $clubId]);
            }
        }
    }

    private function backfillCanonicalLanes(): void
    {
        if (! Schema::hasTable('sports_pool_lanes')) return;

        $map = DB::table('sports_pool_lanes')
            ->whereNotNull('legacy_sports_venue_lane_id')
            ->get(['id', 'sports_pool_id', 'legacy_sports_venue_lane_id'])
            ->keyBy('legacy_sports_venue_lane_id');

        foreach (['training_session_group_lanes', 'training_recurrence_group_lanes'] as $table) {
            if (! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'sports_venue_lane_id')
                || ! Schema::hasColumn($table, 'sports_pool_lane_id')) {
                continue;
            }

            foreach (DB::table($table)->whereNull('sports_pool_lane_id')->whereNotNull('sports_venue_lane_id')->get() as $row) {
                $lane = $map->get($row->sports_venue_lane_id);
                if (! $lane) continue;

                $query = DB::table($table)->where('sports_venue_lane_id', $row->sports_venue_lane_id);
                if (isset($row->training_session_group_id)) $query->where('training_session_group_id', $row->training_session_group_id);
                if (isset($row->training_recurrence_group_id)) $query->where('training_recurrence_group_id', $row->training_recurrence_group_id);
                $query->update(['sports_pool_lane_id' => $lane->id]);
            }
        }

        if (Schema::hasTable('sports_venue_closures') && Schema::hasColumn('sports_venue_closures', 'sports_venue_lane_id')) {
            foreach (DB::table('sports_venue_closures')->whereNull('sports_pool_lane_id')->whereNotNull('sports_venue_lane_id')->get(['id', 'sports_venue_lane_id']) as $row) {
                $lane = $map->get($row->sports_venue_lane_id);
                if (! $lane) continue;
                DB::table('sports_venue_closures')->where('id', $row->id)->update([
                    'sports_pool_id' => $lane->sports_pool_id,
                    'sports_pool_lane_id' => $lane->id,
                ]);
            }
        }
    }

    private function backfillPoolContext(): void
    {
        if (Schema::hasTable('trainings') && Schema::hasTable('training_session_groups') && Schema::hasTable('training_session_group_lanes')) {
            $rows = DB::table('trainings')->whereNull('sports_pool_id')->get(['id']);
            foreach ($rows as $training) {
                $poolIds = DB::table('training_session_groups')
                    ->join('training_session_group_lanes', 'training_session_groups.id', '=', 'training_session_group_lanes.training_session_group_id')
                    ->join('sports_pool_lanes', 'training_session_group_lanes.sports_pool_lane_id', '=', 'sports_pool_lanes.id')
                    ->where('training_session_groups.training_id', $training->id)
                    ->whereNotNull('training_session_group_lanes.sports_pool_lane_id')
                    ->distinct()->pluck('sports_pool_lanes.sports_pool_id');
                if ($poolIds->count() === 1) DB::table('trainings')->where('id', $training->id)->update(['sports_pool_id' => $poolIds->first()]);
            }
        }

        if (Schema::hasTable('training_recurrences') && Schema::hasTable('training_recurrence_groups') && Schema::hasTable('training_recurrence_group_lanes')) {
            $rows = DB::table('training_recurrences')->whereNull('sports_pool_id')->get(['id']);
            foreach ($rows as $recurrence) {
                $poolIds = DB::table('training_recurrence_groups')
                    ->join('training_recurrence_group_lanes', 'training_recurrence_groups.id', '=', 'training_recurrence_group_lanes.training_recurrence_group_id')
                    ->join('sports_pool_lanes', 'training_recurrence_group_lanes.sports_pool_lane_id', '=', 'sports_pool_lanes.id')
                    ->where('training_recurrence_groups.training_recurrence_id', $recurrence->id)
                    ->whereNotNull('training_recurrence_group_lanes.sports_pool_lane_id')
                    ->distinct()->pluck('sports_pool_lanes.sports_pool_id');
                if ($poolIds->count() === 1) DB::table('training_recurrences')->where('id', $recurrence->id)->update(['sports_pool_id' => $poolIds->first()]);
            }
        }
    }

    private function finalizeLaneCutover(): void
    {
        if (Schema::hasTable('training_session_group_lanes')) {
            Schema::table('training_session_group_lanes', function (Blueprint $table): void {
                // Legacy pointer remains only for rollback/history; canonical writes may leave it null.
                $table->uuid('sports_venue_lane_id')->nullable()->change();
                $table->unique(
                    ['training_session_group_id', 'sports_pool_lane_id'],
                    'uq_training_session_group_pool_lane'
                );
            });
        }

        if (Schema::hasTable('training_recurrence_group_lanes')) {
            Schema::table('training_recurrence_group_lanes', function (Blueprint $table): void {
                $table->uuid('sports_venue_lane_id')->nullable()->change();
                $table->unique(
                    ['training_recurrence_group_id', 'sports_pool_lane_id'],
                    'uq_training_recurrence_group_pool_lane'
                );
            });
        }
    }

    public function down(): void
    {
        // Expand-first cutover. Historical compatibility columns are intentionally retained.
    }
};

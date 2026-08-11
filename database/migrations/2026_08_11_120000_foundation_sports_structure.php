<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $clubId = (string) config('sports.club_id', 'bscn');

        if (! Schema::hasTable('sports_modalities')) {
            Schema::create('sports_modalities', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->string('code', 64);
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamp('archived_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['club_id', 'code']);
            });
        }

        if (! Schema::hasTable('sports_programs')) {
            Schema::create('sports_programs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('sports_modality_id')->index();
                $table->string('code', 64);
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamp('archived_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['club_id', 'sports_modality_id', 'code'], 'sports_programs_scope_code_unique');
            });
        }

        if (! Schema::hasTable('season_programs')) {
            Schema::create('season_programs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('season_id')->index();
                $table->uuid('sports_program_id')->index();
                $table->boolean('active')->default(true)->index();
                $table->json('settings_json')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['season_id', 'sports_program_id']);
            });
        }

        if (! Schema::hasTable('season_age_group_rules')) {
            Schema::create('season_age_group_rules', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('season_id')->index();
                $table->uuid('sports_modality_id')->index();
                $table->uuid('age_group_id')->index();
                $table->string('gender', 32)->nullable()->index();
                $table->integer('birth_year_min')->nullable();
                $table->integer('birth_year_max')->nullable();
                $table->unsignedSmallInteger('age_min')->nullable();
                $table->unsignedSmallInteger('age_max')->nullable();
                $table->date('reference_date')->nullable();
                $table->integer('priority')->default(0)->index();
                $table->boolean('active')->default(true)->index();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('training_group_seasons')) {
            Schema::create('training_group_seasons', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('training_group_id')->index();
                $table->uuid('season_id')->index();
                $table->uuid('sports_program_id')->nullable()->index();
                $table->boolean('active')->default(true)->index();
                $table->json('settings_json')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['training_group_id', 'season_id']);
            });
        }

        if (! Schema::hasTable('sports_coach_roles')) {
            Schema::create('sports_coach_roles', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->string('code', 64);
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamp('archived_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['club_id', 'code']);
            });
        }

        if (! Schema::hasTable('sports_pools')) {
            Schema::create('sports_pools', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('sports_venue_id')->index();
                $table->uuid('pool_type_config_id')->nullable()->index();
                $table->string('code', 80);
                $table->string('name');
                $table->decimal('length_m', 6, 2)->nullable();
                $table->boolean('indoor')->nullable();
                $table->unsignedInteger('capacity')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->timestamp('archived_at')->nullable();
                $table->json('metadata_json')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['club_id', 'sports_venue_id', 'code'], 'sports_pools_location_code_unique');
            });
        }

        if (! Schema::hasTable('sports_pool_lanes')) {
            Schema::create('sports_pool_lanes', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('sports_pool_id')->index();
                $table->unsignedInteger('lane_number')->nullable();
                $table->string('name')->nullable();
                $table->unsignedInteger('capacity')->nullable();
                $table->boolean('active')->default(true)->index();
                $table->uuid('legacy_sports_venue_lane_id')->nullable()->unique();
                $table->json('metadata_json')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['sports_pool_id', 'lane_number']);
            });
        }

        if (! Schema::hasTable('athlete_age_group_overrides')) {
            Schema::create('athlete_age_group_overrides', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('club_id', 64)->index();
                $table->uuid('user_id')->index();
                $table->uuid('season_id')->index();
                $table->uuid('sports_modality_id')->index();
                $table->uuid('age_group_id')->index();
                $table->text('reason');
                $table->boolean('active')->default(true)->index();
                $table->timestamp('effective_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->uuid('ended_by')->nullable();
                $table->timestamps();
            });
        }

        $this->expandLegacyTables();
        $this->backfill($clubId);
        $this->syncPermissionNode();
    }

    private function expandLegacyTables(): void
    {
        if (Schema::hasTable('seasons')) {
            Schema::table('seasons', function (Blueprint $table) {
                if (! Schema::hasColumn('seasons', 'club_id')) $table->string('club_id', 64)->nullable()->index();
                if (! Schema::hasColumn('seasons', 'sports_modality_id')) $table->uuid('sports_modality_id')->nullable()->index();
                if (! Schema::hasColumn('seasons', 'status')) $table->string('status', 24)->default('planned')->index();
                if (! Schema::hasColumn('seasons', 'closed_at')) $table->timestamp('closed_at')->nullable();
                if (! Schema::hasColumn('seasons', 'closed_by')) $table->uuid('closed_by')->nullable();
                if (! Schema::hasColumn('seasons', 'reopened_at')) $table->timestamp('reopened_at')->nullable();
                if (! Schema::hasColumn('seasons', 'reopened_by')) $table->uuid('reopened_by')->nullable();
                if (! Schema::hasColumn('seasons', 'reopen_reason')) $table->text('reopen_reason')->nullable();
            });
        }

        if (Schema::hasTable('age_groups')) {
            Schema::table('age_groups', function (Blueprint $table) {
                if (! Schema::hasColumn('age_groups', 'club_id')) $table->string('club_id', 64)->nullable()->index();
                if (! Schema::hasColumn('age_groups', 'code')) $table->string('code', 64)->nullable();
                if (! Schema::hasColumn('age_groups', 'archived_at')) $table->timestamp('archived_at')->nullable();
            });
        }

        if (Schema::hasTable('training_groups')) {
            Schema::table('training_groups', function (Blueprint $table) {
                if (! Schema::hasColumn('training_groups', 'sports_modality_id')) $table->uuid('sports_modality_id')->nullable()->index();
                if (! Schema::hasColumn('training_groups', 'archived_at')) $table->timestamp('archived_at')->nullable();
            });
        }

        if (Schema::hasTable('training_group_memberships')) {
            Schema::table('training_group_memberships', function (Blueprint $table) {
                if (! Schema::hasColumn('training_group_memberships', 'training_group_season_id')) $table->uuid('training_group_season_id')->nullable()->index();
                if (! Schema::hasColumn('training_group_memberships', 'notes')) $table->text('notes')->nullable();
            });
        }

        if (Schema::hasTable('training_group_coaches')) {
            Schema::table('training_group_coaches', function (Blueprint $table) {
                if (! Schema::hasColumn('training_group_coaches', 'training_group_season_id')) $table->uuid('training_group_season_id')->nullable()->index();
                if (! Schema::hasColumn('training_group_coaches', 'sports_coach_role_id')) $table->uuid('sports_coach_role_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('sports_venues')) {
            Schema::table('sports_venues', function (Blueprint $table) {
                if (! Schema::hasColumn('sports_venues', 'archived_at')) $table->timestamp('archived_at')->nullable();
            });
        }
    }

    private function backfill(string $clubId): void
    {
        $legacyModalities = collect(['swimming']);
        if (Schema::hasTable('training_groups') && Schema::hasColumn('training_groups', 'modality')) {
            $legacyModalities = $legacyModalities->merge(DB::table('training_groups')->whereNotNull('modality')->pluck('modality'));
        }

        foreach ($legacyModalities->filter()->unique() as $legacy) {
            $code = $legacy === 'swimming' ? 'swimming' : Str::slug((string) $legacy, '-');
            if ($code === '') continue;
            if (! DB::table('sports_modalities')->where('club_id', $clubId)->where('code', $code)->exists()) {
                DB::table('sports_modalities')->insert([
                    'id' => (string) Str::uuid(), 'club_id' => $clubId, 'code' => $code,
                    'name' => $code === 'swimming' ? 'Natação' : (string) $legacy,
                    'active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        $swimmingId = DB::table('sports_modalities')->where('club_id', $clubId)->where('code', 'swimming')->value('id');

        if (Schema::hasTable('seasons')) {
            DB::table('seasons')->whereNull('club_id')->update(['club_id' => $clubId]);
            DB::table('seasons')->whereNull('sports_modality_id')->update(['sports_modality_id' => $swimmingId]);
            if (Schema::hasColumn('seasons', 'ativa')) {
                DB::table('seasons')->where('ativa', true)->where('status', 'planned')->update(['status' => 'active']);
            }
        }

        if (Schema::hasTable('age_groups')) {
            DB::table('age_groups')->whereNull('club_id')->update(['club_id' => $clubId]);
            DB::table('age_groups')->orderBy('id')->get(['id', 'nome', 'code'])->each(function ($group) use ($clubId) {
                if (! $group->code) {
                    $base = Str::slug((string) $group->nome, '-');
                    $code = $base !== '' ? $base : 'escalão-' . substr((string) $group->id, 0, 8);
                    $suffix = 2;
                    while (DB::table('age_groups')->where('club_id', $clubId)->where('code', $code)->where('id', '!=', $group->id)->exists()) {
                        $code = $base . '-' . $suffix++;
                    }
                    DB::table('age_groups')->where('id', $group->id)->update(['code' => $code]);
                }
            });
        }

        if (Schema::hasTable('training_groups')) {
            DB::table('training_groups')->get(['id', 'modality', 'sports_modality_id'])->each(function ($group) use ($clubId, $swimmingId) {
                if ($group->sports_modality_id) return;
                $code = $group->modality ? Str::slug((string) $group->modality, '-') : 'swimming';
                $modalityId = DB::table('sports_modalities')->where('club_id', $clubId)->where('code', $code)->value('id') ?: $swimmingId;
                DB::table('training_groups')->where('id', $group->id)->update(['sports_modality_id' => $modalityId]);
            });
        }

        if (Schema::hasTable('training_group_coaches')) {
            foreach (DB::table('training_group_coaches')->whereNotNull('role')->pluck('role')->filter()->unique() as $legacyRole) {
                $code = Str::slug((string) $legacyRole, '-');
                if ($code === '') continue;
                $roleId = DB::table('sports_coach_roles')->where('club_id', $clubId)->where('code', $code)->value('id');
                if (! $roleId) {
                    $roleId = (string) Str::uuid();
                    DB::table('sports_coach_roles')->insert(['id' => $roleId, 'club_id' => $clubId, 'code' => $code, 'name' => (string) $legacyRole, 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('training_group_coaches')->where('role', $legacyRole)->whereNull('sports_coach_role_id')->update(['sports_coach_role_id' => $roleId]);
            }
        }

        if (Schema::hasTable('sports_venues') && Schema::hasTable('sports_venue_lanes')) {
            foreach (DB::table('sports_venues')->where('club_id', $clubId)->get() as $venue) {
                $lanes = DB::table('sports_venue_lanes')->where('sports_venue_id', $venue->id)->get();
                if ($lanes->isEmpty()) continue;
                $poolId = DB::table('sports_pools')->where('sports_venue_id', $venue->id)->value('id');
                if (! $poolId) {
                    $poolId = (string) Str::uuid();
                    DB::table('sports_pools')->insert([
                        'id' => $poolId, 'club_id' => $clubId, 'sports_venue_id' => $venue->id,
                        'code' => 'principal', 'name' => 'Piscina principal', 'active' => true,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                foreach ($lanes as $lane) {
                    if (DB::table('sports_pool_lanes')->where('legacy_sports_venue_lane_id', $lane->id)->exists()) continue;
                    DB::table('sports_pool_lanes')->insert([
                        'id' => (string) Str::uuid(), 'club_id' => $clubId, 'sports_pool_id' => $poolId,
                        'lane_number' => $lane->lane_number, 'name' => $lane->name, 'capacity' => $lane->capacity,
                        'active' => (bool) $lane->active, 'legacy_sports_venue_lane_id' => $lane->id,
                        'metadata_json' => $lane->metadata, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function syncPermissionNode(): void
    {
        if (! Schema::hasTable('permission_nodes')) return;
        $parentId = DB::table('permission_nodes')->where('key', 'desportivo')->value('id');
        if (! $parentId || DB::table('permission_nodes')->where('key', 'desportivo.estrutura')->exists()) return;
        DB::table('permission_nodes')->insert([
            'id' => (string) Str::uuid(), 'key' => 'desportivo.estrutura', 'label' => 'Estrutura Desportiva',
            'parent_id' => $parentId, 'module_key' => 'desportivo', 'node_type' => 'submodule',
            'sort_order' => 15, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Expand-first foundation: rollback intentionally avoids destructive removal of backfilled structure.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->createParticipations();
        $this->createSeasonProfiles();
        $this->createFederations();
        $this->createFederationAffiliations();
        $this->createOperationalLimitations();
        $this->backfillUnambiguousActiveParticipation();
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_athlete_limitations');
        Schema::dropIfExists('sports_athlete_federation_affiliations');
        Schema::dropIfExists('sports_federations');
        Schema::dropIfExists('sports_athlete_season_profiles');
        Schema::dropIfExists('sports_athlete_participations');
    }

    private function createParticipations(): void
    {
        if (Schema::hasTable('sports_athlete_participations')) {
            return;
        }

        Schema::create('sports_athlete_participations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->uuid('user_id')->index();
            $table->uuid('sports_modality_id')->index();
            $table->boolean('active')->default(true)->index();
            // Non-null only for the current open period. The unique constraint
            // makes concurrent duplicate activation impossible across DB engines.
            $table->string('current_slot', 16)->nullable();
            $table->date('starts_at')->nullable()->index();
            $table->date('ends_at')->nullable()->index();
            $table->string('source', 32)->default('sports');
            $table->text('start_reason')->nullable();
            $table->text('end_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['club_id', 'user_id', 'sports_modality_id', 'current_slot'],
                'sports_athlete_current_participation_unique'
            );
            $table->index(
                ['club_id', 'user_id', 'sports_modality_id', 'active'],
                'sports_athlete_participation_active_idx'
            );
        });
    }

    private function createSeasonProfiles(): void
    {
        if (Schema::hasTable('sports_athlete_season_profiles')) {
            return;
        }

        Schema::create('sports_athlete_season_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->uuid('user_id')->index();
            $table->uuid('sports_athlete_participation_id')->index();
            $table->uuid('season_id')->index();
            $table->uuid('sports_modality_id')->index();
            $table->uuid('calculated_age_group_id')->nullable()->index();
            $table->uuid('official_age_group_id')->nullable()->index();
            $table->string('placement_source', 24)->nullable()->index();
            $table->uuid('season_age_group_rule_id')->nullable()->index();
            $table->uuid('athlete_age_group_override_id')->nullable()->index();
            $table->date('reference_date')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->uuid('evaluated_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['user_id', 'season_id', 'sports_modality_id'],
                'sports_athlete_season_profile_unique'
            );
        });
    }

    private function createFederations(): void
    {
        if (Schema::hasTable('sports_federations')) {
            return;
        }

        Schema::create('sports_federations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('code', 80);
            $table->string('name');
            $table->string('country_code', 8)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'code'], 'sports_federation_scope_code_unique');
        });
    }

    private function createFederationAffiliations(): void
    {
        if (Schema::hasTable('sports_athlete_federation_affiliations')) {
            return;
        }

        Schema::create('sports_athlete_federation_affiliations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->uuid('user_id')->index();
            $table->uuid('sports_athlete_participation_id')->index();
            $table->uuid('sports_modality_id')->index();
            $table->uuid('sports_federation_id')->index();
            $table->string('membership_number', 120)->nullable();
            $table->string('license_number', 120)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(
                ['club_id', 'user_id', 'sports_modality_id', 'active'],
                'sports_athlete_federation_active_idx'
            );
        });
    }

    private function createOperationalLimitations(): void
    {
        if (Schema::hasTable('sports_athlete_limitations')) {
            return;
        }

        Schema::create('sports_athlete_limitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->uuid('user_id')->index();
            $table->uuid('sports_modality_id')->nullable()->index();
            $table->uuid('sports_limitation_type_id')->index();
            $table->date('starts_at')->index();
            $table->date('ends_at')->nullable()->index();
            $table->text('operational_instruction')->nullable();
            $table->boolean('allows_training')->default(true);
            $table->boolean('allows_competition')->default(true);
            $table->boolean('active')->default(true)->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('ended_by')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(
                ['club_id', 'user_id', 'active'],
                'sports_athlete_limitation_active_idx'
            );
        });
    }

    private function backfillUnambiguousActiveParticipation(): void
    {
        if (! Schema::hasTable('athlete_sports_data') || ! Schema::hasTable('sports_modalities')) {
            return;
        }

        $clubId = (string) config('sports.club_id', 'bscn');
        $modalities = DB::table('sports_modalities')
            ->where('club_id', $clubId)
            ->where('active', true)
            ->whereNull('archived_at')
            ->pluck('id');

        if ($modalities->count() !== 1) {
            return;
        }

        $modalityId = (string) $modalities->first();

        DB::table('athlete_sports_data')
            ->where('ativo', true)
            ->orderBy('user_id')
            ->get(['user_id', 'data_inscricao'])
            ->each(function ($legacy) use ($clubId, $modalityId): void {
                $exists = DB::table('sports_athlete_participations')
                    ->where('club_id', $clubId)
                    ->where('user_id', $legacy->user_id)
                    ->where('sports_modality_id', $modalityId)
                    ->where('current_slot', 'current')
                    ->exists();

                if ($exists) {
                    return;
                }

                DB::table('sports_athlete_participations')->insert([
                    'id' => (string) Str::uuid(),
                    'club_id' => $clubId,
                    'user_id' => $legacy->user_id,
                    'sports_modality_id' => $modalityId,
                    'active' => true,
                    'current_slot' => 'current',
                    'starts_at' => $legacy->data_inscricao ?: null,
                    'ends_at' => null,
                    'source' => 'legacy',
                    'start_reason' => 'Backfill F3 de atividade desportiva legacy inequívoca.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }
};

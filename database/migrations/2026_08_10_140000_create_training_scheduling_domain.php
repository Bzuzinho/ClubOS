<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_venues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->string('venue_type', 32)->default('pool');
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['club_id', 'code'], 'uq_sports_venues_club_code');
            $table->index(['club_id', 'active', 'name'], 'idx_sports_venues_lookup');
        });

        Schema::create('sports_venue_lanes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('sports_venue_id')->constrained('sports_venues')->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedSmallInteger('lane_number')->nullable();
            $table->unsignedSmallInteger('capacity')->default(8);
            $table->boolean('active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['sports_venue_id', 'code'], 'uq_sports_venue_lanes_code');
            $table->unique(['sports_venue_id', 'lane_number'], 'uq_sports_venue_lanes_number');
            $table->index(['club_id', 'sports_venue_id', 'active'], 'idx_sports_venue_lanes_lookup');
        });

        Schema::create('sports_venue_closures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('sports_venue_id')->constrained('sports_venues')->cascadeOnDelete();
            $table->foreignUuid('sports_venue_lane_id')->nullable()->constrained('sports_venue_lanes')->cascadeOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
                ['club_id', 'sports_venue_id', 'starts_at', 'ends_at'],
                'idx_sports_venue_closures_window'
            );
        });

        Schema::create('training_recurrences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('frequency', 16)->default('weekly');
            $table->unsignedSmallInteger('interval')->default(1);
            $table->json('weekdays')->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignUuid('sports_venue_id')->nullable()->constrained('sports_venues')->nullOnDelete();
            $table->string('local_snapshot')->nullable();
            $table->foreignUuid('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('training_plan_version_id')->nullable()->constrained('training_plan_versions')->nullOnDelete();
            $table->text('instruction')->nullable();
            $table->string('training_type', 100)->nullable();
            $table->string('session_status_template', 24)->default('draft');
            $table->boolean('active')->default(true);
            $table->date('last_generated_until')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['club_id', 'active', 'starts_on', 'ends_on'], 'idx_training_recurrences_window');
        });

        Schema::create('training_recurrence_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('training_recurrence_id')->constrained('training_recurrences')->cascadeOnDelete();
            $table->foreignUuid('training_group_id')->constrained('training_groups')->restrictOnDelete();
            $table->foreignUuid('training_plan_version_id')->nullable()->constrained('training_plan_versions')->nullOnDelete();
            $table->text('instruction')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(
                ['training_recurrence_id', 'training_group_id'],
                'uq_training_recurrence_group'
            );
        });

        Schema::create('training_recurrence_group_lanes', function (Blueprint $table): void {
            $table->string('club_id', 64)->index();
            $table->uuid('training_recurrence_group_id');
            $table->uuid('sports_venue_lane_id');
            $table->unsignedSmallInteger('planned_capacity')->nullable();
            $table->timestamps();

            $table->foreign(
                'training_recurrence_group_id',
                'fk_trg_lanes_group'
            )->references('id')->on('training_recurrence_groups')->cascadeOnDelete();
            $table->foreign(
                'sports_venue_lane_id',
                'fk_trg_lanes_lane'
            )->references('id')->on('sports_venue_lanes')->restrictOnDelete();
            $table->unique(
                ['training_recurrence_group_id', 'sports_venue_lane_id'],
                'uq_training_recurrence_group_lane'
            );
        });

        Schema::table('trainings', function (Blueprint $table): void {
            $table->foreignUuid('sports_venue_id')->nullable()->constrained('sports_venues')->nullOnDelete();
            $table->foreignUuid('training_recurrence_id')->nullable()->constrained('training_recurrences')->nullOnDelete();
            $table->string('recurrence_occurrence_key', 64)->nullable();
            $table->boolean('schedule_review_required')->default(false)->index();
            $table->json('schedule_conflicts_snapshot')->nullable();

            $table->unique(
                ['training_recurrence_id', 'recurrence_occurrence_key'],
                'uq_trainings_recurrence_occ'
            );
        });

        Schema::create('training_session_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->foreignUuid('training_group_id')->constrained('training_groups')->restrictOnDelete();
            $table->foreignUuid('training_plan_version_id')->nullable()->constrained('training_plan_versions')->nullOnDelete();
            $table->text('instruction')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['training_id', 'training_group_id'], 'uq_training_session_group');
            $table->index(['club_id', 'training_id', 'sort_order'], 'idx_training_session_groups');
        });

        Schema::create('training_session_group_lanes', function (Blueprint $table): void {
            $table->string('club_id', 64)->index();
            $table->uuid('training_session_group_id');
            $table->uuid('sports_venue_lane_id');
            $table->unsignedSmallInteger('planned_capacity')->nullable();
            $table->timestamps();

            $table->foreign(
                'training_session_group_id',
                'fk_tsg_lanes_group'
            )->references('id')->on('training_session_groups')->cascadeOnDelete();
            $table->foreign(
                'sports_venue_lane_id',
                'fk_tsg_lanes_lane'
            )->references('id')->on('sports_venue_lanes')->restrictOnDelete();
            $table->unique(
                ['training_session_group_id', 'sports_venue_lane_id'],
                'uq_training_session_group_lane'
            );
        });

        Schema::create('training_schedule_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->string('exception_type', 32);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->text('reason');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['club_id', 'training_id', 'recorded_at'], 'idx_training_schedule_exceptions');
        });

        Schema::table('club_settings', function (Blueprint $table): void {
            $table->string('sports_lane_overlap_policy', 16)->default('warn');
            $table->string('sports_athlete_overlap_policy', 16)->default('warn');
            $table->string('sports_capacity_policy', 16)->default('warn');
        });
    }

    public function down(): void
    {
        Schema::table('club_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'sports_lane_overlap_policy',
                'sports_athlete_overlap_policy',
                'sports_capacity_policy',
            ]);
        });

        Schema::dropIfExists('training_schedule_exceptions');
        Schema::dropIfExists('training_session_group_lanes');
        Schema::dropIfExists('training_session_groups');

        Schema::table('trainings', function (Blueprint $table): void {
            $table->dropUnique('uq_trainings_recurrence_occ');
            $table->dropForeign(['sports_venue_id']);
            $table->dropForeign(['training_recurrence_id']);
            $table->dropColumn([
                'sports_venue_id',
                'training_recurrence_id',
                'recurrence_occurrence_key',
                'schedule_review_required',
                'schedule_conflicts_snapshot',
            ]);
        });

        Schema::dropIfExists('training_recurrence_group_lanes');
        Schema::dropIfExists('training_recurrence_groups');
        Schema::dropIfExists('training_recurrences');
        Schema::dropIfExists('sports_venue_closures');
        Schema::dropIfExists('sports_venue_lanes');
        Schema::dropIfExists('sports_venues');
    }
};

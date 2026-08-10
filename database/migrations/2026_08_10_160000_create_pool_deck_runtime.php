<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $clubId = trim((string) config('sports.club_id', 'bscn')) ?: 'bscn';

        Schema::table('trainings', function (Blueprint $table): void {
            $table->string('pool_deck_status', 24)->default('planned')->index();
            $table->unsignedInteger('pool_deck_version')->default(0);
            $table->timestamp('pool_deck_opened_at')->nullable();
            $table->foreignUuid('pool_deck_opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pool_deck_closed_at')->nullable();
            $table->foreignUuid('pool_deck_closed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('training_athletes', function (Blueprint $table): void {
            $table->unsignedInteger('cais_version')->default(0);
            $table->string('cais_status_source', 32)->default('planning');
            $table->timestamp('cais_last_modified_at')->nullable();
            $table->foreignUuid('cais_last_modified_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('training_metrics', function (Blueprint $table) use ($clubId): void {
            $table->string('club_id', 64)->default($clubId)->index();
            $table->foreignUuid('training_series_id')->nullable()->constrained('training_series')->nullOnDelete();
            $table->string('measurement_type', 40)->default('time');
            $table->unsignedInteger('total_distance_m')->nullable();
            $table->string('repetition_mode', 24)->default('one_off');
            $table->unsignedInteger('repetition_number')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->json('splits_json')->nullable();
            $table->string('source', 32)->default('pool_deck');
            $table->string('client_event_id', 128)->nullable();
            $table->timestamp('client_recorded_at')->nullable();
            $table->foreignUuid('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('server_version')->default(1);

            $table->unique(['club_id', 'client_event_id'], 'uq_training_metrics_client_event');
            $table->index(
                ['club_id', 'treino_id', 'user_id', 'training_series_id'],
                'idx_training_metrics_pool_deck'
            );
        });

        DB::table('training_metrics')->update(['club_id' => $clubId]);

        Schema::create('training_pool_deck_timers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->foreignUuid('training_athlete_id')->nullable()->constrained('training_athletes')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignUuid('training_series_id')->nullable()->constrained('training_series')->nullOnDelete();
            $table->string('subject_type', 24)->default('athlete');
            $table->string('subject_key', 128);
            $table->string('exercise_label');
            $table->unsignedInteger('repetition_number')->nullable();
            $table->string('timer_state', 24)->default('running');
            $table->unsignedBigInteger('elapsed_ms')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('last_resumed_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->string('client_timer_id', 128)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['club_id', 'client_timer_id'], 'uq_pool_deck_timers_client_id');
            $table->index(['club_id', 'training_id', 'timer_state'], 'idx_pool_deck_timers_training_state');
            $table->index(['club_id', 'training_id', 'subject_key'], 'idx_pool_deck_timers_subject');
        });

        Schema::create('training_pool_deck_timer_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('timer_id')->constrained('training_pool_deck_timers')->cascadeOnDelete();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->string('event_type', 24);
            $table->unsignedBigInteger('elapsed_ms')->default(0);
            $table->timestamp('occurred_at');
            $table->string('client_event_id', 128)->nullable();
            $table->json('payload')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['club_id', 'client_event_id'], 'uq_pool_deck_timer_events_client_id');
            $table->index(['club_id', 'training_id', 'occurred_at'], 'idx_pool_deck_timer_events_training');
        });

        Schema::create('training_pool_deck_sync_conflicts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('training_id')->constrained('trainings')->cascadeOnDelete();
            $table->string('entity_type', 48);
            $table->uuid('entity_id')->nullable();
            $table->string('field', 80);
            $table->json('client_value')->nullable();
            $table->json('server_value')->nullable();
            $table->unsignedInteger('client_version')->nullable();
            $table->unsignedInteger('server_version')->nullable();
            $table->string('client_event_id', 128)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['club_id', 'training_id', 'resolved_at'], 'idx_pool_deck_sync_conflicts_training');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_pool_deck_sync_conflicts');
        Schema::dropIfExists('training_pool_deck_timer_events');
        Schema::dropIfExists('training_pool_deck_timers');

        Schema::table('training_metrics', function (Blueprint $table): void {
            $table->dropUnique('uq_training_metrics_client_event');
            $table->dropIndex('idx_training_metrics_pool_deck');
            $table->dropForeign(['training_series_id']);
            $table->dropForeign(['captured_by']);
            $table->dropColumn([
                'club_id',
                'training_series_id',
                'measurement_type',
                'total_distance_m',
                'repetition_mode',
                'repetition_number',
                'duration_ms',
                'splits_json',
                'source',
                'client_event_id',
                'client_recorded_at',
                'captured_by',
                'server_version',
            ]);
        });

        Schema::table('training_athletes', function (Blueprint $table): void {
            $table->dropForeign(['cais_last_modified_by']);
            $table->dropColumn([
                'cais_version',
                'cais_status_source',
                'cais_last_modified_at',
                'cais_last_modified_by',
            ]);
        });

        Schema::table('trainings', function (Blueprint $table): void {
            $table->dropForeign(['pool_deck_opened_by']);
            $table->dropForeign(['pool_deck_closed_by']);
            $table->dropColumn([
                'pool_deck_status',
                'pool_deck_version',
                'pool_deck_opened_at',
                'pool_deck_opened_by',
                'pool_deck_closed_at',
                'pool_deck_closed_by',
            ]);
        });
    }
};
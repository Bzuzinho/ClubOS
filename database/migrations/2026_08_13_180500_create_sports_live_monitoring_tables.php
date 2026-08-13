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
        Schema::create('sports_live_metric_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64);
            $table->string('codigo', 96);
            $table->string('nome', 120);
            $table->string('input_type', 32)->default('text');
            $table->string('unit', 32)->nullable();
            $table->json('options_json')->nullable();
            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['club_id', 'codigo'], 'sports_live_metric_defs_club_code_unique');
            $table->index(['club_id', 'ativo', 'ordem'], 'sports_live_metric_defs_runtime_idx');
        });

        Schema::create('sports_live_monitorings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64);
            $table->uuid('training_id');
            $table->uuid('training_series_id')->nullable();
            $table->string('type', 24);
            $table->string('state', 24)->default('active');
            $table->unsignedInteger('current_repetition')->default(1);
            $table->unsignedInteger('current_round')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();
            $table->foreign('training_series_id')->references('id')->on('training_series')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['club_id', 'training_id', 'state'], 'sports_live_monitorings_runtime_idx');
        });

        Schema::create('sports_live_monitoring_athletes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('monitoring_id');
            $table->uuid('training_athlete_id');
            $table->uuid('user_id');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->foreign('monitoring_id')->references('id')->on('sports_live_monitorings')->cascadeOnDelete();
            $table->foreign('training_athlete_id')->references('id')->on('training_athletes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['monitoring_id', 'user_id'], 'sports_live_monitoring_athlete_unique');
            $table->index(['training_athlete_id', 'active'], 'sports_live_monitoring_athlete_active_idx');
        });

        Schema::create('sports_live_measurements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('monitoring_id');
            $table->uuid('training_id');
            $table->uuid('training_series_id')->nullable();
            $table->unsignedInteger('repetition_number')->default(1);
            $table->unsignedInteger('round_number')->default(1);
            $table->string('state', 24)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->uuid('started_by')->nullable();
            $table->string('client_measurement_id', 120)->nullable();
            $table->timestamps();
            $table->foreign('monitoring_id')->references('id')->on('sports_live_monitorings')->cascadeOnDelete();
            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();
            $table->foreign('training_series_id')->references('id')->on('training_series')->nullOnDelete();
            $table->foreign('started_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('client_measurement_id', 'sports_live_measurements_client_unique');
            $table->index(['monitoring_id', 'state'], 'sports_live_measurements_monitoring_state_idx');
        });

        Schema::create('sports_live_measurement_athletes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('measurement_id');
            $table->uuid('monitoring_athlete_id');
            $table->uuid('training_athlete_id');
            $table->uuid('user_id');
            $table->string('state', 24)->default('active');
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();
            $table->foreign('measurement_id')->references('id')->on('sports_live_measurements')->cascadeOnDelete();
            $table->foreign('monitoring_athlete_id')->references('id')->on('sports_live_monitoring_athletes')->cascadeOnDelete();
            $table->foreign('training_athlete_id')->references('id')->on('training_athletes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['measurement_id', 'user_id'], 'sports_live_measurement_athlete_unique');
        });

        Schema::create('sports_live_measurement_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('measurement_id');
            $table->uuid('measurement_athlete_id')->nullable();
            $table->string('event_type', 24);
            $table->unsignedInteger('sequence')->default(0);
            $table->unsignedBigInteger('elapsed_ms')->default(0);
            $table->timestamp('occurred_at');
            $table->string('client_event_id', 160);
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();
            $table->foreign('measurement_id')->references('id')->on('sports_live_measurements')->cascadeOnDelete();
            $table->foreign('measurement_athlete_id')->references('id')->on('sports_live_measurement_athletes')->cascadeOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('client_event_id', 'sports_live_events_client_unique');
            $table->index(['measurement_id', 'measurement_athlete_id', 'sequence'], 'sports_live_events_sequence_idx');
        });

        Schema::create('sports_live_free_classifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('measurement_athlete_id');
            $table->unsignedInteger('total_distance_m');
            $table->unsignedInteger('segment_count');
            $table->decimal('segment_distance_m', 10, 2);
            $table->uuid('sports_stroke_id')->nullable();
            $table->string('stroke_label', 120);
            $table->timestamp('classified_at');
            $table->uuid('classified_by')->nullable();
            $table->timestamps();
            $table->foreign('measurement_athlete_id')->references('id')->on('sports_live_measurement_athletes')->cascadeOnDelete();
            $table->foreign('sports_stroke_id')->references('id')->on('sports_strokes')->nullOnDelete();
            $table->foreign('classified_by')->references('id')->on('users')->nullOnDelete();
            $table->unique('measurement_athlete_id', 'sports_live_free_classification_unique');
        });

        Schema::create('sports_live_metric_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64);
            $table->uuid('training_id');
            $table->uuid('training_series_id')->nullable();
            $table->uuid('training_athlete_id');
            $table->uuid('user_id');
            $table->uuid('metric_definition_id');
            $table->string('metric_code', 96);
            $table->string('metric_name', 120);
            $table->string('unit_snapshot', 32)->nullable();
            $table->string('value', 500);
            $table->decimal('value_number', 14, 4)->nullable();
            $table->text('note')->nullable();
            $table->uuid('live_measurement_id')->nullable();
            $table->timestamp('recorded_at');
            $table->uuid('recorded_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by')->nullable();
            $table->timestamps();
            $table->foreign('training_id')->references('id')->on('trainings')->cascadeOnDelete();
            $table->foreign('training_series_id')->references('id')->on('training_series')->nullOnDelete();
            $table->foreign('training_athlete_id')->references('id')->on('training_athletes')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('metric_definition_id')->references('id')->on('sports_live_metric_definitions')->restrictOnDelete();
            $table->foreign('live_measurement_id')->references('id')->on('sports_live_measurements')->nullOnDelete();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('voided_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['club_id', 'training_id', 'user_id', 'metric_definition_id', 'recorded_at'], 'sports_live_metric_history_idx');
            $table->index(['training_series_id', 'metric_definition_id'], 'sports_live_metric_series_idx');
        });

        $clubId = (string) config('sports.club_id', 'bscn');
        $now = now();
        $defaults = [
            ['heart_rate', 'Frequência cardíaca', 'number', 'bpm', null, 10],
            ['lactate', 'Lactato', 'number', 'mmol/L', null, 20],
            ['rpe', 'RPE', 'number', null, null, 30],
            ['strokes', 'Braçadas', 'number', null, null, 40],
            ['stroke_rate', 'Frequência de braçada', 'number', 'ciclos/min', null, 50],
            ['breaths', 'Respirações', 'number', null, null, 60],
            ['turn', 'Viragem', 'number', 's', null, 70],
            ['underwater', 'Subaquático', 'number', 's', null, 80],
            ['technique', 'Técnica', 'text', null, null, 90],
        ];
        foreach ($defaults as [$code, $name, $type, $unit, $options, $order]) {
            DB::table('sports_live_metric_definitions')->insert([
                'id' => (string) Str::uuid(), 'club_id' => $clubId, 'codigo' => $code, 'nome' => $name,
                'input_type' => $type, 'unit' => $unit,
                'options_json' => $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE),
                'ativo' => true, 'ordem' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_live_metric_records');
        Schema::dropIfExists('sports_live_free_classifications');
        Schema::dropIfExists('sports_live_measurement_events');
        Schema::dropIfExists('sports_live_measurement_athletes');
        Schema::dropIfExists('sports_live_measurements');
        Schema::dropIfExists('sports_live_monitoring_athletes');
        Schema::dropIfExists('sports_live_monitorings');
        Schema::dropIfExists('sports_live_metric_definitions');
    }
};
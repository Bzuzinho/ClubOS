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

        Schema::create('training_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('nome');
            $table->string('codigo', 80)->nullable();
            $table->text('descricao')->nullable();
            $table->string('modalidade', 80)->nullable();
            $table->string('estado', 30)->default('draft')->index();
            $table->foreignUuid('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['club_id', 'codigo'], 'uq_training_plans_club_code');
            $table->index(['club_id', 'estado'], 'idx_training_plans_club_state');
        });

        Schema::create('training_plan_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('training_plan_id')->constrained('training_plans')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('nome_snapshot');
            $table->string('tipo_treino', 100)->nullable();
            $table->text('descricao_treino')->nullable();
            $table->text('notas_gerais')->nullable();
            $table->unsignedInteger('volume_planeado_m')->nullable();
            $table->text('instrucao')->nullable();
            $table->text('motivo_revisao')->nullable();
            $table->json('metadados')->nullable();
            $table->foreignUuid('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('publicado_em')->nullable();
            $table->timestamps();

            $table->unique(['training_plan_id', 'version'], 'uq_training_plan_versions_plan_version');
            $table->index(['club_id', 'training_plan_id'], 'idx_training_plan_versions_club_plan');
        });

        Schema::create('training_plan_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('training_plan_version_id')->constrained('training_plan_versions')->cascadeOnDelete();
            $table->unsignedInteger('ordem');
            $table->string('bloco', 80)->nullable();
            $table->unsignedInteger('repeticoes')->nullable();
            $table->unsignedInteger('distancia_m')->nullable();
            $table->unsignedInteger('distancia_total_m')->nullable();
            $table->string('exercicio', 255)->nullable();
            $table->string('estilo', 50)->nullable();
            $table->string('zona_intensidade', 30)->nullable();
            $table->string('intervalo', 50)->nullable();
            $table->string('saida', 50)->nullable();
            $table->json('material')->nullable();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->unique(['training_plan_version_id', 'ordem'], 'uq_training_plan_series_version_order');
            $table->index(['club_id', 'training_plan_version_id'], 'idx_training_plan_series_club_version');
        });

        Schema::table('trainings', function (Blueprint $table) use ($clubId) {
            $table->string('club_id', 64)->default($clubId)->index();
            $table->foreignUuid('training_plan_version_id')->nullable()->constrained('training_plan_versions')->nullOnDelete();
            $table->foreignUuid('responsavel_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('session_status', 30)->default('draft')->index();
            $table->text('instrucao')->nullable();
            $table->timestamp('plan_applied_at')->nullable();
            $table->foreignUuid('plan_applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });

        Schema::table('training_series', function (Blueprint $table) {
            $table->foreignUuid('training_plan_version_id')->nullable()->constrained('training_plan_versions')->nullOnDelete();
            $table->foreignUuid('training_plan_series_id')->nullable()->constrained('training_plan_series')->nullOnDelete();
            $table->string('source', 30)->default('manual')->index();
            $table->string('bloco', 80)->nullable();
            $table->unsignedInteger('distancia_m')->nullable();
            $table->string('saida', 50)->nullable();
            $table->json('material')->nullable();
        });

        // Sessões já existentes eram operacionais antes da introdução do estado.
        // Mantêm-se publicadas para não alterar comportamento histórico/portal.
        DB::table('trainings')->update([
            'club_id' => $clubId,
            'session_status' => 'published',
            'published_at' => DB::raw('created_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('training_series', function (Blueprint $table) {
            $table->dropForeign(['training_plan_version_id']);
            $table->dropForeign(['training_plan_series_id']);
            $table->dropColumn([
                'training_plan_version_id',
                'training_plan_series_id',
                'source',
                'bloco',
                'distancia_m',
                'saida',
                'material',
            ]);
        });

        Schema::table('trainings', function (Blueprint $table) {
            $table->dropForeign(['training_plan_version_id']);
            $table->dropForeign(['responsavel_id']);
            $table->dropForeign(['plan_applied_by']);
            $table->dropColumn([
                'club_id',
                'training_plan_version_id',
                'responsavel_id',
                'session_status',
                'instrucao',
                'plan_applied_at',
                'plan_applied_by',
                'published_at',
                'completed_at',
            ]);
        });

        Schema::dropIfExists('training_plan_series');
        Schema::dropIfExists('training_plan_versions');
        Schema::dropIfExists('training_plans');
    }
};

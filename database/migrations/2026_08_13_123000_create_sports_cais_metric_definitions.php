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
        Schema::create('sports_cais_metric_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64);
            $table->string('codigo', 96);
            $table->string('nome', 120);
            $table->string('input_type', 32)->default('text');
            $table->string('unit', 32)->nullable();
            $table->json('options_json')->nullable();
            $table->boolean('quick_action')->default(false);
            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'codigo'], 'sports_cais_metric_defs_club_code_unique');
            $table->index(['club_id', 'ativo', 'ordem'], 'sports_cais_metric_defs_runtime_idx');
        });

        $clubId = (string) config('sports.club_id', 'bscn');
        $now = now();
        $defaults = [
            ['behavior', 'Comportamento', 'choice', null, ['Adequado', 'Atenção', 'Inadequado'], true, 10],
            ['material', 'Material', 'choice', null, ['Completo', 'Falta: palas', 'Falta: prancha', 'Falta: pull buoy', 'Outro'], true, 20],
            ['heart_rate', 'Frequência cardíaca', 'number', 'bpm', null, false, 30],
            ['rpe', 'RPE', 'number', null, null, false, 40],
        ];

        foreach ($defaults as [$code, $name, $type, $unit, $options, $quick, $order]) {
            DB::table('sports_cais_metric_definitions')->insert([
                'id' => (string) Str::uuid(),
                'club_id' => $clubId,
                'codigo' => $code,
                'nome' => $name,
                'input_type' => $type,
                'unit' => $unit,
                'options_json' => $options === null ? null : json_encode($options, JSON_UNESCAPED_UNICODE),
                'quick_action' => $quick,
                'ativo' => true,
                'ordem' => $order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('training_athletes')) {
            DB::table('training_athletes')
                ->where('estado', 'ausente')
                ->where('presente', false)
                ->whereNull('volume_real_m')
                ->whereNull('rpe')
                ->whereNull('observacoes_tecnicas')
                ->whereColumn('created_at', 'updated_at')
                ->whereExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from('trainings')
                        ->whereColumn('trainings.id', 'training_athletes.treino_id')
                        ->whereDate('trainings.data', '>=', now()->toDateString())
                        ->whereNotIn('trainings.session_status', ['cancelled', 'completed']);
                })
                ->update([
                    'presente' => true,
                    'estado' => 'presente',
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_cais_metric_definitions');
    }
};

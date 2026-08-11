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
        $this->addCommonColumns('athlete_status_configs');
        $this->addCommonColumns('training_type_configs');
        $this->addCommonColumns('training_zone_configs');
        $this->addCommonColumns('absence_reason_configs');
        $this->addCommonColumns('pool_type_configs');
        $this->addCommonColumns('prova_tipos');

        Schema::table('athlete_status_configs', function (Blueprint $table): void {
            $table->boolean('counts_as_present')->default(false);
            $table->boolean('requires_reason')->default(false);
            $table->boolean('allows_training')->default(true);
            $table->boolean('allows_competition')->default(true);
        });

        Schema::table('training_type_configs', function (Blueprint $table): void {
            $table->boolean('is_recovery')->default(false);
            $table->boolean('is_high_intensity')->default(false);
        });

        Schema::table('training_zone_configs', function (Blueprint $table): void {
            $table->boolean('is_recovery')->default(false);
            $table->boolean('is_high_intensity')->default(false);
        });

        Schema::table('absence_reason_configs', function (Blueprint $table): void {
            $table->boolean('health_related')->default(false);
        });

        Schema::table('pool_type_configs', function (Blueprint $table): void {
            $table->boolean('is_open_water')->default(false);
        });

        Schema::table('prova_tipos', function (Blueprint $table): void {
            $table->string('codigo', 96)->nullable()->index();
            $table->unsignedInteger('ordem')->default(0);
        });

        Schema::create('sports_limitation_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('codigo', 64);
            $table->string('nome', 120);
            $table->text('descricao')->nullable();
            $table->text('instrucao_padrao')->nullable();
            $table->boolean('allows_training')->default(true);
            $table->boolean('allows_competition')->default(true);
            $table->boolean('requires_end_date')->default(false);
            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['club_id', 'codigo'], 'uq_sports_limitation_types_club_code');
            $table->index(['club_id', 'ativo', 'ordem'], 'idx_sports_limitation_types_catalog');
        });

        $this->backfillSemantics();
        $this->backfillRaceCodes();
        $this->ensureSportsConfigurationPermissionNode();
    }

    public function down(): void
    {
        if (Schema::hasTable('permission_nodes')) {
            DB::table('permission_nodes')->where('key', 'desportivo.configuracao')->delete();
        }

        Schema::dropIfExists('sports_limitation_types');

        Schema::table('prova_tipos', function (Blueprint $table): void {
            $table->dropColumn(['codigo', 'ordem']);
        });
        Schema::table('pool_type_configs', fn (Blueprint $table) => $table->dropColumn('is_open_water'));
        Schema::table('absence_reason_configs', fn (Blueprint $table) => $table->dropColumn('health_related'));
        Schema::table('training_zone_configs', fn (Blueprint $table) => $table->dropColumn(['is_recovery', 'is_high_intensity']));
        Schema::table('training_type_configs', fn (Blueprint $table) => $table->dropColumn(['is_recovery', 'is_high_intensity']));
        Schema::table('athlete_status_configs', fn (Blueprint $table) => $table->dropColumn(['counts_as_present', 'requires_reason', 'allows_training', 'allows_competition']));

        foreach (['athlete_status_configs', 'training_type_configs', 'training_zone_configs', 'absence_reason_configs', 'pool_type_configs', 'prova_tipos'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn(['club_id', 'archived_at', 'created_by', 'updated_by']);
            });
        }
    }

    private function addCommonColumns(string $tableName): void
    {
        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('club_id', 64)->default('bscn')->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
        });
    }

    private function backfillSemantics(): void
    {
        DB::table('athlete_status_configs')->whereIn('codigo', ['presente', 'limitado'])->update(['counts_as_present' => true]);
        DB::table('athlete_status_configs')->whereIn('codigo', ['justificado', 'lesionado', 'doente'])->update(['requires_reason' => true]);
        DB::table('athlete_status_configs')->whereIn('codigo', ['lesionado', 'doente'])->update(['allows_competition' => false]);

        DB::table('training_type_configs')->whereIn('codigo', ['regeneracao', 'tapering'])->update(['is_recovery' => true]);
        DB::table('training_type_configs')->whereIn('codigo', ['velocidade', 'forca'])->update(['is_high_intensity' => true]);

        DB::table('training_zone_configs')->whereNotNull('percentagem_max')->where('percentagem_max', '<=', 70)->update(['is_recovery' => true]);
        DB::table('training_zone_configs')->whereNotNull('percentagem_min')->where('percentagem_min', '>=', 80)->update(['is_high_intensity' => true]);

        DB::table('absence_reason_configs')->whereIn('codigo', ['doenca', 'lesao'])->update(['health_related' => true]);
        DB::table('pool_type_configs')->whereNull('comprimento_m')->update(['is_open_water' => true]);
    }

    private function backfillRaceCodes(): void
    {
        DB::table('prova_tipos')->orderBy('id')->get()->each(function ($row): void {
            $base = Str::slug(implode('-', array_filter([
                $row->modalidade ?? null,
                $row->nome ?? null,
                $row->distancia ?? null,
                $row->unidade ?? null,
            ]))) ?: 'prova';

            $candidate = $base;
            $suffix = 2;
            while (DB::table('prova_tipos')->where('club_id', $row->club_id ?? 'bscn')->where('codigo', $candidate)->where('id', '<>', $row->id)->exists()) {
                $candidate = $base.'-'.$suffix++;
            }

            DB::table('prova_tipos')->where('id', $row->id)->update(['codigo' => $candidate]);
        });
    }

    private function ensureSportsConfigurationPermissionNode(): void
    {
        if (!Schema::hasTable('permission_nodes')) {
            return;
        }

        $parentId = DB::table('permission_nodes')->where('key', 'desportivo')->value('id');
        if (!$parentId || DB::table('permission_nodes')->where('key', 'desportivo.configuracao')->exists()) {
            return;
        }

        DB::table('permission_nodes')->insert([
            'id' => (string) Str::uuid(),
            'key' => 'desportivo.configuracao',
            'label' => 'Configuração Desportiva',
            'parent_id' => $parentId,
            'module_key' => 'desportivo',
            'node_type' => 'submodule',
            'sort_order' => 90,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

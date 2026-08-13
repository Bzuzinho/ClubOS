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
        $clubId = trim((string) config('sports.club_id', 'bscn')) ?: 'bscn';

        Schema::create('sports_strokes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('code', 40);
            $table->string('name', 100);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['club_id', 'code'], 'uq_sports_strokes_club_code');
        });

        Schema::create('sports_training_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('code', 60);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->unique(['club_id', 'code'], 'uq_sports_training_materials_club_code');
        });

        Schema::table('training_plans', function (Blueprint $table) {
            $table->foreignUuid('sports_modality_id')->nullable()->after('modalidade')->constrained('sports_modalities')->nullOnDelete();
            $table->json('tags')->nullable()->after('sports_modality_id');
        });

        Schema::create('training_plan_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->foreignUuid('training_plan_version_id')->constrained('training_plan_versions')->cascadeOnDelete();
            $table->unsignedInteger('sort_order');
            $table->string('name', 100);
            $table->unsignedInteger('rounds')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['training_plan_version_id', 'sort_order'], 'uq_training_plan_blocks_version_order');
        });

        Schema::table('training_plan_series', function (Blueprint $table) {
            $table->foreignUuid('training_plan_block_id')->nullable()->after('training_plan_version_id')->constrained('training_plan_blocks')->nullOnDelete();
            $table->foreignUuid('training_zone_config_id')->nullable()->after('zona_intensidade')->constrained('training_zone_configs')->nullOnDelete();
            $table->foreignUuid('sports_stroke_id')->nullable()->after('estilo')->constrained('sports_strokes')->nullOnDelete();
            $table->string('timing_mode', 30)->default('none')->after('saida');
        });

        Schema::create('training_plan_series_materials', function (Blueprint $table) {
            $table->foreignUuid('training_plan_series_id')->constrained('training_plan_series')->cascadeOnDelete();
            $table->foreignUuid('sports_training_material_id')->constrained('sports_training_materials')->cascadeOnDelete();
            $table->unsignedInteger('quantity')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->primary(['training_plan_series_id', 'sports_training_material_id'], 'pk_training_plan_series_materials');
        });

        Schema::table('training_series', function (Blueprint $table) {
            $table->foreignUuid('training_zone_config_id')->nullable()->constrained('training_zone_configs')->nullOnDelete();
            $table->foreignUuid('sports_stroke_id')->nullable()->constrained('sports_strokes')->nullOnDelete();
            $table->string('block_name', 100)->nullable();
            $table->unsignedInteger('block_order')->nullable();
            $table->unsignedInteger('block_rounds')->default(1);
            $table->string('timing_mode', 30)->default('none');
        });

        $now = now();
        $strokeRows = [
            ['code' => 'LIVRE', 'name' => 'Livre'],
            ['code' => 'COSTAS', 'name' => 'Costas'],
            ['code' => 'BRUCOS', 'name' => 'Bruços'],
            ['code' => 'MARIPOSA', 'name' => 'Mariposa'],
            ['code' => 'ESTILOS', 'name' => 'Estilos'],
        ];
        foreach ($strokeRows as $index => $row) {
            DB::table('sports_strokes')->insert([
                'id' => (string) Str::uuid(), 'club_id' => $clubId, 'code' => $row['code'], 'name' => $row['name'],
                'active' => true, 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $materialRows = [
            ['code' => 'PRANCHA', 'name' => 'Prancha'], ['code' => 'PULL_BUOY', 'name' => 'Pull buoy'],
            ['code' => 'PALAS', 'name' => 'Palas'], ['code' => 'BARBATANAS', 'name' => 'Barbatanas'],
            ['code' => 'SNORKEL', 'name' => 'Snorkel frontal'], ['code' => 'ELASTICO', 'name' => 'Elástico'],
        ];
        foreach ($materialRows as $index => $row) {
            DB::table('sports_training_materials')->insert([
                'id' => (string) Str::uuid(), 'club_id' => $clubId, 'code' => $row['code'], 'name' => $row['name'],
                'active' => true, 'sort_order' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('training_series', function (Blueprint $table) {
            $table->dropForeign(['training_zone_config_id']);
            $table->dropForeign(['sports_stroke_id']);
            $table->dropColumn(['training_zone_config_id', 'sports_stroke_id', 'block_name', 'block_order', 'block_rounds', 'timing_mode']);
        });
        Schema::dropIfExists('training_plan_series_materials');
        Schema::table('training_plan_series', function (Blueprint $table) {
            $table->dropForeign(['training_plan_block_id']);
            $table->dropForeign(['training_zone_config_id']);
            $table->dropForeign(['sports_stroke_id']);
            $table->dropColumn(['training_plan_block_id', 'training_zone_config_id', 'sports_stroke_id', 'timing_mode']);
        });
        Schema::dropIfExists('training_plan_blocks');
        Schema::table('training_plans', function (Blueprint $table) {
            $table->dropForeign(['sports_modality_id']);
            $table->dropColumn(['sports_modality_id', 'tags']);
        });
        Schema::dropIfExists('sports_training_materials');
        Schema::dropIfExists('sports_strokes');
    }
};

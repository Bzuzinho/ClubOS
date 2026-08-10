<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('athlete_sports_data', function (Blueprint $table): void {
            $table->uuid('escalao_calculado_id')->nullable()->after('escalao_id');
            $table->boolean('escalao_manual_override')->default(false)->after('escalao_calculado_id');

            $table->foreign('escalao_calculado_id')
                ->references('id')
                ->on('age_groups')
                ->nullOnDelete();
        });

        // Preserva a intenção dos dados existentes: um escalão já gravado antes
        // desta migração é tratado como escolha explícita até ser colocado em modo automático.
        DB::statement(
            'UPDATE athlete_sports_data '
            .'SET escalao_calculado_id = escalao_id, escalao_manual_override = TRUE '
            .'WHERE escalao_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::table('athlete_sports_data', function (Blueprint $table): void {
            $table->dropForeign(['escalao_calculado_id']);
            $table->dropColumn(['escalao_calculado_id', 'escalao_manual_override']);
        });
    }
};

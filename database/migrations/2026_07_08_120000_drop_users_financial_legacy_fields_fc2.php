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
        if (!Schema::hasTable('users')) {
            return;
        }

        if (Schema::hasColumn('users', 'tipo_mensalidade')) {
            DB::statement('DROP INDEX IF EXISTS users_tipo_mensalidade_index');
        }

        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'centro_custo')) {
                $table->dropColumn('centro_custo');
            }

            if (Schema::hasColumn('users', 'tipo_mensalidade')) {
                $table->dropColumn('tipo_mensalidade');
            }

            if (Schema::hasColumn('users', 'conta_corrente')) {
                $table->dropColumn('conta_corrente');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // FC2 rollback recreates schema only; removed values are not restored automatically.
            if (!Schema::hasColumn('users', 'centro_custo')) {
                $table->json('centro_custo')->nullable()->after('educandos');
            }

            if (!Schema::hasColumn('users', 'tipo_mensalidade')) {
                $table->string('tipo_mensalidade')->nullable()->after('educandos');
            }

            if (!Schema::hasColumn('users', 'conta_corrente')) {
                $table->decimal('conta_corrente', 10, 2)->default(0)->after('educandos');
            }

            if (Schema::hasColumn('users', 'tipo_mensalidade')) {
                $table->index('tipo_mensalidade');
            }
        });
    }
};

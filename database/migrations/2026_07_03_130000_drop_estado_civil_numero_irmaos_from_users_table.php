<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'numero_irmaos')) {
                $table->dropColumn('numero_irmaos');
            }

            if (Schema::hasColumn('users', 'estado_civil')) {
                $table->dropColumn('estado_civil');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (!Schema::hasColumn('users', 'estado_civil')) {
                $table->string('estado_civil')->nullable()->after('email_secundario');
            }

            if (!Schema::hasColumn('users', 'numero_irmaos')) {
                $table->integer('numero_irmaos')->nullable()->after('estado_civil');
            }
        });
    }
};

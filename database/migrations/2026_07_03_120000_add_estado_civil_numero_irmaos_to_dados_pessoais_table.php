<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('dados_pessoais', function (Blueprint $table): void {
            if (!Schema::hasColumn('dados_pessoais', 'estado_civil')) {
                $table->string('estado_civil')->nullable()->after('email_secundario');
            }

            if (!Schema::hasColumn('dados_pessoais', 'numero_irmaos')) {
                $table->integer('numero_irmaos')->nullable()->after('estado_civil');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dados_pessoais', function (Blueprint $table): void {
            if (Schema::hasColumn('dados_pessoais', 'numero_irmaos')) {
                $table->dropColumn('numero_irmaos');
            }

            if (Schema::hasColumn('dados_pessoais', 'estado_civil')) {
                $table->dropColumn('estado_civil');
            }
        });
    }
};
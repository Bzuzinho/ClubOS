<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_preferences')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('automacoes_financeiro')->default(false)->change();
            $table->boolean('automacoes_faturas_financeiras')->default(false)->change();
            $table->boolean('automacoes_movimentos_financeiros')->default(false)->change();
            $table->boolean('automacoes_eventos')->default(false)->change();
            $table->boolean('automacoes_logistica')->default(false)->change();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('notification_preferences')) {
            return;
        }

        Schema::table('notification_preferences', function (Blueprint $table) {
            $table->boolean('automacoes_financeiro')->default(true)->change();
            $table->boolean('automacoes_faturas_financeiras')->default(true)->change();
            $table->boolean('automacoes_movimentos_financeiros')->default(true)->change();
            $table->boolean('automacoes_eventos')->default(true)->change();
            $table->boolean('automacoes_logistica')->default(true)->change();
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('mapa_conciliacao', function (Blueprint $table) {
            if (!Schema::hasColumn('mapa_conciliacao', 'bank_reconciliation_suggestion_id')) {
                $table->foreignUuid('bank_reconciliation_suggestion_id')
                    ->nullable()
                    ->constrained('bank_reconciliation_suggestions')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('mapa_conciliacao', 'score')) {
                $table->unsignedTinyInteger('score')->nullable();
            }

            if (!Schema::hasColumn('mapa_conciliacao', 'metadata')) {
                $table->json('metadata')->nullable();
            }

            $table->index('bank_reconciliation_suggestion_id');
            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::table('mapa_conciliacao', function (Blueprint $table) {
            if (Schema::hasColumn('mapa_conciliacao', 'bank_reconciliation_suggestion_id')) {
                $table->dropIndex(['bank_reconciliation_suggestion_id']);
                $table->dropConstrainedForeignId('bank_reconciliation_suggestion_id');
            }

            if (Schema::hasColumn('mapa_conciliacao', 'score')) {
                $table->dropIndex(['score']);
                $table->dropColumn('score');
            }

            if (Schema::hasColumn('mapa_conciliacao', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
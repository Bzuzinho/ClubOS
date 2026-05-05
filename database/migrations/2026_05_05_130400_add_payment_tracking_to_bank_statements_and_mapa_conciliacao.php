<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            if (!Schema::hasColumn('bank_statements', 'valor_conciliado')) {
                $table->decimal('valor_conciliado', 10, 2)->default(0)->after('conciliado');
            }
            if (!Schema::hasColumn('bank_statements', 'valor_por_conciliar')) {
                $table->decimal('valor_por_conciliar', 10, 2)->nullable()->after('valor_conciliado');
            }
            if (!Schema::hasColumn('bank_statements', 'conciliacao_status')) {
                $table->string('conciliacao_status', 20)->default('unreconciled')->after('valor_por_conciliar');
            }

            $table->index('conciliacao_status');
        });

        Schema::table('mapa_conciliacao', function (Blueprint $table) {
            if (!Schema::hasColumn('mapa_conciliacao', 'payment_id')) {
                $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            }
            if (!Schema::hasColumn('mapa_conciliacao', 'payment_allocation_id')) {
                $table->foreignUuid('payment_allocation_id')->nullable()->constrained('payment_allocations')->nullOnDelete();
            }

            $table->index('payment_id');
            $table->index('payment_allocation_id');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table) {
            if (Schema::hasColumn('bank_statements', 'conciliacao_status')) {
                $table->dropIndex(['conciliacao_status']);
            }

            $columns = [];
            if (Schema::hasColumn('bank_statements', 'valor_conciliado')) {
                $columns[] = 'valor_conciliado';
            }
            if (Schema::hasColumn('bank_statements', 'valor_por_conciliar')) {
                $columns[] = 'valor_por_conciliar';
            }
            if (Schema::hasColumn('bank_statements', 'conciliacao_status')) {
                $columns[] = 'conciliacao_status';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('mapa_conciliacao', function (Blueprint $table) {
            if (Schema::hasColumn('mapa_conciliacao', 'payment_id')) {
                $table->dropIndex(['payment_id']);
                $table->dropConstrainedForeignId('payment_id');
            }
            if (Schema::hasColumn('mapa_conciliacao', 'payment_allocation_id')) {
                $table->dropIndex(['payment_allocation_id']);
                $table->dropConstrainedForeignId('payment_allocation_id');
            }
        });
    }
};
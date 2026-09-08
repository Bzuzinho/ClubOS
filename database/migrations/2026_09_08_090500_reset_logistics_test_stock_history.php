<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Limpeza operacional única pedida antes da entrada em utilização real da Logística.
     *
     * O histórico removido é exclusivamente o ledger de movimentos de stock. Catálogos,
     * fornecedores, compras e movimentos financeiros não são apagados por esta migração.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            if (Schema::hasTable('stock_movements')) {
                DB::table('stock_movements')->delete();
            }

            if (Schema::hasTable('product_variants')) {
                DB::table('product_variants')->update([
                    'stock' => 0,
                    'stock_reservado' => 0,
                ]);
            }

            if (Schema::hasTable('products')) {
                DB::table('products')->update([
                    'stock' => 0,
                    'stock_reservado' => 0,
                ]);
            }
        });
    }

    /**
     * Os movimentos removidos eram dados de teste e não devem ser reconstruídos num rollback.
     */
    public function down(): void
    {
        // Irreversível por decisão operacional: não recriar histórico de testes.
    }
};

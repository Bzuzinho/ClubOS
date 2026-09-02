<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS invoices_store_order_origin_unique
            ON invoices (origem_tipo, origem_id)
            WHERE origem_tipo = 'store_order' AND origem_id IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS invoices_store_order_origin_unique');
    }
};

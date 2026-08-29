<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->uuid('product_variant_id')->nullable()->after('article_id');
            $table->foreign('product_variant_id')->references('id')->on('product_variants')->restrictOnDelete();
            $table->index(['product_variant_id', 'created_at'], 'stock_movements_variant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign(['product_variant_id']);
            $table->dropIndex('stock_movements_variant_created_idx');
            $table->dropColumn('product_variant_id');
        });
    }
};

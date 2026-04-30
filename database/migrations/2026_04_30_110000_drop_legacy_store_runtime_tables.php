<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_cart_items');
        Schema::dropIfExists('store_orders');
    }

    public function down(): void
    {
        Schema::create('store_orders', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('target_user_id')->nullable();
            $table->string('status', 40)->default('pending_payment');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('financial_invoice_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('target_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('financial_invoice_id')->references('id')->on('invoices')->onDelete('set null');

            $table->index('user_id');
            $table->index('target_user_id');
            $table->index('status');
            $table->index('created_at');
        });

        Schema::create('store_order_items', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('store_order_id');
            $table->uuid('article_id')->nullable();
            $table->string('article_code_snapshot');
            $table->string('article_name_snapshot');
            $table->string('variant_snapshot')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->foreign('store_order_id')->references('id')->on('store_orders')->onDelete('cascade');
            $table->foreign('article_id')->references('id')->on('products')->onDelete('set null');

            $table->index('store_order_id');
            $table->index('article_id');
        });

        Schema::create('store_cart_items', function ($table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('target_user_id')->nullable();
            $table->uuid('article_id');
            $table->string('variant')->nullable();
            $table->integer('quantity')->default(1);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('target_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('article_id')->references('id')->on('products')->onDelete('cascade');

            $table->index('user_id');
            $table->index('target_user_id');
            $table->index('article_id');
            $table->unique(['user_id', 'target_user_id', 'article_id', 'variant'], 'store_cart_unique_item');
        });
    }
};
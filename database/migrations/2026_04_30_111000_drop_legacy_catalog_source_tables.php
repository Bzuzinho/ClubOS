<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::dropIfExists('loja_produto_variantes');
        Schema::dropIfExists('loja_produtos');
    }

    public function down(): void
    {
        Schema::create('loja_produtos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('categoria_id')->nullable();
            $table->string('codigo', 100)->nullable()->unique();
            $table->string('nome');
            $table->string('slug')->unique();
            $table->text('descricao')->nullable();
            $table->decimal('preco', 10, 2);
            $table->string('imagem_principal_path')->nullable();
            $table->boolean('ativo')->default(true);
            $table->boolean('destaque')->default(false);
            $table->boolean('gere_stock')->default(true);
            $table->integer('stock_atual')->default(0);
            $table->integer('stock_minimo')->nullable();
            $table->integer('ordem')->nullable();
            $table->timestamps();

            $table->foreign('categoria_id')->references('id')->on('item_categories')->nullOnDelete();
            $table->index(['ativo', 'destaque'], 'loja_produtos_ativo_destaque_idx');
            $table->index(['categoria_id', 'ativo'], 'loja_produtos_categoria_ativo_idx');
            $table->index('ordem');
        });

        Schema::create('loja_produto_variantes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('loja_produto_id');
            $table->string('nome')->nullable();
            $table->string('tamanho', 80)->nullable();
            $table->string('cor', 80)->nullable();
            $table->string('sku', 120)->nullable()->unique();
            $table->decimal('preco_extra', 10, 2)->default(0);
            $table->integer('stock_atual')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();

            $table->foreign('loja_produto_id')->references('id')->on('loja_produtos')->cascadeOnDelete();
            $table->index(['loja_produto_id', 'ativo'], 'loja_produto_variantes_produto_ativo_idx');
        });
    }
};
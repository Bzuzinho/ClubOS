<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('loja_encomenda_devolucoes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('loja_encomenda_id')->unique()->constrained('loja_encomendas')->restrictOnDelete();
            $table->foreignUuid('fatura_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->foreignUuid('fiscal_document_request_id')->nullable()->constrained('fiscal_document_requests')->restrictOnDelete();
            $table->string('estado', 40)->default('solicitada');
            $table->text('motivo');
            $table->foreignUuid('solicitada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('solicitada_em');
            $table->foreignUuid('reversao_financeira_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversao_financeira_em')->nullable();
            $table->foreignUuid('stock_reposto_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('stock_reposto_em')->nullable();
            $table->foreignUuid('concluida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('concluida_em')->nullable();
            $table->timestamps();

            $table->index(['estado', 'created_at'], 'loja_devolucoes_estado_created_idx');
            $table->index('fatura_id');
        });

        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignUuid('payment_allocation_id')->unique()->constrained('payment_allocations')->restrictOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('source_type', 50);
            $table->uuid('source_id');
            $table->text('reason');
            $table->string('reference')->nullable();
            $table->foreignUuid('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id'], 'payment_reversals_source_idx');
            $table->index(['payment_id', 'reversed_at'], 'payment_reversals_payment_at_idx');
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
        Schema::dropIfExists('loja_encomenda_devolucoes');
    }
};

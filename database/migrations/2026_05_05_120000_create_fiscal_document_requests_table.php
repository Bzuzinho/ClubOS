<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('fiscal_document_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('bank_statement_id')->nullable()->constrained('bank_statements')->nullOnDelete();
            $table->foreignUuid('mapa_conciliacao_id')->nullable()->constrained('mapa_conciliacao')->nullOnDelete();
            $table->foreignUuid('financial_entry_id')->nullable()->constrained('financial_entries')->nullOnDelete();
            $table->string('provider', 50)->default('wintouch');
            $table->string('document_type', 50)->default('receipt');
            $table->string('status', 50)->default('pending');
            $table->string('priority', 20)->default('normal');
            $table->decimal('amount', 10, 2)->nullable();
            $table->date('paid_at')->nullable();
            $table->date('due_at')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_tax_number', 50)->nullable();
            $table->string('customer_email')->nullable();
            $table->text('customer_address')->nullable();
            $table->text('description')->nullable();
            $table->string('internal_reference')->nullable();
            $table->foreignUuid('cost_center_id')->nullable()->constrained('cost_centers')->nullOnDelete();
            $table->string('external_document_number', 100)->nullable();
            $table->string('external_document_id')->nullable();
            $table->text('external_document_url')->nullable();
            $table->string('external_series', 100)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('provider');
            $table->index('document_type');
            $table->index('due_at');
            $table->index('issued_at');
            $table->index('user_id');
            $table->index('invoice_id');
            $table->index('bank_statement_id');
            $table->index(['invoice_id', 'provider', 'document_type', 'status'], 'fdr_invoice_provider_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_requests');
    }
};
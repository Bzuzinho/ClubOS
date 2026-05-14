<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('receipt_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source_type', 30);
            $table->string('source_name')->nullable();
            $table->string('source_path')->nullable();
            $table->string('status', 30)->default('pending_review');
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('processed_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('receipt_import_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('batch_id')->constrained('receipt_import_batches')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('bank_statement_id')->nullable()->constrained('bank_statements')->nullOnDelete();
            $table->uuid('duplicate_of_item_id')->nullable();
            $table->string('status', 30)->default('pending_review');
            $table->decimal('confidence_score', 5, 2)->default(0);
            $table->string('file_name');
            $table->string('storage_path');
            $table->string('file_hash', 64);
            $table->string('numero_recibo')->nullable();
            $table->date('recibo_emitido_em')->nullable();
            $table->decimal('valor', 10, 2)->nullable();
            $table->string('extracted_name')->nullable();
            $table->string('extracted_nif', 32)->nullable();
            $table->string('extracted_member_number', 64)->nullable();
            $table->string('extracted_email')->nullable();
            $table->string('extracted_period_label')->nullable();
            $table->date('extracted_period_start')->nullable();
            $table->date('extracted_period_end')->nullable();
            $table->longText('extracted_text')->nullable();
            $table->json('extraction_payload')->nullable();
            $table->json('match_candidates')->nullable();
            $table->json('metadata')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index('file_hash');
            $table->index(['batch_id', 'status']);
            $table->index(['user_id', 'invoice_id']);
            $table->index('duplicate_of_item_id');
            $table->index('numero_recibo');
        });

        Schema::table('receipt_import_items', function (Blueprint $table) {
            $table->foreign('duplicate_of_item_id')
                ->references('id')
                ->on('receipt_import_items')
                ->nullOnDelete();
        });

        Schema::create('bank_transaction_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignUuid('payment_allocation_id')->nullable()->constrained('payment_allocations')->nullOnDelete();
            $table->foreignUuid('receipt_import_item_id')->nullable()->constrained('receipt_import_items')->nullOnDelete();
            $table->foreignUuid('mapa_conciliacao_id')->nullable()->constrained('mapa_conciliacao')->nullOnDelete();
            $table->decimal('valor_alocado', 10, 2);
            $table->string('status', 30)->default('confirmed');
            $table->string('origem', 50)->default('importacao_recibos');
            $table->json('metadata')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['bank_statement_id', 'status']);
            $table->index(['invoice_id', 'status']);
            $table->index(['user_id', 'origem']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'numero_recibo')) {
                $table->string('numero_recibo')->nullable()->after('data_pagamento');
            }
            if (!Schema::hasColumn('invoices', 'recibo_emitido_em')) {
                $table->date('recibo_emitido_em')->nullable()->after('numero_recibo');
            }
            if (!Schema::hasColumn('invoices', 'recibo_pdf_path')) {
                $table->string('recibo_pdf_path')->nullable()->after('recibo_emitido_em');
            }
            if (!Schema::hasColumn('invoices', 'receipt_import_item_id')) {
                $table->foreignUuid('receipt_import_item_id')->nullable()->after('recibo_pdf_path')->constrained('receipt_import_items')->nullOnDelete();
            }
        });

        if (Schema::hasTable('bank_reconciliation_aliases')) {
            Schema::table('bank_reconciliation_aliases', function (Blueprint $table) {
                if (!Schema::hasColumn('bank_reconciliation_aliases', 'raw_description')) {
                    $table->string('raw_description')->nullable()->after('value');
                }
                if (!Schema::hasColumn('bank_reconciliation_aliases', 'extracted_after_de')) {
                    $table->string('extracted_after_de')->nullable()->after('raw_description');
                }
                if (!Schema::hasColumn('bank_reconciliation_aliases', 'normalized_alias')) {
                    $table->string('normalized_alias')->nullable()->after('normalized_value');
                    $table->index('normalized_alias');
                }
                if (!Schema::hasColumn('bank_reconciliation_aliases', 'usage_count')) {
                    $table->unsignedInteger('usage_count')->default(0)->after('match_count');
                }
                if (!Schema::hasColumn('bank_reconciliation_aliases', 'last_used_at')) {
                    $table->timestamp('last_used_at')->nullable()->after('last_matched_at');
                }
                if (!Schema::hasColumn('bank_reconciliation_aliases', 'confidence_score')) {
                    $table->decimal('confidence_score', 5, 2)->default(50)->after('confidence');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_reconciliation_aliases')) {
            Schema::table('bank_reconciliation_aliases', function (Blueprint $table) {
                $columns = [];

                if (Schema::hasColumn('bank_reconciliation_aliases', 'raw_description')) {
                    $columns[] = 'raw_description';
                }
                if (Schema::hasColumn('bank_reconciliation_aliases', 'extracted_after_de')) {
                    $columns[] = 'extracted_after_de';
                }
                if (Schema::hasColumn('bank_reconciliation_aliases', 'normalized_alias')) {
                    $table->dropIndex(['normalized_alias']);
                    $columns[] = 'normalized_alias';
                }
                if (Schema::hasColumn('bank_reconciliation_aliases', 'usage_count')) {
                    $columns[] = 'usage_count';
                }
                if (Schema::hasColumn('bank_reconciliation_aliases', 'last_used_at')) {
                    $columns[] = 'last_used_at';
                }
                if (Schema::hasColumn('bank_reconciliation_aliases', 'confidence_score')) {
                    $columns[] = 'confidence_score';
                }

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'receipt_import_item_id')) {
                $table->dropConstrainedForeignId('receipt_import_item_id');
            }

            $columns = [];
            if (Schema::hasColumn('invoices', 'recibo_emitido_em')) {
                $columns[] = 'recibo_emitido_em';
            }
            if (Schema::hasColumn('invoices', 'recibo_pdf_path')) {
                $columns[] = 'recibo_pdf_path';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::dropIfExists('bank_transaction_allocations');
        Schema::dropIfExists('receipt_import_items');
        Schema::dropIfExists('receipt_import_batches');
    }
};
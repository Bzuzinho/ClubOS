<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            if (!Schema::hasColumn('movements', 'categoria')) {
                $table->string('categoria')->nullable()->after('classificacao');
            }

            if (!Schema::hasColumn('movements', 'estado_conciliacao')) {
                $table->string('estado_conciliacao', 30)->default('nao_conciliado')->after('estado_pagamento');
                $table->index('estado_conciliacao');
            }

            if (!Schema::hasColumn('movements', 'estado_documental')) {
                $table->string('estado_documental', 40)->default('sem_documentos')->after('estado_conciliacao');
                $table->index('estado_documental');
            }

            if (!Schema::hasColumn('movements', 'document_control_status')) {
                $table->string('document_control_status', 40)->nullable()->after('estado_documental');
            }
        });

        Schema::create('movement_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('movement_id');
            $table->uuid('supplier_id')->nullable();
            $table->string('document_type', 40);
            $table->string('source_type', 30)->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('original_filename')->nullable();
            $table->string('stored_path')->nullable();
            $table->string('mime_type')->nullable();
            $table->string('sha256_hash')->nullable();
            $table->string('document_number')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->decimal('vat_amount', 12, 2)->nullable();
            $table->string('status', 30)->default('pending_validation');
            $table->boolean('is_required')->default(false);
            $table->timestamp('validated_at')->nullable();
            $table->uuid('validated_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('movement_id')->references('id')->on('movements')->onDelete('cascade');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('set null');
            $table->foreign('validated_by')->references('id')->on('users')->onDelete('set null');

            $table->index('movement_id');
            $table->index('document_type');
            $table->index('status');
            $table->index('sha256_hash');
        });

        Schema::create('movement_document_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('movement_classification', 30)->nullable();
            $table->string('movement_type', 30)->nullable();
            $table->string('category')->nullable();
            $table->uuid('supplier_id')->nullable();
            $table->boolean('requires_invoice')->default(true);
            $table->boolean('requires_receipt')->default(false);
            $table->boolean('requires_payment_proof')->default(true);
            $table->boolean('requires_bank_match')->default(true);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('set null');

            $table->index('movement_classification');
            $table->index('movement_type');
            $table->index('category');
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movement_document_requirements');
        Schema::dropIfExists('movement_documents');

        Schema::table('movements', function (Blueprint $table) {
            if (Schema::hasColumn('movements', 'document_control_status')) {
                $table->dropColumn('document_control_status');
            }

            if (Schema::hasColumn('movements', 'estado_documental')) {
                $table->dropColumn('estado_documental');
            }

            if (Schema::hasColumn('movements', 'estado_conciliacao')) {
                $table->dropColumn('estado_conciliacao');
            }

            if (Schema::hasColumn('movements', 'categoria')) {
                $table->dropColumn('categoria');
            }
        });
    }
};
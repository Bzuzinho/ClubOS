<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('bank_reconciliation_suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('bank_statement_id')->constrained('bank_statements')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            if (Schema::hasTable('familias')) {
                $table->foreignUuid('family_id')->nullable()->constrained('familias')->nullOnDelete();
            } else {
                $table->uuid('family_id')->nullable();
            }

            $table->string('status', 20)->default('suggested');
            $table->unsignedTinyInteger('score')->default(0);
            $table->string('confidence_label', 20)->nullable();
            $table->decimal('total_bank_amount', 10, 2);
            $table->decimal('total_allocated_amount', 10, 2);
            $table->decimal('unallocated_amount', 10, 2)->default(0);
            $table->json('suggested_allocations')->nullable();
            $table->json('matched_rules')->nullable();
            $table->text('explanation')->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignUuid('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('bank_statement_id');
            $table->index('user_id');
            $table->index('family_id');
            $table->index('status');
            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_suggestions');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('payments')) {
            return;
        }

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('family_id')->nullable()->constrained('familias')->nullOnDelete();
            $table->foreignUuid('bank_statement_id')->nullable()->constrained('bank_statements')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('allocated_amount', 10, 2)->default(0);
            $table->decimal('unallocated_amount', 10, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->string('method')->nullable();
            $table->string('reference')->nullable();
            $table->text('description')->nullable();
            $table->string('source', 30)->default('manual');
            $table->string('status', 20)->default('confirmed');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
            $table->index('family_id');
            $table->index('bank_statement_id');
            $table->index('payment_date');
            $table->index('status');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('account_credit_usages')) {
            return;
        }

        Schema::create('account_credit_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_credit_id')->constrained('account_credits')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status', 20)->default('applied');
            $table->timestamp('applied_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('account_credit_id');
            $table->index('invoice_id');
            $table->index('status');
            $table->index('applied_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_credit_usages');
    }
};

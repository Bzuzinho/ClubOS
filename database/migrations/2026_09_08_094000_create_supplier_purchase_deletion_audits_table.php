<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_purchase_deletion_audits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('supplier_purchase_id');
            $table->uuid('supplier_id')->nullable();
            $table->string('supplier_name_snapshot');
            $table->string('invoice_reference');
            $table->date('invoice_date');
            $table->decimal('total_amount', 12, 2);
            $table->uuid('financial_movement_id')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->string('deletion_mode', 40);
            $table->string('reason')->nullable();
            $table->json('payload');
            $table->timestamp('deleted_at');
            $table->timestamps();

            $table->index('supplier_purchase_id');
            $table->index('supplier_id');
            $table->index('invoice_reference');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_purchase_deletion_audits');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::create('bank_reconciliation_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            if (Schema::hasTable('familias')) {
                $table->foreignUuid('family_id')->nullable()->constrained('familias')->nullOnDelete();
            } else {
                $table->uuid('family_id')->nullable();
            }

            $table->string('type', 50);
            $table->string('value');
            $table->string('normalized_value')->index();
            $table->boolean('is_confirmed')->default(false);
            $table->unsignedTinyInteger('confidence')->default(50);
            $table->string('source', 50)->default('manual');
            $table->timestamp('last_matched_at')->nullable();
            $table->unsignedInteger('match_count')->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['family_id', 'type']);
            $table->index(['is_confirmed', 'confidence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_aliases');
    }
};
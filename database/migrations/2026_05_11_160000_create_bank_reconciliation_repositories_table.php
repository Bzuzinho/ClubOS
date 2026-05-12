<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_reconciliation_repositories')) {
            return;
        }

        Schema::create('bank_reconciliation_repositories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('signature', 64);
            $table->string('conta')->nullable();
            $table->text('descricao')->nullable();
            $table->string('referencia')->nullable();
            $table->string('normalized_description')->nullable();
            $table->string('normalized_reference')->nullable();
            $table->foreignUuid('primary_user_id')->nullable()->constrained('users')->nullOnDelete();

            if (Schema::hasTable('familias')) {
                $table->foreignUuid('family_id')->nullable()->constrained('familias')->nullOnDelete();
            } else {
                $table->uuid('family_id')->nullable();
            }

            $table->json('matched_user_ids')->nullable();
            $table->unsignedInteger('match_count')->default(0);
            $table->timestamp('last_reconciled_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('signature');
            $table->index('primary_user_id');
            $table->index('family_id');
            $table->index('match_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_reconciliation_repositories');
    }
};
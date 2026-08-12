<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sports_legacy_cutover_ledger')) {
            return;
        }

        Schema::create('sports_legacy_cutover_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('source_type', 64)->index();
            $table->string('source_id', 128);
            $table->string('target_type', 64)->nullable()->index();
            $table->string('target_id', 128)->nullable();
            $table->string('status', 32)->default('manual_review')->index();
            $table->string('reason', 160)->nullable();
            $table->json('source_snapshot')->nullable();
            $table->timestamp('audited_at')->nullable()->index();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['club_id', 'source_type', 'source_id'],
                'sports_legacy_cutover_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_legacy_cutover_ledger');
    }
};

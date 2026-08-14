<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sports_convocation_publications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('convocation_group_id')->index();
            $table->unsignedInteger('version');
            $table->string('fingerprint', 64);
            $table->uuid('published_by')->nullable()->index();
            $table->timestamp('published_at');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('communication_status', 32)->nullable()->index();
            $table->string('communication_key', 128)->nullable();
            $table->json('snapshot_json');
            $table->timestamps();

            $table->foreign('convocation_group_id')->references('id')->on('convocation_groups')->cascadeOnDelete();
            $table->unique(['convocation_group_id', 'version'], 'sports_convocation_publications_version_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_convocation_publications');
    }
};

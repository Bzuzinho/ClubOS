<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('convocation_groups', function (Blueprint $table): void {
            $table->string('publication_status', 24)->default('draft')->index();
            $table->unsignedInteger('publication_version')->default(1);
            $table->timestamp('published_at')->nullable();
            $table->uuid('published_by')->nullable()->index();
            $table->string('published_fingerprint', 64)->nullable();
        });

        Schema::create('sports_communication_intents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->string('source_type', 80)->index();
            $table->string('source_id', 80)->index();
            $table->unsignedInteger('source_version')->default(1);
            $table->string('intent_type', 80)->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->uuid('campaign_id')->nullable()->index();
            $table->json('payload_json')->nullable();
            $table->uuid('requested_by')->nullable()->index();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id', 'source_version'], 'sports_comm_source_version_idx');
        });

        Schema::table('logistics_requests', function (Blueprint $table): void {
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_id', 80)->nullable()->index();
            $table->string('idempotency_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('logistics_requests', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['source_type', 'source_id', 'idempotency_key']);
        });

        Schema::dropIfExists('sports_communication_intents');

        Schema::table('convocation_groups', function (Blueprint $table): void {
            $table->dropColumn([
                'publication_status',
                'publication_version',
                'published_at',
                'published_by',
                'published_fingerprint',
            ]);
        });
    }
};

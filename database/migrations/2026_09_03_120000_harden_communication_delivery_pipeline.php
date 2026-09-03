<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_campaigns', function (Blueprint $table): void {
            $table->string('source_type', 80)->nullable()->index();
            $table->string('source_id', 160)->nullable()->index();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('dispatch_requested_at')->nullable()->index();
        });

        Schema::table('communication_deliveries', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('completed_at')->nullable()->index();
        });

        Schema::table('communication_delivery_recipients', function (Blueprint $table): void {
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->string('provider', 80)->nullable()->index();
            $table->string('provider_message_id', 255)->nullable()->index();
            $table->timestamp('processing_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
        });

        Schema::create('communication_delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipient_id')->constrained('communication_delivery_recipients')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->string('status', 24)->default('processing')->index();
            $table->string('provider', 80)->nullable()->index();
            $table->string('provider_message_id', 255)->nullable()->index();
            $table->string('error_code', 120)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('next_retry_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['recipient_id', 'attempt_number'], 'communication_recipient_attempt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_delivery_attempts');

        Schema::table('communication_delivery_recipients', function (Blueprint $table): void {
            $table->dropColumn([
                'idempotency_key',
                'attempt_count',
                'max_attempts',
                'provider',
                'provider_message_id',
                'processing_at',
                'last_attempt_at',
                'next_attempt_at',
            ]);
        });

        Schema::table('communication_deliveries', function (Blueprint $table): void {
            $table->dropColumn(['idempotency_key', 'completed_at']);
        });

        Schema::table('communication_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'source_id', 'idempotency_key', 'dispatch_requested_at']);
        });
    }
};

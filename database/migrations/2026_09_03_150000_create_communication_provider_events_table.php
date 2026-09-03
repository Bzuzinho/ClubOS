<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_provider_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32)->index();
            $table->string('external_event_id', 255);
            $table->string('provider_message_id', 255)->index();
            $table->string('event_type', 24)->index();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->string('payload_hash', 64);
            $table->string('status', 24)->default('pending')->index();
            $table->foreignUuid('recipient_id')->nullable()->constrained('communication_delivery_recipients')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'communication_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_provider_events');
    }
};

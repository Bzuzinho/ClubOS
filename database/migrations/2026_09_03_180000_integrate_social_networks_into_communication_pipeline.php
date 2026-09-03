<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['communication_templates', 'communication_campaign_channels', 'communication_deliveries'] as $table) {
                DB::statement(sprintf(
                    'ALTER TABLE "%s" DROP CONSTRAINT IF EXISTS "%s_channel_check"',
                    $table,
                    $table,
                ));
            }
        }

        Schema::table('communication_templates', function (Blueprint $table): void {
            $table->string('channel', 40)->change();
        });
        Schema::table('communication_campaign_channels', function (Blueprint $table): void {
            $table->string('channel', 40)->change();
            $table->string('link_url', 2048)->nullable();
            $table->string('media_url', 2048)->nullable();
        });
        Schema::table('communication_deliveries', function (Blueprint $table): void {
            $table->string('channel', 40)->change();
        });

        Schema::create('social_network_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32)->unique();
            $table->string('display_name')->nullable();
            $table->string('username')->nullable();
            $table->string('external_account_id')->nullable();
            $table->string('graph_api_version', 20)->default('v24.0');
            $table->string('app_id')->nullable();
            $table->text('app_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('webhook_verify_token')->nullable();
            $table->boolean('is_enabled')->default(false)->index();
            $table->string('verification_status', 24)->default('not_verified')->index();
            $table->text('verification_message')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('communication_delivery_recipients', function (Blueprint $table): void {
            $table->foreignUuid('social_network_account_id')
                ->nullable()
                ->constrained('social_network_accounts')
                ->nullOnDelete();
        });

        Schema::create('social_network_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 32)->index();
            $table->string('external_event_id', 255);
            $table->string('event_type', 80)->nullable()->index();
            $table->string('provider_message_id', 255)->nullable()->index();
            $table->foreignUuid('recipient_id')->nullable()->constrained('communication_delivery_recipients')->nullOnDelete();
            $table->string('payload_hash', 64);
            $table->string('status', 24)->default('received')->index();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_network_events');

        Schema::table('communication_delivery_recipients', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('social_network_account_id');
        });

        Schema::dropIfExists('social_network_accounts');

        Schema::table('communication_campaign_channels', function (Blueprint $table): void {
            $table->dropColumn(['link_url', 'media_url']);
        });
    }
};

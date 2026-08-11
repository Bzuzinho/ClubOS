<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sports_season_lifecycle_events')) {
            return;
        }

        Schema::create('sports_season_lifecycle_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->uuid('season_id')->index();
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->index();
            $table->text('reason')->nullable();
            $table->uuid('actor_id')->nullable()->index();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['club_id', 'season_id', 'occurred_at'], 'sports_season_lifecycle_history_idx');
        });
    }

    public function down(): void
    {
        // Foundation migrations are expand-first and do not destroy historical sports data.
    }
};

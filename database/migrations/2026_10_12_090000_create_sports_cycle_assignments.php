<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sports_cycle_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('club_id', 64)->index();
            $table->uuid('season_id')->index();
            $table->string('cycle_type', 16);
            $table->uuid('cycle_id');
            $table->string('target_type', 16);
            $table->uuid('target_id');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['cycle_type','cycle_id','target_type','target_id'], 'sports_cycle_assignments_unique');
            $table->index(['club_id','season_id','target_type','target_id'], 'sports_cycle_assignments_target_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sports_cycle_assignments');
    }
};

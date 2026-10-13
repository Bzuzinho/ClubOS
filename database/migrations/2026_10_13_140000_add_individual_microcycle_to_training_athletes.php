<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('training_athletes', function (Blueprint $table): void {
            $table->uuid('individual_microcycle_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('training_athletes', function (Blueprint $table): void {
            $table->dropColumn('individual_microcycle_id');
        });
    }
};

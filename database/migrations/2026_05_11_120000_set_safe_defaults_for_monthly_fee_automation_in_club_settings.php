<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_settings', function (Blueprint $table) {
            if (Schema::hasColumn('club_settings', 'monthly_fee_generation_enabled')) {
                $table->boolean('monthly_fee_generation_enabled')->default(false)->change();
            }

            if (Schema::hasColumn('club_settings', 'monthly_fee_auto_activate_due')) {
                $table->boolean('monthly_fee_auto_activate_due')->default(false)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('club_settings', function (Blueprint $table) {
            if (Schema::hasColumn('club_settings', 'monthly_fee_generation_enabled')) {
                $table->boolean('monthly_fee_generation_enabled')->default(true)->change();
            }

            if (Schema::hasColumn('club_settings', 'monthly_fee_auto_activate_due')) {
                $table->boolean('monthly_fee_auto_activate_due')->default(true)->change();
            }
        });
    }
};
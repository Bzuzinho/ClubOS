<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('club_settings', 'monthly_fee_generation_enabled')) {
                $table->boolean('monthly_fee_generation_enabled')->default(true)->after('iban');
            }

            if (!Schema::hasColumn('club_settings', 'monthly_fee_start_month')) {
                $table->unsignedTinyInteger('monthly_fee_start_month')->default(9)->after('monthly_fee_generation_enabled');
            }

            if (!Schema::hasColumn('club_settings', 'monthly_fee_end_month')) {
                $table->unsignedTinyInteger('monthly_fee_end_month')->default(7)->after('monthly_fee_start_month');
            }

            if (!Schema::hasColumn('club_settings', 'monthly_fee_due_day')) {
                $table->unsignedTinyInteger('monthly_fee_due_day')->default(1)->after('monthly_fee_end_month');
            }

            if (!Schema::hasColumn('club_settings', 'monthly_fee_hide_future')) {
                $table->boolean('monthly_fee_hide_future')->default(true)->after('monthly_fee_due_day');
            }

            if (!Schema::hasColumn('club_settings', 'monthly_fee_auto_activate_due')) {
                $table->boolean('monthly_fee_auto_activate_due')->default(true)->after('monthly_fee_hide_future');
            }

            if (!Schema::hasColumn('club_settings', 'monthly_fee_respect_registration_date')) {
                $table->boolean('monthly_fee_respect_registration_date')->default(true)->after('monthly_fee_auto_activate_due');
            }

            if (!Schema::hasColumn('club_settings', 'monthly_fee_generate_months_ahead')) {
                $table->unsignedTinyInteger('monthly_fee_generate_months_ahead')->nullable()->after('monthly_fee_respect_registration_date');
            }

            if (!Schema::hasColumn('club_settings', 'monthly_fee_default_period_mode')) {
                $table->string('monthly_fee_default_period_mode', 50)->default('financial_cycle')->after('monthly_fee_generate_months_ahead');
            }
        });
    }

    public function down(): void
    {
        Schema::table('club_settings', function (Blueprint $table) {
            foreach ([
                'monthly_fee_default_period_mode',
                'monthly_fee_generate_months_ahead',
                'monthly_fee_respect_registration_date',
                'monthly_fee_auto_activate_due',
                'monthly_fee_hide_future',
                'monthly_fee_due_day',
                'monthly_fee_end_month',
                'monthly_fee_start_month',
                'monthly_fee_generation_enabled',
            ] as $column) {
                if (Schema::hasColumn('club_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
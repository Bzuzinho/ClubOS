<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_entries', function (Blueprint $table) {
            if (!Schema::hasColumn('financial_entries', 'data_liquidacao')) {
                $table->date('data_liquidacao')->nullable()->after('data_pagamento');
            }
        });
    }

    public function down(): void
    {
        Schema::table('financial_entries', function (Blueprint $table) {
            if (Schema::hasColumn('financial_entries', 'data_liquidacao')) {
                $table->dropColumn('data_liquidacao');
            }
        });
    }
};
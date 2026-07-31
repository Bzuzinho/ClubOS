<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_statements', function (Blueprint $table): void {
            $table->timestamp('suggestions_analyzed_at')->nullable()->after('conciliacao_status');
        });
    }

    public function down(): void
    {
        Schema::table('bank_statements', function (Blueprint $table): void {
            $table->dropColumn('suggestions_analyzed_at');
        });
    }
};

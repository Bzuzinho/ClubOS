<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('results', function (Blueprint $table): void {
            $table->string('status', 16)->default('ok')->index();
            $table->decimal('tempo_oficial', 10, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('results', function (Blueprint $table): void {
            $table->dropColumn('status');
            $table->decimal('tempo_oficial', 10, 2)->nullable(false)->change();
        });
    }
};

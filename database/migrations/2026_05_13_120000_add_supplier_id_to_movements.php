<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            if (!Schema::hasColumn('movements', 'supplier_id')) {
                $table->uuid('supplier_id')->nullable()->after('user_id');
                $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
                $table->index('supplier_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {
            if (Schema::hasColumn('movements', 'supplier_id')) {
                $table->dropForeign(['supplier_id']);
                $table->dropIndex(['supplier_id']);
                $table->dropColumn('supplier_id');
            }
        });
    }
};
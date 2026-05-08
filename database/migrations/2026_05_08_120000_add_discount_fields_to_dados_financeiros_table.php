<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('dados_financeiros', function (Blueprint $table) {
            if (!Schema::hasColumn('dados_financeiros', 'discount_type')) {
                $table->string('discount_type', 20)->nullable()->after('mensalidade_id');
            }

            if (!Schema::hasColumn('dados_financeiros', 'discount_value')) {
                $table->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
            }

            if (!Schema::hasColumn('dados_financeiros', 'discount_reason')) {
                $table->string('discount_reason')->nullable()->after('discount_value');
            }
        });
    }

    public function down(): void
    {
        Schema::table('dados_financeiros', function (Blueprint $table) {
            $columns = array_values(array_filter([
                Schema::hasColumn('dados_financeiros', 'discount_reason') ? 'discount_reason' : null,
                Schema::hasColumn('dados_financeiros', 'discount_value') ? 'discount_value' : null,
                Schema::hasColumn('dados_financeiros', 'discount_type') ? 'discount_type' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
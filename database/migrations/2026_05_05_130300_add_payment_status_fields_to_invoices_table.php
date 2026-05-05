<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'valor_pago')) {
                $table->decimal('valor_pago', 10, 2)->default(0)->after('valor_total');
            }
            if (!Schema::hasColumn('invoices', 'valor_em_aberto')) {
                $table->decimal('valor_em_aberto', 10, 2)->nullable()->after('valor_pago');
            }
            if (!Schema::hasColumn('invoices', 'data_pagamento')) {
                $table->date('data_pagamento')->nullable()->after('data_vencimento');
            }
            if (!Schema::hasColumn('invoices', 'metodo_pagamento')) {
                $table->string('metodo_pagamento', 50)->nullable()->after('referencia_pagamento');
            }
            if (!Schema::hasColumn('invoices', 'pagamento_observacoes')) {
                $table->text('pagamento_observacoes')->nullable()->after('observacoes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('invoices', 'valor_pago')) {
                $columns[] = 'valor_pago';
            }
            if (Schema::hasColumn('invoices', 'valor_em_aberto')) {
                $columns[] = 'valor_em_aberto';
            }
            if (Schema::hasColumn('invoices', 'data_pagamento')) {
                $columns[] = 'data_pagamento';
            }
            if (Schema::hasColumn('invoices', 'metodo_pagamento')) {
                $columns[] = 'metodo_pagamento';
            }
            if (Schema::hasColumn('invoices', 'pagamento_observacoes')) {
                $columns[] = 'pagamento_observacoes';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
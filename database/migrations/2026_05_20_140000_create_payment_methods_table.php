<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('codigo', 50)->unique();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->boolean('requer_linha_bancaria')->default(false);
            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('ordem')->default(0);
            $table->timestamps();
        });

        $now = now();

        DB::table('payment_methods')->insert([
            [
                'id' => (string) Str::uuid(),
                'codigo' => 'transferencia',
                'nome' => 'Transferencia',
                'descricao' => 'Pagamento conciliado com linha bancaria.',
                'requer_linha_bancaria' => true,
                'ativo' => true,
                'ordem' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'codigo' => 'dinheiro',
                'nome' => 'Dinheiro',
                'descricao' => 'Pagamento manual sem linha bancaria.',
                'requer_linha_bancaria' => false,
                'ativo' => true,
                'ordem' => 2,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'codigo' => 'multibanco',
                'nome' => 'Multibanco',
                'descricao' => 'Pagamento manual por referencia ou terminal MB.',
                'requer_linha_bancaria' => false,
                'ativo' => true,
                'ordem' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'codigo' => 'tpa',
                'nome' => 'TPA',
                'descricao' => 'Pagamento manual em terminal de pagamento automatico.',
                'requer_linha_bancaria' => false,
                'ativo' => true,
                'ordem' => 4,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(),
                'codigo' => 'cheque',
                'nome' => 'Cheque',
                'descricao' => 'Pagamento manual registado por cheque.',
                'requer_linha_bancaria' => false,
                'ativo' => true,
                'ordem' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};

<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use App\Services\Operations\OperationalDataResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OperationalDataResetServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_clears_operational_domains_and_preserves_member_and_fee_configuration(): void
    {
        $user = User::factory()->create();
        $feeId = (string) Str::uuid();

        DB::table('monthly_fees')->insert([
            'id' => $feeId,
            'designacao' => 'Mensalidade base',
            'valor' => 25,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('dados_financeiros')->updateOrInsert(
            ['user_id' => $user->id],
            [
                'id' => (string) Str::uuid(),
                'mensalidade_id' => $feeId,
                'conta_corrente_manual' => 99,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $invoiceId = (string) Str::uuid();
        DB::table('invoices')->insert([
            'id' => $invoiceId,
            'user_id' => $user->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addMonth()->toDateString(),
            'valor_total' => 25,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('invoice_items')->insert([
            'id' => (string) Str::uuid(),
            'fatura_id' => $invoiceId,
            'descricao' => 'Mensalidade teste',
            'valor_unitario' => 25,
            'quantidade' => 1,
            'imposto_percentual' => 0,
            'total_linha' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('bank_statements')->insert([
            'id' => (string) Str::uuid(),
            'data_movimento' => now()->toDateString(),
            'descricao' => 'Movimento fictício',
            'valor' => 25,
            'conciliado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('movements')->insert([
            'id' => (string) Str::uuid(),
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 10,
            'estado_pagamento' => 'pendente',
            'tipo' => 'despesa',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('competitions')) {
            DB::table('competitions')->insert([
                'id' => (string) Str::uuid(),
                'nome' => 'Competição fictícia',
                'local' => 'Teste',
                'data_inicio' => now()->toDateString(),
                'tipo' => 'prova',
                'club_id' => 'bscn',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $report = app(OperationalDataResetService::class)->execute();

        $this->assertTrue($report['preserved_invariants_ok']);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('monthly_fees', ['id' => $feeId]);
        $this->assertDatabaseHas('dados_financeiros', [
            'user_id' => $user->id,
            'mensalidade_id' => $feeId,
            'conta_corrente_manual' => 0,
        ]);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('bank_statements', 0);
        $this->assertDatabaseCount('movements', 0);
        if (Schema::hasTable('competitions')) {
            $this->assertDatabaseCount('competitions', 0);
        }
    }
}

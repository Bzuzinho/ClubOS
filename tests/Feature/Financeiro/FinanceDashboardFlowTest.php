<?php

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Financeiro\FinanceDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceDashboardFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_dashboard_service_builds_financial_summary_from_backend_models(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Dashboard',
            'email' => 'dashboard-athlete@example.com',
            'nif' => '123123123',
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->subDays(20)->toDateString(),
            'data_emissao' => now()->subDays(20)->toDateString(),
            'data_vencimento' => now()->subDays(10)->toDateString(),
            'valor_total' => 45.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 45.00,
            'estado_pagamento' => 'vencido',
            'tipo' => 'mensalidade',
            'oculta' => false,
            'mes' => now()->format('Y-m'),
        ]);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Mensalidade',
            'descricao' => 'Receita do mes',
            'documento_ref' => 'REC-1',
            'valor' => 80.00,
            'valor_pago' => 80.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Despesa do mes',
            'documento_ref' => 'DESP-1',
            'valor' => 30.00,
            'valor_pago' => 30.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Servico',
            'descricao' => 'Receita pendente',
            'documento_ref' => 'PEND-1',
            'valor' => 20.00,
            'valor_pago' => 5.00,
            'valor_em_aberto' => 15.00,
            'estado' => 'parcial',
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        $summary = app(FinanceDashboardService::class)->build();

        $this->assertSame(50.0, $summary['total_geral']);
        $this->assertSame(80.0, $summary['receitas_mes']);
        $this->assertSame(30.0, $summary['despesas_mes']);
        $this->assertSame(45.0, $summary['mensalidades_vencidas']);
        $this->assertSame(15.0, $summary['movimentos_pendentes']);
        $this->assertNotEmpty($summary['evolucao_mensal_ultimos_6_meses']);
        $this->assertNotEmpty($summary['receitas_despesas_por_centro_custo']);
    }

    public function test_financeiro_index_includes_dashboard_data_from_backend_payload(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();

        $response = $this->actingAs($admin)->get(route('financeiro.index'));

        $response->assertOk();
        $response->assertSee('dashboardData', false);
        $response->assertSee('receitas_mes', false);
        $response->assertSee('mensalidades_vencidas', false);
    }
}

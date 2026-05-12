<?php

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\MembershipFee;
use App\Models\Movement;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceReportFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_cost_center_report_sums_canonical_revenue_and_expense(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create();

        FinancialEntry::query()->create([
            'data' => now()->subDays(3)->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Servicos',
            'descricao' => 'Receita canonica relatorio',
            'documento_ref' => 'REL-REC-1',
            'valor' => 120.00,
            'valor_pago' => 120.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->subDays(3)->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        FinancialEntry::query()->create([
            'data' => now()->subDays(2)->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Despesa canonica relatorio',
            'documento_ref' => 'REL-DESP-1',
            'valor' => 45.00,
            'valor_pago' => 45.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->subDays(2)->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        $response = $this->actingAs($admin)->getJson(route('relatorios-financeiros.index', [
            'data_inicio' => now()->subWeek()->toDateString(),
            'data_fim' => now()->toDateString(),
            'centro_custo_id' => $costCenter->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('reports.cost_centers.items.0.receitas', 120);
        $response->assertJsonPath('reports.cost_centers.items.0.despesas', 45);
        $response->assertJsonPath('reports.cost_centers.items.0.saldo', 75);
    }

    public function test_period_report_respects_date_range(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create();

        FinancialEntry::query()->create([
            'data' => '2026-04-10',
            'tipo' => 'receita',
            'categoria' => 'Servicos',
            'descricao' => 'Fora do periodo',
            'documento_ref' => 'PER-OUT-1',
            'valor' => 200.00,
            'valor_pago' => 200.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => '2026-04-10',
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        FinancialEntry::query()->create([
            'data' => '2026-05-10',
            'tipo' => 'receita',
            'categoria' => 'Servicos',
            'descricao' => 'Dentro do periodo',
            'documento_ref' => 'PER-IN-1',
            'valor' => 80.00,
            'valor_pago' => 80.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => '2026-05-10',
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        $response = $this->actingAs($admin)->getJson(route('relatorios-financeiros.index', [
            'data_inicio' => '2026-05-01',
            'data_fim' => '2026-05-31',
        ]));

        $response->assertOk();
        $response->assertJsonPath('reports.period.totals.receitas', 80);
        $response->assertJsonPath('reports.period.totals.despesas', 0);
    }

    public function test_hidden_and_future_monthly_invoices_are_ignored_in_reports(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create([
            'escalao' => ['age-group-1'],
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->subMonth()->toDateString(),
            'data_emissao' => now()->subMonth()->toDateString(),
            'data_vencimento' => now()->subWeeks(3)->toDateString(),
            'valor_total' => 30.00,
            'valor_pago' => 30.00,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => now()->subWeeks(3)->toDateString(),
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->addMonth()->toDateString(),
            'data_emissao' => now()->addMonth()->toDateString(),
            'data_vencimento' => now()->addMonth()->toDateString(),
            'valor_total' => 999.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 999.00,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->subMonth()->toDateString(),
            'data_emissao' => now()->subMonth()->toDateString(),
            'data_vencimento' => now()->subWeeks(3)->toDateString(),
            'valor_total' => 500.00,
            'valor_pago' => 500.00,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => now()->subWeeks(3)->toDateString(),
            'tipo' => 'mensalidade',
            'oculta' => true,
        ]);

        $response = $this->actingAs($admin)->getJson(route('relatorios-financeiros.index'));

        $response->assertOk();
        $response->assertJsonPath('reports.age_groups.available', true);
        $response->assertJsonPath('reports.age_groups.items.0.total_faturado', 30);
        $response->assertJsonPath('reports.age_groups.items.0.total_pago', 30);
        $response->assertJsonPath('reports.age_groups.items.0.total_pendente', 0);
    }

    public function test_legacy_movements_with_financial_entry_are_not_duplicated(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create();

        $movement = Movement::query()->create([
            'user_id' => $user->id,
            'classificacao' => 'receita',
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->subDay()->toDateString(),
            'valor_total' => 70.00,
            'estado_pagamento' => 'pago',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'nome_manual' => 'Receita legacy',
        ]);

        FinancialEntry::query()->create([
            'data' => now()->subDay()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Servicos',
            'descricao' => 'Receita canonica associada ao movimento',
            'documento_ref' => 'LEG-DUP-1',
            'valor' => 70.00,
            'valor_pago' => 70.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->subDay()->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'movement',
            'origem_modulo' => 'financeiro',
            'origem_id' => $movement->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('relatorios-financeiros.index', [
            'data_inicio' => now()->subWeek()->toDateString(),
            'data_fim' => now()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('reports.period.totals.receitas', 70);
    }

    public function test_reports_endpoint_does_not_use_transaction_or_membership_fee_models(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $user = User::factory()->create();

        $transaction = Transaction::query()->create([
            'user_id' => $user->id,
            'descricao' => 'Legacy transacao ignorada',
            'valor' => 999.00,
            'tipo' => 'receita',
            'data' => now()->toDateString(),
            'estado' => 'pago',
        ]);

        MembershipFee::query()->create([
            'user_id' => $user->id,
            'month' => (int) now()->format('n'),
            'year' => (int) now()->format('Y'),
            'amount' => 999.00,
            'status' => 'paid',
            'payment_date' => now()->toDateString(),
            'transaction_id' => $transaction->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('relatorios-financeiros.index', [
            'data_inicio' => now()->startOfMonth()->toDateString(),
            'data_fim' => now()->endOfMonth()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertJsonPath('reports.period.totals.receitas', 0);
        $response->assertJsonPath('reports.period.totals.despesas', 0);
    }
}
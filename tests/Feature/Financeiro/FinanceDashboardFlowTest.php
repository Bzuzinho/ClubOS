<?php

namespace Tests\Feature\Financeiro;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\User;
use App\Services\Financeiro\FinanceDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FinanceDashboardFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_dashboard_ignores_hidden_and_future_monthly_invoices(): void
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

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->addMonth()->toDateString(),
            'data_emissao' => now()->addMonth()->toDateString(),
            'data_vencimento' => now()->addMonth()->toDateString(),
            'valor_total' => 55.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 55.00,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => true,
            'mes' => now()->addMonth()->format('Y-m'),
        ]);

        $summary = app(FinanceDashboardService::class)->build();

        $this->assertSame(45.0, $summary['mensalidades_vencidas']);
    }

    public function test_dashboard_sums_paid_monthly_fees_and_paid_revenue_movements(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Dashboard Receita',
            'email' => 'dashboard-receita@example.com',
            'nif' => '456456456',
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->subDays(5)->toDateString(),
            'data_emissao' => now()->subDays(5)->toDateString(),
            'data_vencimento' => now()->subDays(1)->toDateString(),
            'valor_total' => 45.00,
            'valor_pago' => 45.00,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => now()->subDay()->toDateString(),
            'tipo' => 'mensalidade',
            'oculta' => false,
            'mes' => now()->format('Y-m'),
        ]);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Servico',
            'descricao' => 'Receita de movimento paga',
            'documento_ref' => 'REC-1',
            'valor' => 80.00,
            'valor_pago' => 80.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'movement',
            'origem_modulo' => 'financeiro',
        ]);

        $summary = app(FinanceDashboardService::class)->build();

        $this->assertSame(125.0, $summary['receitas_mes']);
    }

    public function test_dashboard_sums_paid_expense_movements(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();

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

        $summary = app(FinanceDashboardService::class)->build();

        $this->assertSame(30.0, $summary['despesas_mes']);
    }

    public function test_dashboard_counts_overdue_monthly_fees_open_amount(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Dashboard Vencida',
            'email' => 'dashboard-vencida@example.com',
            'nif' => '789789789',
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

        $summary = app(FinanceDashboardService::class)->build();

        $this->assertSame(45.0, $summary['mensalidades_vencidas']);
    }

    public function test_dashboard_groups_revenue_and_expense_by_cost_center(): void
    {
        $centers = CostCenter::query()->take(2)->get();
        $firstCenter = $centers[0];
        $secondCenter = $centers[1] ?? CostCenter::query()->create([
            'codigo' => 'CC-DASH-02',
            'nome' => 'Centro Dashboard 02',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $user = User::factory()->create([
            'nome_completo' => 'Atleta Dashboard Centros',
            'email' => 'dashboard-centros@example.com',
            'nif' => '741741741',
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $firstCenter->id,
            'data_fatura' => now()->subDays(5)->toDateString(),
            'data_emissao' => now()->subDays(5)->toDateString(),
            'data_vencimento' => now()->subDays(1)->toDateString(),
            'valor_total' => 40.00,
            'valor_pago' => 40.00,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => now()->subDay()->toDateString(),
            'tipo' => 'mensalidade',
            'oculta' => false,
            'mes' => now()->format('Y-m'),
        ]);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Patrocinio',
            'descricao' => 'Receita centro 1',
            'documento_ref' => 'REC-CC1',
            'valor' => 60.00,
            'valor_pago' => 60.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $firstCenter->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Despesa centro 2',
            'documento_ref' => 'DESP-CC2',
            'valor' => 25.00,
            'valor_pago' => 25.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $secondCenter->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
        ]);

        $summary = app(FinanceDashboardService::class)->build();

        $firstRow = collect($summary['receitas_despesas_por_centro_custo'])->firstWhere('centro_custo_id', $firstCenter->id);
        $secondRow = collect($summary['receitas_despesas_por_centro_custo'])->firstWhere('centro_custo_id', $secondCenter->id);

        $this->assertNotNull($firstRow);
        $this->assertNotNull($secondRow);
        $this->assertSame(100.0, (float) $firstRow['receitas']);
        $this->assertSame(0.0, (float) $firstRow['despesas']);
        $this->assertSame(0.0, (float) $secondRow['receitas']);
        $this->assertSame(25.0, (float) $secondRow['despesas']);
    }

    public function test_dashboard_pending_amount_includes_partial_non_monthly_entries(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Dashboard Pendente',
            'email' => 'dashboard-pendente@example.com',
            'nif' => '852852852',
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

        Movement::query()->create([
            'user_id' => $user->id,
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 12.00,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'nome_manual' => 'Despesa pendente legacy',
        ]);

        $summary = app(FinanceDashboardService::class)->build();

        $this->assertSame(27.0, $summary['movimentos_pendentes']);
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

    public function test_financeiro_index_exposes_only_monthly_invoices_in_mensalidades_payload(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Payload Mensalidades',
            'email' => 'payload-mensalidades@example.com',
            'nif' => '963852741',
        ]);

        $monthlyInvoice = Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->subDays(15)->toDateString(),
            'data_emissao' => now()->subDays(15)->toDateString(),
            'data_vencimento' => now()->subDays(5)->toDateString(),
            'valor_total' => 30.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 30.00,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
            'mes' => now()->format('Y-m'),
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->subDays(10)->toDateString(),
            'data_emissao' => now()->subDays(10)->toDateString(),
            'data_vencimento' => now()->subDays(2)->toDateString(),
            'valor_total' => 45.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 45.00,
            'estado_pagamento' => 'pendente',
            'tipo' => 'servico',
            'oculta' => false,
        ]);

        Cache::flush();

        $response = $this->inertiaGetAs($admin, route('financeiro.index', ['fresh' => 1]));

        $response->assertOk();
        $response->assertJsonPath('component', 'Financeiro/Index');
        $response->assertJsonCount(1, 'props.mensalidadesFaturas');
        $response->assertJsonPath('props.mensalidadesFaturas.0.id', $monthlyInvoice->id);
        $response->assertJsonPath('props.mensalidadesFaturas.0.tipo', 'mensalidade');
        $response->assertJsonPath('props.mensalidadesFaturas.0.estado_pagamento', 'vencido');
    }

    public function test_financeiro_index_exposes_canonical_movements_payload_without_monthly_invoices(): void
    {
        $admin = User::query()->where('email', 'admin@test.com')->firstOrFail();
        $costCenter = CostCenter::query()->firstOrFail();
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Payload Movimentos',
            'email' => 'payload-movimentos@example.com',
            'nif' => '147258369',
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->subDays(15)->toDateString(),
            'data_emissao' => now()->subDays(15)->toDateString(),
            'data_vencimento' => now()->subDays(5)->toDateString(),
            'valor_total' => 30.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 30.00,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
            'mes' => now()->format('Y-m'),
        ]);

        $legacyMovement = Movement::query()->create([
            'user_id' => $user->id,
            'classificacao' => 'receita',
            'data_emissao' => now()->subDays(2)->toDateString(),
            'data_vencimento' => now()->addDay()->toDateString(),
            'valor_total' => 70.00,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'nome_manual' => 'Receita legacy',
        ]);

        $canonicalEntry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Despesa canonica manual',
            'documento_ref' => 'DESP-CAN-1',
            'valor' => 25.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 25.00,
            'estado' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
            'entidade_nome' => 'Fornecedor Canonico',
        ]);

        Cache::flush();

        $response = $this->inertiaGetAs($admin, route('financeiro.index', ['fresh' => 1]));
        $movimentosFinanceiros = collect($response->json('props.movimentosFinanceiros'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Financeiro/Index');
        $response->assertJsonCount(2, 'props.movimentosFinanceiros');

        $this->assertTrue($movimentosFinanceiros->contains(fn (array $item): bool =>
            ($item['movimento_id'] ?? null) === $legacyMovement->id
            && ($item['source_kind'] ?? null) === 'movement'
            && ($item['classificacao'] ?? null) === 'receita'
        ));

        $this->assertTrue($movimentosFinanceiros->contains(fn (array $item): bool =>
            ($item['financial_entry_id'] ?? null) === $canonicalEntry->id
            && ($item['source_kind'] ?? null) === 'financial_entry'
            && ($item['classificacao'] ?? null) === 'despesa'
        ));
    }

    private function inertiaGetAs(User $user, string $uri)
    {
        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get($uri);
    }
}

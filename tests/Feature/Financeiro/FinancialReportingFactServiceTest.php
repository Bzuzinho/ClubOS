<?php

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\User;
use App\Services\Financeiro\FinancialReportingFactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinancialReportingFactServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_paid_monthly_invoice_is_counted_once(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();
        $invoice = $this->createPaidInvoice('mensalidade', $costCenter->id, 35.00);

        $facts = app(FinancialReportingFactService::class)->paidFacts();

        $invoiceFacts = $facts->where('source_kind', 'invoice')->where('source_id', (string) $invoice->id);

        $this->assertCount(1, $invoiceFacts);
        $this->assertSame('receita', $invoiceFacts->first()['type']);
        $this->assertSame(35.0, (float) $invoiceFacts->first()['amount']);
    }

    public function test_paid_inscricao_and_material_invoices_are_included_once_each(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();
        $inscricao = $this->createPaidInvoice('inscricao', $costCenter->id, 48.00);
        $material = $this->createPaidInvoice('material', $costCenter->id, 22.00);

        $facts = app(FinancialReportingFactService::class)->paidFacts();

        $this->assertCount(1, $facts->where('source_kind', 'invoice')->where('source_id', (string) $inscricao->id));
        $this->assertCount(1, $facts->where('source_kind', 'invoice')->where('source_id', (string) $material->id));
    }

    public function test_invoice_with_financial_entry_fatura_id_does_not_duplicate(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();
        $invoice = $this->createPaidInvoice('material', $costCenter->id, 60.00);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Material',
            'descricao' => 'Entry da invoice',
            'valor' => 60.00,
            'valor_pago' => 60.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'fatura_id' => $invoice->id,
            'origem_tipo' => 'stock',
            'origem_modulo' => 'financeiro',
            'origem_id' => 'stock-order-1',
        ]);

        $facts = app(FinancialReportingFactService::class)->paidFacts();

        $this->assertCount(1, $facts->where('source_kind', 'invoice')->where('source_id', (string) $invoice->id));
        $this->assertCount(0, $facts->where('source_kind', 'financial_entry')->where('origem_tipo', 'stock'));
    }

    public function test_paid_movement_without_entry_is_included(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();

        $movement = Movement::query()->create([
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 15.00,
            'estado_pagamento' => 'pago',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'manual',
            'origem_tipo' => 'manual',
            'origem_id' => 'movement-no-entry',
        ]);

        $facts = app(FinancialReportingFactService::class)->paidFacts();

        $this->assertCount(1, $facts->where('source_kind', 'movement')->where('source_id', (string) $movement->id));
    }

    public function test_movement_with_entry_origin_movement_is_not_duplicated(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();

        $movement = Movement::query()->create([
            'classificacao' => 'receita',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 90.00,
            'estado_pagamento' => 'pago',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'manual',
            'origem_tipo' => 'manual',
            'origem_id' => 'movement-with-entry',
        ]);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Movimento',
            'descricao' => 'Entry canonica do movimento',
            'valor' => 90.00,
            'valor_pago' => 90.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'origem_tipo' => 'movement',
            'origem_modulo' => 'financeiro',
            'origem_id' => $movement->id,
        ]);

        $facts = app(FinancialReportingFactService::class)->paidFacts();

        $this->assertCount(0, $facts->where('source_kind', 'movement')->where('source_id', (string) $movement->id));
        $this->assertCount(1, $facts->where('source_kind', 'financial_entry')->where('origem_tipo', 'movement'));
    }

    public function test_supplier_purchase_parallel_entry_prevents_movement_duplication(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();

        Movement::query()->create([
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 100.00,
            'estado_pagamento' => 'pago',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'fornecedor',
            'origem_tipo' => 'stock',
            'origem_id' => 'supplier-purchase-1',
        ]);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Entry fornecedor',
            'valor' => 100.00,
            'valor_pago' => 100.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'origem_tipo' => 'stock',
            'origem_modulo' => 'financeiro',
            'origem_id' => 'supplier-purchase-1',
        ]);

        $facts = app(FinancialReportingFactService::class)->paidFacts();

        $this->assertCount(1, $facts->where('source_kind', 'financial_entry')->where('origem_tipo', 'stock'));
        $this->assertCount(0, $facts->where('source_kind', 'movement')->where('origem_tipo', 'stock'));
    }

    public function test_negative_expense_movement_is_normalized_as_positive_expense(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();

        $movement = Movement::query()->create([
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -55.25,
            'estado_pagamento' => 'pago',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'manual',
            'origem_tipo' => 'manual',
            'origem_id' => 'negative-expense',
        ]);

        $facts = app(FinancialReportingFactService::class)->paidFacts();
        $fact = $facts->firstWhere('source_id', (string) $movement->id);

        $this->assertSame('despesa', $fact['type']);
        $this->assertSame(55.25, (float) $fact['amount']);
    }

    public function test_balance_uses_revenue_minus_expense_with_positive_amounts(): void
    {
        $costCenter = CostCenter::query()->firstOrFail();

        $this->createPaidInvoice('mensalidade', $costCenter->id, 100.00);

        FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Despesa paga',
            'valor' => 30.00,
            'valor_pago' => 30.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'centro_custo_id' => $costCenter->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
            'origem_id' => 'expense-1',
        ]);

        $facts = app(FinancialReportingFactService::class)->paidFacts();
        $receitas = (float) $facts->where('type', 'receita')->sum('amount');
        $despesas = (float) $facts->where('type', 'despesa')->sum('amount');

        $this->assertSame(100.0, $receitas);
        $this->assertSame(30.0, $despesas);
        $this->assertSame(70.0, $receitas - $despesas);
    }

    public function test_paid_facts_support_filters_for_user_cost_center_origin_and_period(): void
    {
        $firstCenter = CostCenter::query()->firstOrFail();
        $secondCenter = CostCenter::query()->skip(1)->first() ?: CostCenter::query()->create([
            'codigo' => 'CC-XFIN2-02',
            'nome' => 'Centro Custo XFIN2 02',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();

        FinancialEntry::query()->create([
            'data' => '2026-05-10',
            'tipo' => 'receita',
            'categoria' => 'Servico',
            'descricao' => 'Filtro A',
            'valor' => 77.00,
            'valor_pago' => 77.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => '2026-05-10',
            'centro_custo_id' => $firstCenter->id,
            'user_id' => $userA->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
            'origem_id' => 'filter-a',
        ]);

        FinancialEntry::query()->create([
            'data' => '2026-06-10',
            'tipo' => 'receita',
            'categoria' => 'Servico',
            'descricao' => 'Filtro B',
            'valor' => 88.00,
            'valor_pago' => 88.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => '2026-06-10',
            'centro_custo_id' => $secondCenter->id,
            'user_id' => $userB->id,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
            'origem_id' => 'filter-b',
        ]);

        $facts = app(FinancialReportingFactService::class)->paidFacts(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31'),
            [
                'user_id' => $userA->id,
                'centro_custo_id' => $firstCenter->id,
                'origem_modulo' => 'financeiro',
                'origem_tipo' => 'manual',
                'tipo' => 'receita',
            ],
        );

        $this->assertCount(1, $facts);
        $this->assertSame($userA->id, $facts->first()['user_id']);
        $this->assertSame($firstCenter->id, $facts->first()['centro_custo_id']);
        $this->assertSame('financeiro', $facts->first()['origem_modulo']);
        $this->assertSame('manual', $facts->first()['origem_tipo']);
        $this->assertSame('2026-05-10', $facts->first()['paid_at']->toDateString());
    }

    private function createPaidInvoice(string $type, string $costCenterId, float $amount): Invoice
    {
        return Invoice::query()->create([
            'user_id' => User::factory()->create()->id,
            'centro_custo_id' => $costCenterId,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => $amount,
            'valor_pago' => $amount,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => now()->toDateString(),
            'tipo' => $type,
            'oculta' => false,
            'origem_tipo' => 'financeiro',
            'origem_id' => uniqid('origin-', true),
        ]);
    }
}

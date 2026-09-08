<?php

namespace Tests\Feature\Logistica;

use App\Models\BankStatement;
use App\Models\FinancialEntry;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financeiro\BankReconciliationSuggestionService;
use App\Services\Logistica\RegisterSupplierPurchaseAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPurchaseBankReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_purchase_creates_expense_that_can_be_reconciled_with_bank_statement(): void
    {
        $actor = User::factory()->admin()->create();

        $supplier = Supplier::query()->create([
            'nome' => 'Fornecedor Reconciliação',
            'nif' => '509999991',
            'email' => 'supplier-reconciliation@example.test',
            'telefone' => '912345679',
            'categoria' => 'Equipamento',
            'ativo' => true,
        ]);

        $product = Product::query()->create([
            'codigo' => 'SP-RECON-001',
            'nome' => 'Material Reconciliação',
            'categoria' => 'Equipamento',
            'preco' => 20,
            'stock' => 0,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'supplier_id' => $supplier->id,
            'ativo' => true,
        ]);

        $purchase = app(RegisterSupplierPurchaseAction::class)->execute([
            'supplier_id' => $supplier->id,
            'invoice_reference' => 'SUP-RECON-001',
            'invoice_date' => '2026-09-08',
            'due_date' => '2026-09-15',
            'centro_custo_id' => null,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => 5,
                'unit_cost' => 10,
            ]],
        ], $actor);

        $movement = Movement::query()->findOrFail($purchase->financial_movement_id);

        $this->assertSame('despesa', $movement->classificacao);
        $this->assertSame('por_pagar', $movement->estado_pagamento);
        $this->assertSame('nao_conciliado', $movement->estado_conciliacao);
        $this->assertSame('supplier_purchase', $movement->origem_tipo);
        $this->assertSame($purchase->id, $movement->origem_id);
        $this->assertEquals(50.0, (float) $movement->valor_total);
        $this->assertNull($purchase->financial_entry_id);

        $statement = BankStatement::query()->create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-09-10',
            'descricao' => 'Pagamento Fornecedor Reconciliação SUP-RECON-001',
            'valor' => -50.00,
            'saldo' => 950.00,
            'referencia' => 'SUP-RECON-001',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => -50.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $service = app(BankReconciliationSuggestionService::class);
        $suggestion = $service
            ->generateForBankStatement($statement)
            ->first(fn ($candidate): bool => collect($candidate->suggested_allocations)
                ->contains(fn (array $allocation): bool => ($allocation['movement_id'] ?? null) === $movement->id));

        $this->assertNotNull($suggestion, 'A compra deve surgir como despesa conciliável para uma saída bancária do mesmo valor.');

        $payment = $service->confirmSuggestion($suggestion, $actor);

        $movement->refresh();
        $statement->refresh();

        $this->assertSame('pago', $movement->estado_pagamento);
        $this->assertSame('conciliado', $movement->estado_conciliacao);
        $this->assertTrue((bool) $statement->conciliado);
        $this->assertSame($statement->id, $payment->bank_statement_id);
        $this->assertTrue(FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->where('origem_id', $movement->id)
            ->where('bank_statement_id', $statement->id)
            ->exists());
    }
}

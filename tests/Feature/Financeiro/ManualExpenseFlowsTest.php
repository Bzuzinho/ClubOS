<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementDocumentRequirement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ManualExpenseFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        Storage::fake('public');
    }

    public function test_creating_simple_expense_creates_movement_item_financial_entry_and_document(): void
    {
        $admin = User::factory()->create();
        $costCenter = CostCenter::query()->firstOrFail();
        $supplier = Supplier::query()->create([
            'nome' => 'Piscina Municipal',
            'nif' => '509999991',
            'ativo' => true,
        ]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.movimentos.store'), [
            'supplier_id' => $supplier->id,
            'classificacao' => 'despesa',
            'categoria' => 'piscina',
            'document_type' => 'invoice',
            'document_number' => 'FAC-POOL-1',
            'document_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'valor_total' => 120.00,
            'estado_pagamento' => 'por_pagar',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'notes' => 'Aluguer de piscina',
            'items' => [[
                'descricao' => 'Piscina',
                'quantidade' => 1,
                'valor_unitario' => 120.00,
                'imposto_percentual' => 0,
                'total_linha' => 120.00,
                'centro_custo_id' => $costCenter->id,
            ]],
        ]);

        $response->assertOk();

        $movement = Movement::query()->firstOrFail();
        $entry = FinancialEntry::query()->where('origem_tipo', 'movement')->where('origem_id', $movement->id)->first();
        $document = MovementDocument::query()->where('movement_id', $movement->id)->first();

        $this->assertNotNull($entry);
        $this->assertNotNull($document);
        $this->assertSame('despesa', $movement->classificacao);
        $this->assertSame('piscina', $movement->categoria);
        $this->assertSame('por_pagar', $movement->estado_pagamento);
        $this->assertDatabaseHas('movement_items', [
            'movimento_id' => $movement->id,
            'descricao' => 'Piscina',
        ]);
        $this->assertSame('invoice', $document->document_type);
        $this->assertSame('despesa', $entry->tipo);
    }

    public function test_bank_payment_before_invoice_creates_paid_reconciled_expense_missing_invoice(): void
    {
        $admin = User::factory()->create();
        $costCenter = CostCenter::query()->firstOrFail();

        $statement = BankStatement::query()->create([
            'data_movimento' => now()->toDateString(),
            'descricao' => 'Transferencia fornecedor agua',
            'valor' => -45.00,
            'referencia' => 'TRX-AGUA-1',
            'centro_custo_id' => $costCenter->id,
            'conciliado' => false,
        ]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.extratos.criar-despesa', $statement->id), [
            'centro_custo_id' => $costCenter->id,
            'categoria' => 'agua',
            'tipo' => 'servico',
            'notes' => 'Pagamento agua',
        ]);

        $response->assertOk();

        $movement = Movement::query()->where('origem_tipo', 'bank_statement')->firstOrFail();

        $this->assertSame('pago', $movement->estado_pagamento);
        $this->assertSame('conciliado', $movement->estado_conciliacao);
        $this->assertSame('falta_fatura', $movement->estado_documental);
        $this->assertDatabaseHas('movement_documents', [
            'movement_id' => $movement->id,
            'document_type' => 'bank_statement_line',
        ]);

        $statement->refresh();
        $this->assertTrue((bool) $statement->conciliado);
    }

    public function test_movement_paid_without_receipt_generates_missing_receipt_when_rule_requires_it(): void
    {
        $movement = Movement::query()->create([
            'nome_manual' => 'Seguro Clube',
            'classificacao' => 'despesa',
            'categoria' => 'seguros',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -88.00,
            'estado_pagamento' => 'pago',
            'estado_conciliacao' => 'nao_conciliado',
            'tipo' => 'servico',
        ]);

        MovementDocumentRequirement::query()->create([
            'movement_classification' => 'despesa',
            'category' => 'seguros',
            'requires_invoice' => true,
            'requires_receipt' => true,
            'requires_payment_proof' => false,
        ]);

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'invoice',
            'status' => 'valid',
            'amount' => 88.00,
        ]);

        $movement->refresh();
        $this->assertSame('falta_recibo', $movement->estado_documental);
    }

    public function test_reconciled_movement_without_invoice_generates_missing_invoice(): void
    {
        $movement = Movement::query()->create([
            'nome_manual' => 'Luz',
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -64.00,
            'estado_pagamento' => 'pago',
            'estado_conciliacao' => 'conciliado',
            'tipo' => 'servico',
        ]);

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'bank_statement_line',
            'status' => 'valid',
            'amount' => 64.00,
        ]);

        $movement->refresh();
        $this->assertSame('falta_fatura', $movement->estado_documental);
    }

    public function test_attaching_invoice_receipt_closes_invoice_and_receipt_requirements(): void
    {
        $movement = Movement::query()->create([
            'nome_manual' => 'Contabilidade',
            'classificacao' => 'despesa',
            'categoria' => 'contabilidade',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -70.00,
            'estado_pagamento' => 'pago',
            'estado_conciliacao' => 'nao_conciliado',
            'tipo' => 'servico',
        ]);

        MovementDocumentRequirement::query()->create([
            'movement_classification' => 'despesa',
            'category' => 'contabilidade',
            'requires_invoice' => true,
            'requires_receipt' => true,
            'requires_payment_proof' => false,
        ]);

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'invoice_receipt',
            'status' => 'valid',
            'amount' => 70.00,
        ]);

        $movement->refresh();
        $this->assertSame('completo', $movement->estado_documental);
    }

    public function test_divergent_document_amount_marks_movement_inconsistent(): void
    {
        $movement = Movement::query()->create([
            'nome_manual' => 'Taxa Federativa',
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -100.00,
            'estado_pagamento' => 'por_pagar',
            'estado_conciliacao' => 'nao_conciliado',
            'tipo' => 'servico',
        ]);

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'invoice',
            'status' => 'valid',
            'amount' => 99.00,
        ]);

        $movement->refresh();
        $this->assertSame('inconsistente', $movement->estado_documental);
    }
}
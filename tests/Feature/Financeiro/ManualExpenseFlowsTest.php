<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementDocumentRequirement;
use App\Models\PaymentMethod;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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

        $response
            ->assertOk()
            ->assertJsonPath('movimento.classificacao', 'despesa')
            ->assertJsonPath('movimento.estado_pagamento', 'por_pagar');

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

    public function test_creating_movement_accepts_multipart_form_data_and_returns_json(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = CostCenter::query()->firstOrFail();
        $documentoOriginal = UploadedFile::fake()->create('movimento.pdf', 32, 'application/pdf');

        $response = $this->actingAs($admin)
            ->withHeaders([
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->post(route('financeiro.movimentos.store'), [
                'nome_manual' => 'Patrocinador Teste',
                'classificacao' => 'receita',
                'categoria' => 'patrocinio',
                'data_emissao' => now()->toDateString(),
                'data_vencimento' => now()->addDays(5)->toDateString(),
                'valor_total' => 150.00,
                'estado_pagamento' => 'pendente',
                'centro_custo_id' => $costCenter->id,
                'tipo' => 'patrocinio',
                'documento_original' => $documentoOriginal,
                'items' => json_encode([
                    [
                        'descricao' => 'Apoio mensal',
                        'quantidade' => 1,
                        'valor_unitario' => 150.00,
                        'imposto_percentual' => 0,
                        'total_linha' => 150.00,
                        'centro_custo_id' => $costCenter->id,
                    ],
                ], JSON_THROW_ON_ERROR),
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('movimento.classificacao', 'receita')
            ->assertJsonPath('movimento.nome_manual', 'Patrocinador Teste');

        $movement = Movement::query()->latest('created_at')->firstOrFail();

        $this->assertNotNull($movement->documento_original);
        Storage::disk('public')->assertExists($movement->documento_original);
        $this->assertDatabaseHas('movement_items', [
            'movimento_id' => $movement->id,
            'descricao' => 'Apoio mensal',
        ]);
    }

    public function test_store_movimento_returns_json_validation_errors_for_missing_required_fields(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->postJson(route('financeiro.movimentos.store'), [
            'nome_manual' => 'Fornecedor sem centro de custo',
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 45.00,
            'tipo' => 'servico',
            'items' => [
                [
                    'descricao' => 'Servico sem centro',
                    'quantidade' => 1,
                    'valor_unitario' => 45.00,
                    'imposto_percentual' => 0,
                    'total_linha' => 45.00,
                ],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['centro_custo_id']);

        $this->assertNotEmpty($response->json('errors.centro_custo_id.0'));
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

    public function test_liquidar_movimento_allows_manual_cash_without_receipt_number(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Atleta Receita',
            'nif' => '245678901',
        ]);
        $movement = Movement::query()->create([
            'user_id' => $member->id,
            'nome_manual' => 'Patrocinio local',
            'classificacao' => 'receita',
            'categoria' => 'patrocinio',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 125.00,
            'estado_pagamento' => 'pendente',
            'estado_conciliacao' => 'nao_conciliado',
            'tipo' => 'patrocinio',
        ]);

        $cashMethod = PaymentMethod::query()->where('codigo', 'dinheiro')->firstOrFail();

        $response = $this->actingAs($admin)->postJson(route('financeiro.movimentos.liquidar', $movement->id), [
            'metodo_pagamento' => $cashMethod->codigo,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('movimento.estado_pagamento', 'pago')
            ->assertJsonPath('movimento.numero_recibo', null)
            ->assertJsonPath('payment.method', $cashMethod->codigo)
            ->assertJsonPath('payment.bank_statement_id', null);

        $financialEntryId = (string) $response->json('lancamento.id');

        $movement->refresh();

        $this->assertSame('pago', $movement->estado_pagamento);
        $this->assertNull($movement->numero_recibo);
        $this->assertDatabaseHas('fiscal_document_requests', [
            'financial_entry_id' => $financialEntryId,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
        ]);
    }

    public function test_liquidar_movimento_requires_bank_statement_for_bank_method_and_rejects_inactive_method(): void
    {
        $admin = User::factory()->admin()->create();
        $movement = Movement::query()->create([
            'nome_manual' => 'Quota evento',
            'classificacao' => 'receita',
            'categoria' => 'evento',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 80.00,
            'estado_pagamento' => 'pendente',
            'estado_conciliacao' => 'nao_conciliado',
            'tipo' => 'evento',
        ]);

        $transferMethod = PaymentMethod::query()->where('codigo', 'transferencia')->firstOrFail();
        $inactiveMethod = PaymentMethod::query()->create([
            'codigo' => 'movimentos-inativo',
            'nome' => 'Movimentos Inativo',
            'descricao' => 'Metodo desativado para teste',
            'requer_linha_bancaria' => false,
            'ativo' => false,
            'ordem' => 999,
        ]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.movimentos.liquidar', $movement->id), [
                'metodo_pagamento' => $transferMethod->codigo,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bank_statement_id']);

        $this->actingAs($admin)
            ->postJson(route('financeiro.movimentos.liquidar', $movement->id), [
                'metodo_pagamento' => $inactiveMethod->codigo,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['method']);
    }

    public function test_liquidar_movimento_with_bank_statement_reconciles_expense_without_fiscal_request(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = CostCenter::query()->firstOrFail();
        $movement = Movement::query()->create([
            'nome_manual' => 'Fornecedor gas',
            'classificacao' => 'despesa',
            'categoria' => 'gas',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -55.00,
            'estado_pagamento' => 'pendente',
            'estado_conciliacao' => 'nao_conciliado',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
        ]);

        $statement = BankStatement::query()->create([
            'data_movimento' => now()->toDateString(),
            'descricao' => 'Transferencia fornecedor gas',
            'valor' => -55.00,
            'referencia' => 'TRX-GAS-1',
            'centro_custo_id' => $costCenter->id,
            'conciliado' => false,
        ]);

        $transferMethod = PaymentMethod::query()->where('codigo', 'transferencia')->firstOrFail();

        $response = $this->actingAs($admin)->postJson(route('financeiro.movimentos.liquidar', $movement->id), [
            'metodo_pagamento' => $transferMethod->codigo,
            'bank_statement_id' => $statement->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('movimento.estado_pagamento', 'pago')
            ->assertJsonPath('movimento.estado_conciliacao', 'conciliado')
            ->assertJsonPath('payment.method', $transferMethod->codigo)
            ->assertJsonPath('payment.bank_statement_id', $statement->id);

        $financialEntryId = (string) $response->json('lancamento.id');

        $movement->refresh();
        $statement->refresh();

        $this->assertSame('pago', $movement->estado_pagamento);
        $this->assertSame('conciliado', $movement->estado_conciliacao);
        $this->assertTrue((bool) $statement->conciliado);
        $this->assertDatabaseMissing('fiscal_document_requests', [
            'financial_entry_id' => $financialEntryId,
        ]);
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
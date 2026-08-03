<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementDocumentRequirement;
use App\Models\MovementItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MovementShowFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        Storage::fake('public');
    }

    public function test_opening_movement_detail_returns_movement_items_documents_and_conciliation(): void
    {
        $admin = User::factory()->admin()->create();
        [$movement, $entry, $statement] = $this->createPaidExpenseMovement();

        MovementItem::query()->create([
            'movimento_id' => $movement->id,
            'descricao' => 'Piscina municipal',
            'quantidade' => 1,
            'valor_unitario' => 120,
            'imposto_percentual' => 23,
            'total_linha' => 120,
            'centro_custo_id' => $movement->centro_custo_id,
        ]);

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'supplier_id' => $movement->supplier_id,
            'document_type' => 'invoice',
            'document_number' => 'FAC-DET-1',
            'issue_date' => now()->toDateString(),
            'amount' => 120,
            'status' => 'valid',
        ]);

        MapaConciliacao::query()->create([
            'extrato_id' => $statement->id,
            'lancamento_id' => $entry->id,
            'movimento_id' => $movement->id,
            'valor_conciliado' => 120,
            'status' => 'confirmed',
            'regra_usada' => 'bank_statement_settlement',
        ]);

        $response = $this->actingAs($admin)->getJson(route('financeiro.movimentos.show', $movement));

        $response->assertOk()
            ->assertJsonPath('movement.id', $movement->id)
            ->assertJsonPath('movement.items.0.descricao', 'Piscina municipal')
            ->assertJsonPath('movement.documents.0.document_number', 'FAC-DET-1')
            ->assertJsonPath('movement.conciliation.bank_statement.id', $statement->id)
            ->assertJsonPath('movement.conciliation.reconciliation_map.id', MapaConciliacao::query()->firstOrFail()->id)
            ->assertJsonPath('movement.conciliation.reconciliation_map.regra_usada', 'bank_statement_settlement');
    }

    public function test_attaching_invoice_recalculates_document_state(): void
    {
        $admin = User::factory()->admin()->create();
        [$movement] = $this->createPaidExpenseMovement();

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'bank_statement_line',
            'status' => 'valid',
            'amount' => 120,
        ]);

        $movement->refresh();
        $this->assertSame('falta_fatura', $movement->estado_documental);

        $response = $this->actingAs($admin)->post(route('financeiro.movimentos.documents.store', $movement), [
            'document_type' => 'invoice',
            'document_number' => 'FAC-ATT-1',
            'issue_date' => now()->toDateString(),
            'amount' => 120,
            'file' => UploadedFile::fake()->create('fatura.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertOk()
            ->assertJsonPath('movement.estado_documental', 'pendente_validacao');
    }

    public function test_attaching_invoice_receipt_satisfies_invoice_and_receipt(): void
    {
        $admin = User::factory()->admin()->create();
        [$movement] = $this->createPaidExpenseMovement([
            'categoria' => 'contabilidade',
        ]);

        MovementDocumentRequirement::query()->create([
            'movement_classification' => 'despesa',
            'category' => 'contabilidade',
            'requires_invoice' => true,
            'requires_receipt' => true,
            'requires_payment_proof' => false,
        ]);

        $response = $this->actingAs($admin)->post(route('financeiro.movimentos.documents.store', $movement), [
            'document_type' => 'invoice_receipt',
            'document_number' => 'FR-1',
            'issue_date' => now()->toDateString(),
            'amount' => 120,
        ], ['Accept' => 'application/json']);

        $documentId = $response->json('document.id');

        $this->actingAs($admin)->patchJson(route('financeiro.movimentos.documents.validate', [$movement, $documentId]));

        $movement->refresh();
        $this->assertSame('completo', $movement->estado_documental);
    }

    public function test_validating_document_sets_status_to_valid(): void
    {
        $admin = User::factory()->admin()->create();
        [$movement] = $this->createPaidExpenseMovement();

        $document = MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'invoice',
            'status' => 'pending_validation',
            'amount' => 120,
        ]);

        $response = $this->actingAs($admin)->patchJson(route('financeiro.movimentos.documents.validate', [$movement, $document]), [
            'notes' => 'Documento validado pela tesouraria.',
        ]);

        $response->assertOk()
            ->assertJsonPath('document.status', 'valid');

        $this->assertDatabaseHas('movement_documents', [
            'id' => $document->id,
            'status' => 'valid',
            'validated_by' => $admin->id,
        ]);
    }

    public function test_rejected_document_does_not_count_for_document_control(): void
    {
        $admin = User::factory()->admin()->create();
        [$movement] = $this->createPaidExpenseMovement();

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'bank_statement_line',
            'status' => 'valid',
            'amount' => 120,
        ]);

        $document = MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'invoice',
            'status' => 'pending_validation',
            'amount' => 120,
        ]);

        $this->actingAs($admin)->patchJson(route('financeiro.movimentos.documents.reject', [$movement, $document]), [
            'notes' => 'Documento duplicado do fornecedor errado.',
        ])->assertOk();

        $movement->refresh();
        $this->assertSame('falta_fatura', $movement->estado_documental);
    }

    public function test_paid_movement_without_receipt_keeps_alert_until_receipt_is_attached(): void
    {
        $admin = User::factory()->admin()->create();
        [$movement] = $this->createPaidExpenseMovement([
            'categoria' => 'seguros',
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
            'amount' => 120,
        ]);

        $movement->refresh();
        $this->assertSame('falta_recibo', $movement->estado_documental);

        $invoiceResponse = $this->actingAs($admin)->post(route('financeiro.movimentos.documents.store', $movement), [
            'document_type' => 'invoice',
            'document_number' => 'FAC-EXTRA',
            'issue_date' => now()->toDateString(),
            'amount' => 120,
        ], ['Accept' => 'application/json']);

        $this->actingAs($admin)->patchJson(route('financeiro.movimentos.documents.validate', [$movement, $invoiceResponse->json('document.id')]));
        $movement->refresh();
        $this->assertSame('falta_recibo', $movement->estado_documental);

        $receiptResponse = $this->actingAs($admin)->post(route('financeiro.movimentos.documents.store', $movement), [
            'document_type' => 'receipt',
            'document_number' => 'REC-1',
            'issue_date' => now()->toDateString(),
            'amount' => 120,
        ], ['Accept' => 'application/json']);

        $this->actingAs($admin)->patchJson(route('financeiro.movimentos.documents.validate', [$movement, $receiptResponse->json('document.id')]));

        $movement->refresh();
        $this->assertSame('completo', $movement->estado_documental);
    }

    /**
     * @return array{0: Movement, 1: FinancialEntry, 2: BankStatement}
     */
    private function createPaidExpenseMovement(array $overrides = []): array
    {
        $costCenter = CostCenter::query()->firstOrFail();
        $supplier = Supplier::query()->create([
            'nome' => 'Fornecedor Detalhe',
            'nif' => '509111222',
            'ativo' => true,
        ]);

        $movement = Movement::query()->create(array_merge([
            'supplier_id' => $supplier->id,
            'nome_manual' => $supplier->nome,
            'classificacao' => 'despesa',
            'categoria' => 'servicos',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -120,
            'estado_pagamento' => 'pago',
            'estado_conciliacao' => 'conciliado',
            'estado_documental' => 'falta_fatura',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'observacoes' => 'Movimento teste detalhe',
        ], $overrides));

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Serviços',
            'descricao' => 'Movimento teste detalhe',
            'documento_ref' => 'REF-DET-1',
            'valor' => 120,
            'valor_pago' => 120,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'centro_custo_id' => $costCenter->id,
            'origem_tipo' => 'movement',
            'origem_id' => $movement->id,
        ]);

        $statement = BankStatement::query()->create([
            'data_movimento' => now()->toDateString(),
            'descricao' => 'Transferência fornecedor detalhe',
            'valor' => -120,
            'referencia' => 'TRX-DET-1',
            'centro_custo_id' => $costCenter->id,
            'conciliado' => true,
            'conciliacao_status' => 'reconciled',
            'valor_conciliado' => 120,
            'valor_por_conciliar' => 0,
            'lancamento_id' => $entry->id,
        ]);

        $entry->forceFill(['bank_statement_id' => $statement->id])->save();

        return [$movement->fresh(), $entry->fresh(), $statement->fresh()];
    }
}

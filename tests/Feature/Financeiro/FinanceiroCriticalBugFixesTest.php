<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FiscalDocumentRequest;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FinanceiroCriticalBugFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unreconciled_bank_statement_search_works_without_postgresql_ilike_dependency(): void
    {
        $admin = User::factory()->admin()->create();

        $statement = BankStatement::query()->create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-20',
            'descricao' => 'Mensalidade Maio Familia Silva',
            'valor' => 45.00,
            'saldo' => 1000.00,
            'referencia' => 'REF-MAIO',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 45.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $this->actingAs($admin)
            ->getJson(route('financeiro.bank-statements.unreconciled', ['search' => 'familia silva']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $statement->id);
    }

    public function test_bank_reconciliation_suggestion_search_does_not_fail_with_operator_closure_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create([
            'nome_completo' => 'Joana Pesquisa',
            'numero_socio' => '9001',
            'nif' => '123456789',
        ]);

        $statement = BankStatement::query()->create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-20',
            'descricao' => 'Transferencia Joana Pesquisa',
            'valor' => 45.00,
            'saldo' => 1000.00,
            'referencia' => 'REF-SUG-1',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 45.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $suggestion = BankReconciliationSuggestion::query()->create([
            'bank_statement_id' => $statement->id,
            'user_id' => $user->id,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
            'score' => 92,
            'confidence_label' => BankReconciliationSuggestion::CONFIDENCE_HIGH,
            'total_bank_amount' => 45.00,
            'total_allocated_amount' => 45.00,
            'unallocated_amount' => 0,
            'suggested_allocations' => [],
            'matched_rules' => ['matched_name'],
            'explanation' => 'Sugestao Joana Pesquisa',
        ]);

        $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-suggestions.index').'?search=joana')
            ->assertOk()
            ->assertJsonPath('data.0.id', $suggestion->id);
    }

    public function test_receipt_import_requires_zip_when_pending_directory_is_not_enabled(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('financeiro.receipt-imports.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['zip_file'])
            ->assertJsonPath('errors.zip_file.0', 'Envie um ficheiro ZIP ou ative a importacao pela diretoria pendente.');
    }

    public function test_it_allows_deleting_a_clean_pending_invoice(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createInvoice();

        $this->actingAs($admin)
            ->postJson(route('financeiro.destroy.post', $invoice))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    }

    public function test_it_blocks_deleting_a_paid_invoice(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createInvoice([
            'estado_pagamento' => 'pago',
            'valor_pago' => 45.00,
            'valor_em_aberto' => 0,
        ]);

        $this->assertDeleteBlocked($admin, $invoice);
    }

    public function test_it_blocks_deleting_a_partial_invoice(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createInvoice([
            'estado_pagamento' => 'parcial',
            'valor_pago' => 10.00,
            'valor_em_aberto' => 35.00,
        ]);

        $this->assertDeleteBlocked($admin, $invoice);
    }

    public function test_it_blocks_deleting_an_invoice_with_payment_allocation(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createInvoice();
        $payment = Payment::query()->create([
            'amount' => 10.00,
            'allocated_amount' => 10.00,
            'unallocated_amount' => 0,
            'payment_date' => '2026-05-20',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 10.00,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $this->assertDeleteBlocked($admin, $invoice);
    }

    public function test_it_blocks_deleting_an_invoice_with_fiscal_document_request(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createInvoice();

        FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 45.00,
        ]);

        $this->assertDeleteBlocked($admin, $invoice);
    }

    public function test_it_blocks_deleting_an_invoice_with_receipt_number_or_external_document_trace(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createInvoice([
            'numero_recibo' => 'RC-900',
        ]);

        $this->assertDeleteBlocked($admin, $invoice);
    }

    public function test_manual_invoice_creation_preserves_non_monthly_type(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $costCenter = $this->createCostCenter();

        InvoiceType::query()->firstOrCreate(
            ['codigo' => 'material'],
            ['nome' => 'Material', 'ativo' => true],
        );

        $response = $this->actingAs($admin)->postJson(route('financeiro.store'), [
            'user_id' => $user->id,
            'data_emissao' => '2026-05-20',
            'data_vencimento' => '2026-05-30',
            'data_fatura' => '2026-05-20',
            'tipo' => 'material',
            'valor_total' => 25.00,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'items' => [
                [
                    'descricao' => 'Touca',
                    'quantidade' => 1,
                    'valor_unitario' => 25.00,
                    'imposto_percentual' => 0,
                    'total_linha' => 25.00,
                    'centro_custo_id' => $costCenter->id,
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('invoice.tipo', 'material');

        $invoiceId = $response->json('invoice.id');
        $invoice = Invoice::query()->findOrFail($invoiceId);

        $this->assertSame('material', $invoice->tipo);
    }

    public function test_non_monthly_invoice_does_not_use_monthly_special_status_flow(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createInvoice([
            'tipo' => 'material',
        ]);

        InvoiceType::query()->firstOrCreate(
            ['codigo' => 'material'],
            ['nome' => 'Material', 'ativo' => true],
        );

        $this->actingAs($admin)
            ->putJson(route('financeiro.update', $invoice), [
                'user_id' => $invoice->user_id,
                'data_emissao' => '2026-05-01',
                'data_vencimento' => '2026-05-10',
                'data_fatura' => '2026-05-01',
                'tipo' => 'material',
                'valor_total' => 45.00,
                'estado_pagamento' => 'pago',
                'centro_custo_id' => $invoice->centro_custo_id,
                'items' => [
                    [
                        'descricao' => 'Material',
                        'quantidade' => 1,
                        'valor_unitario' => 45.00,
                        'imposto_percentual' => 0,
                        'total_linha' => 45.00,
                        'centro_custo_id' => $invoice->centro_custo_id,
                    ],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['estado_pagamento'])
            ->assertJsonPath('errors.estado_pagamento.0', 'A liquidacao da fatura tem de ser efetuada pelo fluxo de pagamento.');
    }

    public function test_open_movements_returns_latest_financial_entry_for_each_movement(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = $this->createCostCenter();

        [$movementA, $latestEntryA] = $this->createMovementWithEntries($costCenter, 'Material A', 10.00, 35.00);
        [$movementB, $latestEntryB] = $this->createMovementWithEntries($costCenter, 'Material B', 5.00, 20.00);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.movements.open'))
            ->assertOk();

        $payload = collect($response->json('data'))->keyBy('id');

        $this->assertSame($latestEntryA->id, $payload[$movementA->id]['financial_entry_id']);
        $this->assertSame('35', (string) $payload[$movementA->id]['valor_em_aberto']);
        $this->assertSame($latestEntryB->id, $payload[$movementB->id]['financial_entry_id']);
        $this->assertSame('20', (string) $payload[$movementB->id]['valor_em_aberto']);
    }

    private function assertDeleteBlocked(User $admin, Invoice $invoice): void
    {
        $this->actingAs($admin)
            ->postJson(route('financeiro.destroy.post', $invoice))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice'])
            ->assertJsonPath('errors.invoice.0', 'A fatura tem rasto financeiro ou fiscal. Deve ser cancelada/anulada, nao apagada.');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
    }

    private function createInvoice(array $overrides = []): Invoice
    {
        $user = User::factory()->create();
        $costCenter = $this->createCostCenter();

        $invoice = Invoice::query()->create(array_merge([
            'user_id' => $user->id,
            'data_fatura' => '2026-05-01',
            'mes' => '2026-05',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-10',
            'valor_total' => 45.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 45.00,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'mensalidade',
        ], $overrides));

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Linha teste',
            'quantidade' => 1,
            'valor_unitario' => 45.00,
            'imposto_percentual' => 0,
            'total_linha' => 45.00,
            'centro_custo_id' => $costCenter->id,
        ]);

        return $invoice;
    }

    private function createCostCenter(): CostCenter
    {
        return CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-F2-TEST'],
            ['nome' => 'Centro Teste F2', 'tipo' => 'departamento', 'ativo' => true],
        );
    }

    /**
     * @return array{0: Movement, 1: FinancialEntry}
     */
    private function createMovementWithEntries(CostCenter $costCenter, string $description, float $olderOpenAmount, float $latestOpenAmount): array
    {
        $movement = Movement::query()->create([
            'nome_manual' => $description,
            'classificacao' => 'despesa',
            'categoria' => 'material',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-10',
            'valor_total' => 45.00,
            'estado_pagamento' => 'parcial',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'material',
            'origem_tipo' => 'manual',
            'observacoes' => $description,
        ]);

        $olderEntry = FinancialEntry::query()->create([
            'data' => '2026-05-01',
            'tipo' => 'despesa',
            'categoria' => 'Material',
            'descricao' => $description.' antiga',
            'documento_ref' => 'OLD-'.$movement->id,
            'valor' => 45.00,
            'valor_pago' => 45.00 - $olderOpenAmount,
            'valor_em_aberto' => $olderOpenAmount,
            'estado' => 'parcial',
            'centro_custo_id' => $costCenter->id,
            'origem_tipo' => 'movement',
            'origem_id' => $movement->id,
        ]);
        $olderEntry->forceFill([
            'created_at' => Carbon::parse('2026-05-01 10:00:00'),
            'updated_at' => Carbon::parse('2026-05-01 10:00:00'),
        ])->save();

        $latestEntry = FinancialEntry::query()->create([
            'data' => '2026-05-02',
            'tipo' => 'despesa',
            'categoria' => 'Material',
            'descricao' => $description.' recente',
            'documento_ref' => 'NEW-'.$movement->id,
            'valor' => 45.00,
            'valor_pago' => 45.00 - $latestOpenAmount,
            'valor_em_aberto' => $latestOpenAmount,
            'estado' => 'parcial',
            'centro_custo_id' => $costCenter->id,
            'origem_tipo' => 'movement',
            'origem_id' => $movement->id,
        ]);
        $latestEntry->forceFill([
            'created_at' => Carbon::parse('2026-05-02 10:00:00'),
            'updated_at' => Carbon::parse('2026-05-02 10:00:00'),
        ])->save();

        return [$movement->fresh(), $latestEntry->fresh()];
    }
}
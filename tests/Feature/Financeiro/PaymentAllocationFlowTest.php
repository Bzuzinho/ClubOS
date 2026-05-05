<?php

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAllocationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_pays_an_invoice_in_full_without_receipt_number_and_creates_fiscal_request(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([100.00]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 100.00,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'TRX-100',
            'notes' => 'Pagamento integral sem recibo fiscal.',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 100.00],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.all_paid', true)
            ->assertJsonPath('summary.has_partial_invoice', false)
            ->assertJsonPath('summary.created_credit', false);

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
        $this->assertSame('100.00', $invoice->valor_pago);
        $this->assertNull($invoice->numero_recibo);

        $this->assertDatabaseHas('payments', [
            'reference' => 'TRX-100',
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('fiscal_document_requests', [
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'external_document_number' => null,
        ]);
    }

    public function test_it_records_partial_payment_without_creating_fiscal_request(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([100.00]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 40.00,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 40.00],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.all_paid', false)
            ->assertJsonPath('summary.has_partial_invoice', true);

        $invoice->refresh();

        $this->assertSame('parcial', $invoice->estado_pagamento);
        $this->assertSame('40.00', $invoice->valor_pago);
        $this->assertSame('60.00', $invoice->valor_em_aberto);
        $this->assertDatabaseCount('fiscal_document_requests', 0);
    }

    public function test_two_payments_complete_the_same_invoice_and_only_then_create_fiscal_request(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([100.00]);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 40.00,
            'payment_date' => '2026-05-05',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 40.00],
            ],
        ])->assertOk();

        $this->assertDatabaseCount('fiscal_document_requests', 0);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 60.00,
            'payment_date' => '2026-05-06',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 60.00],
            ],
        ])->assertOk();

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertDatabaseCount('fiscal_document_requests', 1);
    }

    public function test_one_bank_statement_can_pay_multiple_invoices(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoiceA, $invoiceB] = $this->createInvoicesForUser([30.00, 20.00]);
        $statement = $this->createBankStatement(50.00);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'amount' => 30.00],
                ['invoice_id' => $invoiceB->id, 'amount' => 20.00],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.bank_statement_reconciled', true);

        $statement->refresh();

        $this->assertTrue($statement->conciliado);
        $this->assertSame('reconciled', $statement->conciliacao_status);
        $this->assertSame('50.00', $statement->valor_conciliado);
        $this->assertDatabaseCount('payment_allocations', 2);
    }

    public function test_one_bank_statement_can_generate_account_credit_from_overpayment(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $statement = $this->createBankStatement(50.00);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'create_credit' => true,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 30.00],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.created_credit', true)
            ->assertJsonPath('summary.bank_statement_reconciled', true);

        $statement->refresh();

        $this->assertDatabaseHas('account_credits', [
            'payment_id' => Payment::query()->value('id'),
            'amount' => 20.00,
            'remaining_amount' => 20.00,
            'status' => AccountCredit::STATUS_AVAILABLE,
        ]);
        $this->assertTrue($statement->conciliado);
        $this->assertSame('reconciled', $statement->conciliacao_status);
        $this->assertSame('0.00', $statement->valor_por_conciliar);
    }

    public function test_partially_allocated_bank_statement_stays_partial_when_credit_is_not_created(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $statement = $this->createBankStatement(50.00);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'create_credit' => false,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 30.00],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.bank_statement_reconciled', false)
            ->assertJsonPath('summary.bank_statement_partial', true);

        $statement->refresh();

        $this->assertFalse($statement->conciliado);
        $this->assertSame('partial', $statement->conciliacao_status);
        $this->assertSame('30.00', $statement->valor_conciliado);
        $this->assertSame('20.00', $statement->valor_por_conciliar);
        $this->assertDatabaseCount('account_credits', 0);
    }

    public function test_it_rejects_allocations_above_the_payment_amount(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([100.00]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 50.00,
            'payment_date' => '2026-05-05',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 60.00],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('allocations');
    }

    public function test_it_rejects_allocations_above_the_invoice_outstanding_amount(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([40.00]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 50.00,
            'payment_date' => '2026-05-05',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 50.00],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('allocations');
    }

    public function test_it_rejects_reusing_a_fully_reconciled_bank_statement(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoiceA, $invoiceB] = $this->createInvoicesForUser([50.00, 10.00]);
        $statement = $this->createBankStatement(50.00);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'amount' => 50.00],
            ],
        ])->assertOk();

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'allocations' => [
                ['invoice_id' => $invoiceB->id, 'amount' => 10.00],
            ],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_statement_id');
    }

    public function test_receipt_number_is_not_required_or_populated_when_registering_payment(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 25.00,
            'payment_date' => '2026-05-05',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 25.00],
            ],
        ]);

        $response->assertOk();

        $invoice->refresh();

        $this->assertNull($invoice->numero_recibo);
    }

    /**
     * @return array<int, Invoice>
     */
    private function createInvoicesForUser(array $amounts): array
    {
        $user = User::factory()->create([
            'nome_completo' => 'Socio Pagamentos',
            'nif' => '123456789',
            'morada' => 'Rua Principal 1',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa',
            'email' => 'pagamentos@example.com',
        ]);

        $costCenter = CostCenter::create([
            'codigo' => 'CC-PAGAMENTOS',
            'nome' => 'Centro Pagamentos',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        return collect($amounts)->values()->map(function (float $amount, int $index) use ($user, $costCenter) {
            $invoice = Invoice::create([
                'user_id' => $user->id,
                'data_fatura' => '2026-05-01',
                'data_emissao' => '2026-05-01',
                'data_vencimento' => '2026-05-10',
                'valor_total' => $amount,
                'estado_pagamento' => 'pendente',
                'numero_recibo' => null,
                'referencia_pagamento' => sprintf('REF-%02d', $index + 1),
                'centro_custo_id' => $costCenter->id,
                'tipo' => 'mensalidade',
                'observacoes' => null,
            ]);

            InvoiceItem::create([
                'fatura_id' => $invoice->id,
                'descricao' => sprintf('Mensalidade %d', $index + 1),
                'quantidade' => 1,
                'valor_unitario' => $amount,
                'imposto_percentual' => 0,
                'total_linha' => $amount,
                'centro_custo_id' => $costCenter->id,
            ]);

            return $invoice;
        })->all();
    }

    private function createBankStatement(float $amount): BankStatement
    {
        return BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-05',
            'descricao' => 'Transferencia recebida',
            'valor' => $amount,
            'saldo' => 1000.00,
            'referencia' => sprintf('TRX-%s', number_format($amount, 2, '', '')),
            'conciliado' => false,
        ]);
    }
}
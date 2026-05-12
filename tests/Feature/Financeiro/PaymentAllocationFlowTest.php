<?php

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FiscalDocumentRequest;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\InvoiceType;
use App\Services\Financeiro\FinancialSettlementService;
use App\Services\Financeiro\PaymentAllocationService;
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

    public function test_bulk_bank_statement_import_recalculates_running_balances_by_movement_date(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = $this->createCostCenter();

        $response = $this->actingAs($admin)->postJson(route('financeiro.extratos.bulk'), [
            'extratos' => [
                [
                    'conta' => 'PT50-0001',
                    'data_movimento' => '2026-02-11',
                    'descricao' => 'Movimento 2',
                    'valor' => -1537.50,
                    'saldo' => 0,
                    'centro_custo_id' => $costCenter->id,
                ],
                [
                    'conta' => 'PT50-0001',
                    'data_movimento' => '2026-02-10',
                    'descricao' => 'Movimento 1',
                    'valor' => 40.00,
                    'saldo' => 0,
                    'centro_custo_id' => $costCenter->id,
                ],
                [
                    'conta' => 'PT50-0001',
                    'data_movimento' => '2026-02-12',
                    'descricao' => 'Movimento 3',
                    'valor' => 30.00,
                    'saldo' => 0,
                    'centro_custo_id' => $costCenter->id,
                ],
            ],
        ]);

        $response->assertOk();

        $statements = BankStatement::query()
            ->orderBy('data_movimento')
            ->get();

        $this->assertSame('40.00', $statements[0]->saldo);
        $this->assertSame('-1497.50', $statements[1]->saldo);
        $this->assertSame('-1467.50', $statements[2]->saldo);
    }

    public function test_updating_bank_statement_recalculates_balances_and_returns_updated_collection(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = $this->createCostCenter();

        $first = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-02-10',
            'descricao' => 'Primeiro',
            'valor' => 40.00,
            'saldo' => 40.00,
            'centro_custo_id' => $costCenter->id,
            'conciliado' => false,
        ]);
        $second = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-02-11',
            'descricao' => 'Segundo',
            'valor' => 30.00,
            'saldo' => 70.00,
            'centro_custo_id' => $costCenter->id,
            'conciliado' => false,
        ]);

        $response = $this->actingAs($admin)->putJson(route('financeiro.extratos.update', $second), [
            'data_movimento' => '2026-02-09',
            'descricao' => 'Segundo editado',
            'valor' => 30.00,
            'saldo' => 999.00,
            'referencia' => 'REF-2',
            'centro_custo_id' => $costCenter->id,
        ]);

        $response->assertOk();

        $first->refresh();
        $second->refresh();

        $this->assertSame('30.00', $second->saldo);
        $this->assertSame('70.00', $first->saldo);
        $this->assertSame('30.00', (string) collect($response->json('extratos'))->firstWhere('id', $second->id)['saldo']);
        $this->assertSame('70.00', (string) collect($response->json('extratos'))->firstWhere('id', $first->id)['saldo']);
    }

    public function test_it_can_manually_catalog_a_bank_statement_without_selecting_invoice_or_movement(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = $this->createCostCenter();
        $statement = $this->createBankStatement(15062.16);

        $response = $this->actingAs($admin)->postJson(route('financeiro.extratos.conciliar', $statement), [
            'tipo' => 'receita',
            'centro_custo_id' => $costCenter->id,
            'user_id' => $admin->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('extrato.conciliado', true)
            ->assertJsonPath('extrato.conciliacao_status', 'reconciled');

        $statement->refresh();

        $this->assertTrue($statement->conciliado);
        $this->assertSame('reconciled', $statement->conciliacao_status);
        $this->assertSame('15062.16', $statement->valor_conciliado);
        $this->assertSame('0.00', $statement->valor_por_conciliar);
        $this->assertNotNull($statement->lancamento_id);

        $this->assertDatabaseHas('financial_entries', [
            'id' => $statement->lancamento_id,
            'centro_custo_id' => $costCenter->id,
            'user_id' => $admin->id,
            'fatura_id' => null,
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'valor' => 15062.16,
        ]);
        $this->assertDatabaseHas('mapa_conciliacao', [
            'extrato_id' => $statement->id,
            'lancamento_id' => $statement->lancamento_id,
            'fatura_id' => null,
            'movimento_id' => null,
            'status' => 'confirmado',
            'regra_usada' => 'manual',
            'valor_conciliado' => 15062.16,
        ]);
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

    public function test_it_accepts_payment_for_pending_invoice_with_stale_tracked_paid_amount(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);

        $invoice->forceFill([
            'estado_pagamento' => 'vencido',
            'valor_pago' => 25.00,
            'valor_em_aberto' => 0,
        ])->save();

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 25.00,
            'payment_date' => '2026-05-05',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 25.00],
            ],
        ]);

        $response->assertOk();

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertSame('25.00', $invoice->valor_pago);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
    }

    public function test_it_reverses_manual_payment_tracking_when_invoice_is_changed_back_to_overdue(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createInvoiceType();
        [$invoice] = $this->createInvoicesForUser([25.00]);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 25.00,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'TRX-REVERSAO',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 25.00],
            ],
        ])->assertOk();

        $payment = Payment::query()->where('reference', 'TRX-REVERSAO')->firstOrFail();
        $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();

        $response = $this->actingAs($admin)->putJson(route('financeiro.update', $invoice), [
            'user_id' => $invoice->user_id,
            'data_fatura' => optional($invoice->data_fatura)->toDateString(),
            'data_emissao' => $invoice->data_emissao->toDateString(),
            'data_vencimento' => $invoice->data_vencimento->toDateString(),
            'mes' => $invoice->mes,
            'tipo' => $invoice->tipo,
            'estado_pagamento' => 'vencido',
            'valor_total' => (float) $invoice->valor_total,
            'oculta' => false,
            'centro_custo_id' => $invoice->centro_custo_id,
            'numero_recibo' => $invoice->numero_recibo,
            'referencia_pagamento' => $invoice->referencia_pagamento,
            'origem_tipo' => $invoice->origem_tipo,
            'origem_id' => $invoice->origem_id,
            'observacoes' => $invoice->observacoes,
            'items' => [[
                'descricao' => 'Mensalidade 1',
                'quantidade' => 1,
                'valor_unitario' => 25.00,
                'imposto_percentual' => 0,
                'total_linha' => 25.00,
                'produto_id' => null,
                'centro_custo_id' => $invoice->centro_custo_id,
            ]],
        ]);

        $response->assertOk();

        $invoice->refresh();
        $payment->refresh();

        $this->assertSame('vencido', $invoice->estado_pagamento);
        $this->assertSame('0.00', $invoice->valor_pago);
        $this->assertSame('25.00', $invoice->valor_em_aberto);
        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
        $this->assertFalse(PaymentAllocation::query()->whereKey($allocation->id)->exists());
        $this->assertTrue(PaymentAllocation::withTrashed()->whereKey($allocation->id)->where('status', PaymentAllocation::STATUS_CANCELLED)->exists());
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertDatabaseCount('mapa_conciliacao', 0);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 25.00,
            'payment_date' => '2026-05-06',
            'method' => 'transferencia',
            'reference' => 'TRX-CORRIGIDO',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 25.00],
            ],
        ])->assertOk();

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertSame('25.00', $invoice->valor_pago);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
    }

    public function test_it_auto_repairs_stale_manual_allocations_before_registering_a_corrected_payment(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 25.00,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'TRX-STUCK-OLD',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 25.00],
            ],
        ])->assertOk();

        $oldPayment = Payment::query()->where('reference', 'TRX-STUCK-OLD')->firstOrFail();
        $oldAllocation = PaymentAllocation::query()->where('payment_id', $oldPayment->id)->firstOrFail();

        $invoice->forceFill([
            'estado_pagamento' => 'vencido',
            'valor_pago' => 25.00,
            'valor_em_aberto' => 0,
        ])->save();

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 25.00,
            'payment_date' => '2026-05-06',
            'method' => 'transferencia',
            'reference' => 'TRX-STUCK-NEW',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 25.00],
            ],
        ]);

        $response->assertOk();

        $invoice->refresh();
        $oldPayment->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertSame('25.00', $invoice->valor_pago);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
        $this->assertSame(Payment::STATUS_CANCELLED, $oldPayment->status);
        $this->assertTrue(PaymentAllocation::withTrashed()->whereKey($oldAllocation->id)->where('status', PaymentAllocation::STATUS_CANCELLED)->exists());
        $this->assertDatabaseHas('payments', [
            'reference' => 'TRX-STUCK-NEW',
            'status' => Payment::STATUS_CONFIRMED,
        ]);
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

    public function test_liquidating_revenue_movement_uses_canonical_settlement_and_creates_fiscal_request(): void
    {
        $entry = $this->createStandaloneFinancialEntry('receita', 75.00, true);

        $result = app(FinancialSettlementService::class)->settleFinancialEntry($entry, [
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'REC-MOV-001',
            'created_by' => User::factory()->admin()->create()->id,
        ]);

        $entry = $result['financial_entry'];

        $this->assertSame('pago', $entry->estado);
        $this->assertSame('75.00', $entry->valor_pago);
        $this->assertSame('0.00', $entry->valor_em_aberto);
        $this->assertSame('2026-05-05', optional($entry->data_liquidacao)->toDateString());
        $this->assertDatabaseHas('payment_allocations', [
            'financial_entry_id' => $entry->id,
            'invoice_id' => null,
            'amount' => 75.00,
        ]);
        $this->assertDatabaseHas('fiscal_document_requests', [
            'financial_entry_id' => $entry->id,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
        ]);
    }

    public function test_liquidating_expense_movement_uses_canonical_settlement_without_fiscal_request(): void
    {
        $entry = $this->createStandaloneFinancialEntry('despesa', 42.50, false);

        $result = app(FinancialSettlementService::class)->settleFinancialEntry($entry, [
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'REC-MOV-002',
            'created_by' => User::factory()->admin()->create()->id,
        ]);

        $entry = $result['financial_entry'];

        $this->assertSame('pago', $entry->estado);
        $this->assertDatabaseHas('payment_allocations', [
            'financial_entry_id' => $entry->id,
            'invoice_id' => null,
            'amount' => 42.50,
        ]);
        $this->assertDatabaseMissing('fiscal_document_requests', [
            'financial_entry_id' => $entry->id,
        ]);
    }

    public function test_financial_entry_revenue_settlement_with_bank_statement_creates_reconciliation_and_updates_statement(): void
    {
        $entry = $this->createStandaloneFinancialEntry('receita', 60.00, true);
        $statement = $this->createBankStatement(60.00);

        $result = app(FinancialSettlementService::class)->settleFinancialEntry($entry, [
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'BANK-REV-001',
            'bank_statement_id' => $statement->id,
            'created_by' => User::factory()->admin()->create()->id,
        ]);

        $entry = $result['financial_entry'];
        $statement->refresh();

        $this->assertSame('pago', $entry->estado);
        $this->assertTrue($statement->conciliado);
        $this->assertSame('reconciled', $statement->conciliacao_status);
        $this->assertSame('60.00', $statement->valor_conciliado);
        $this->assertDatabaseHas('mapa_conciliacao', [
            'extrato_id' => $statement->id,
            'lancamento_id' => $entry->id,
            'valor_conciliado' => 60.00,
        ]);
    }

    public function test_partial_financial_entry_settlement_keeps_entry_and_bank_statement_partial(): void
    {
        $entry = $this->createStandaloneFinancialEntry('receita', 100.00, true);
        $statement = $this->createBankStatement(100.00);

        $result = app(FinancialSettlementService::class)->settleFinancialEntry($entry, [
            'amount' => 40.00,
            'payment_amount' => 40.00,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'BANK-PARTIAL-001',
            'bank_statement_id' => $statement->id,
            'created_by' => User::factory()->admin()->create()->id,
        ]);

        $entry = $result['financial_entry'];
        $statement->refresh();

        $this->assertSame('parcial', $entry->estado);
        $this->assertSame('40.00', $entry->valor_pago);
        $this->assertSame('60.00', $entry->valor_em_aberto);
        $this->assertFalse($statement->conciliado);
        $this->assertSame('partial', $statement->conciliacao_status);
        $this->assertSame('40.00', $statement->valor_conciliado);
        $this->assertSame('60.00', $statement->valor_por_conciliar);
    }

    public function test_monthly_invoice_settlement_continues_to_delegate_to_payment_allocation_service(): void
    {
        [$invoice] = $this->createInvoicesForUser([100.00]);
        $payment = new Payment([
            'amount' => 100.00,
            'allocated_amount' => 100.00,
            'unallocated_amount' => 0,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        $service = \Mockery::mock(PaymentAllocationService::class);
        $service->shouldReceive('createPayment')
            ->once()
            ->andReturn($payment);
        $service->shouldReceive('allocatePayment')
            ->once()
            ->with($payment, [['invoice_id' => $invoice->id, 'amount' => 100.00]], \Mockery::type('array'))
            ->andReturn($payment);

        $settlement = new FinancialSettlementService(
            $service,
            app(\App\Services\Financeiro\FinancialBalanceService::class),
            app(\App\Services\Financeiro\FiscalEmissionQueueService::class),
            app(\App\Services\Financeiro\ReconciliationRepositoryService::class),
        );

        $result = $settlement->settleInvoices([
            ['invoice_id' => $invoice->id, 'amount' => 100.00],
        ], [
            'amount' => 100.00,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
        ]);

        $this->assertSame($payment, $result);
    }

    public function test_open_invoices_endpoint_excludes_pending_invoices_with_zero_outstanding_amount(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);
        $payment = Payment::create([
            'user_id' => $invoice->user_id,
            'amount' => 25.00,
            'allocated_amount' => 25.00,
            'unallocated_amount' => 0,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'OPEN-0001',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 25.00,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $invoice->forceFill([
            'estado_pagamento' => 'pendente',
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
        ])->save();

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.invoices.open', [
                'per_page' => 50,
            ]));

        $response->assertOk();

        $invoiceIds = collect($response->json('data') ?? [])->pluck('id')->all();

        $this->assertNotContains($invoice->id, $invoiceIds);
    }

    public function test_open_invoices_endpoint_ignores_non_payment_legacy_financial_entries(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([22.50]);

        FinancialEntry::create([
            'data' => '2026-01-01',
            'tipo' => 'receita',
            'categoria' => 'Inscricao',
            'descricao' => 'Lancamento legacy que nao representa pagamento',
            'documento_ref' => 'LEGACY-ENTRY',
            'valor' => 22.50,
            'centro_custo_id' => $invoice->centro_custo_id,
            'user_id' => $invoice->user_id,
            'fatura_id' => $invoice->id,
            'origem_tipo' => 'evento',
            'origem_id' => 'legacy-event-1',
            'metodo_pagamento' => null,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.invoices.open', [
                'per_page' => 50,
            ]));

        $response->assertOk();

        $invoiceRow = collect($response->json('data') ?? [])->firstWhere('id', $invoice->id);

        $this->assertNotNull($invoiceRow);
        $this->assertSame(22.5, (float) ($invoiceRow['valor_em_aberto'] ?? 0));
    }

    public function test_open_invoices_endpoint_hides_invoices_with_confirmed_allocations_even_when_persisted_outstanding_is_stale(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([22.50]);

        $payment = Payment::create([
            'user_id' => $invoice->user_id,
            'family_id' => null,
            'bank_statement_id' => null,
            'amount' => 22.50,
            'allocated_amount' => 22.50,
            'unallocated_amount' => 0,
            'payment_date' => '2026-05-08',
            'method' => 'transferencia',
            'reference' => 'TRX-STALE-OPEN',
            'description' => 'Pagamento confirmado antigo',
            'source' => Payment::SOURCE_RECONCILIATION,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 22.50,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $invoice->forceFill([
            'estado_pagamento' => 'vencido',
            'valor_pago' => 0,
            'valor_em_aberto' => 22.50,
        ])->save();

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.invoices.open', [
                'per_page' => 50,
            ]));

        $response->assertOk();

        $invoiceIds = collect($response->json('data') ?? [])->pluck('id')->all();

        $this->assertNotContains($invoice->id, $invoiceIds);
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

    private function createInvoiceType(): void
    {
        InvoiceType::query()->firstOrCreate(
            ['codigo' => 'mensalidade'],
            [
                'nome' => 'Mensalidade',
                'descricao' => 'Mensalidade',
                'ativo' => true,
            ],
        );
    }

    private function createCostCenter(): CostCenter
    {
        return CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-BANK'],
            [
                'nome' => 'Centro Banco',
                'tipo' => 'departamento',
                'ativo' => true,
            ],
        );
    }

    private function createMovement(string $classificacao, float $amount, bool $withUser): Movement
    {
        $user = $withUser ? User::factory()->create([
            'nome_completo' => 'Movimento Canonico',
            'nif' => '987654321',
            'morada' => 'Rua do Movimento 10',
            'codigo_postal' => '2000-200',
            'localidade' => 'Santarém',
            'email' => 'movimento@example.com',
        ]) : null;
        $costCenter = $this->createCostCenter();

        return Movement::create([
            'user_id' => $user?->id,
            'nome_manual' => $withUser ? null : ($classificacao === 'receita' ? 'BSCN Receita' : 'BSCN Despesa'),
            'classificacao' => $classificacao,
            'data_emissao' => '2026-05-05',
            'data_vencimento' => '2026-05-05',
            'valor_total' => $amount,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'observacoes' => 'Movimento para teste canonico',
        ]);
    }

    private function createStandaloneFinancialEntry(string $tipo, float $amount, bool $withUser): FinancialEntry
    {
        $user = $withUser ? User::factory()->create([
            'nome_completo' => 'Entrada Canonica',
            'nif' => '987654321',
            'morada' => 'Rua da Entrada 10',
            'codigo_postal' => '2000-200',
            'localidade' => 'Santarem',
            'email' => 'entrada@example.com',
        ]) : null;

        return FinancialEntry::create([
            'data' => '2026-05-05',
            'tipo' => $tipo,
            'categoria' => 'Servico',
            'descricao' => 'Entrada financeira canonica',
            'documento_ref' => 'ENTRY-' . strtoupper($tipo),
            'valor' => $amount,
            'valor_pago' => 0,
            'valor_em_aberto' => $amount,
            'estado' => 'pendente',
            'centro_custo_id' => $this->createCostCenter()->id,
            'user_id' => $user?->id,
            'entidade_nome' => $user ? null : ($tipo === 'receita' ? 'BSCN Receita' : 'BSCN Despesa'),
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
            'origem_id' => null,
        ]);
    }
}
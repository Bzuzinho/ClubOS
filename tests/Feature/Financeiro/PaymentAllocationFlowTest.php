<?php

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\Familia;
use App\Models\FiscalDocumentRequest;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Supplier;
use App\Models\User;
use App\Models\InvoiceType;
use App\Services\Financeiro\FinancialSettlementService;
use App\Services\Financeiro\FiscalDocumentRequestService;
use App\Services\Financeiro\FiscalEmissionQueueService;
use App\Services\Financeiro\PaymentAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_reconciled_manual_bank_catalog_entry_is_exposed_as_paid_in_movimentos_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = $this->createCostCenter();
        $statement = $this->createBankStatement(150.00);

        $this->actingAs($admin)->postJson(route('financeiro.extratos.conciliar', $statement), [
            'tipo' => 'receita',
            'centro_custo_id' => $costCenter->id,
            'user_id' => $admin->id,
        ])->assertOk();

        $statement->refresh();

        $response = $this->actingAs($admin)->get(route('financeiro.index'));

        $response->assertOk();

        $row = collect($response->viewData('page')['props']['movimentosFinanceiros'] ?? [])
            ->firstWhere('financial_entry_id', $statement->lancamento_id);

        $this->assertNotNull($row);
        $this->assertSame('financial_entry', $row['source_kind']);
        $this->assertTrue((bool) $row['read_only']);
        $this->assertSame('pago', $row['estado_pagamento']);
        $this->assertSame(150.0, (float) $row['valor_pago']);
        $this->assertSame(0.0, (float) $row['valor_em_aberto']);
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

    public function test_monthly_status_endpoint_marks_invoice_as_paid_without_bank_statement(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.mensalidades.estado', $invoice), [
            'estado_pagamento' => 'pago',
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'TRX-MENSALIDADE-001',
            'notes' => 'Liquidacao canonica da mensalidade.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('invoice.estado_pagamento', 'pago');

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertSame('25.00', $invoice->valor_pago);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
        $this->assertDatabaseHas('payments', [
            'reference' => 'TRX-MENSALIDADE-001',
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $invoice->id,
            'amount' => 25.00,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('fiscal_document_requests', [
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'external_document_number' => null,
        ]);
    }

    public function test_monthly_status_endpoint_marks_invoice_as_paid_with_bank_statement(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);
        $statement = $this->createBankStatement(25.00);

        $response = $this->actingAs($admin)->postJson(route('financeiro.mensalidades.estado', $invoice), [
            'estado_pagamento' => 'pago',
            'bank_statement_id' => $statement->id,
            'method' => 'transferencia',
            'reference' => 'TRX-MENSALIDADE-EXTRATO-001',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('invoice.estado_pagamento', 'pago');

        $invoice->refresh();
        $statement->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertTrue($statement->conciliado);
        $this->assertSame('reconciled', $statement->conciliacao_status);
        $this->assertSame('25.00', $statement->valor_conciliado);
        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $invoice->id,
            'amount' => 25.00,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
        ]);
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

        $response = $this->actingAs($admin)->postJson(route('financeiro.mensalidades.estado', $invoice), [
            'estado_pagamento' => 'vencido',
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

    public function test_monthly_status_endpoint_reopens_paid_invoice_to_pending_and_soft_deletes_pending_fiscal_request(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);

        $this->actingAs($admin)->postJson(route('financeiro.mensalidades.estado', $invoice), [
            'estado_pagamento' => 'pago',
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'TRX-MENSALIDADE-REABRIR-001',
        ])->assertOk();

        $payment = Payment::query()->where('reference', 'TRX-MENSALIDADE-REABRIR-001')->firstOrFail();
        $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();
        $request = FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $response = $this->actingAs($admin)->postJson(route('financeiro.mensalidades.estado', $invoice), [
            'estado_pagamento' => 'pendente',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('invoice.estado_pagamento', 'pendente');

        $invoice->refresh();
        $payment->refresh();

        $this->assertSame('pendente', $invoice->estado_pagamento);
        $this->assertSame('0.00', $invoice->valor_pago);
        $this->assertSame('25.00', $invoice->valor_em_aberto);
        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
        $this->assertFalse(PaymentAllocation::query()->whereKey($allocation->id)->exists());
        $this->assertTrue(PaymentAllocation::withTrashed()->whereKey($allocation->id)->where('status', PaymentAllocation::STATUS_CANCELLED)->exists());
        $this->assertSoftDeleted('fiscal_document_requests', [
            'id' => $request->id,
        ]);
    }

    public function test_monthly_status_endpoint_blocks_reopen_when_fiscal_document_has_external_number(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);

        $this->actingAs($admin)->postJson(route('financeiro.mensalidades.estado', $invoice), [
            'estado_pagamento' => 'pago',
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'TRX-MENSALIDADE-BLOQUEIO-001',
        ])->assertOk();

        $request = FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->firstOrFail();

        app(FiscalDocumentRequestService::class)->markIssued($request, [
            'external_document_number' => 'RC 2026/99',
            'issued_at' => '2026-05-05 10:00:00',
        ]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.mensalidades.estado', $invoice), [
            'estado_pagamento' => 'vencido',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado_pagamento');

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'external_document_number' => 'RC 2026/99',
        ]);
    }

    public function test_it_rejects_marking_an_invoice_as_paid_directly_via_update(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([25.00]);

        $response = $this->actingAs($admin)->putJson(route('financeiro.update', $invoice), [
            'user_id' => $invoice->user_id,
            'data_fatura' => optional($invoice->data_fatura)->toDateString(),
            'data_emissao' => $invoice->data_emissao->toDateString(),
            'data_vencimento' => $invoice->data_vencimento->toDateString(),
            'mes' => $invoice->mes,
            'tipo' => $invoice->tipo,
            'estado_pagamento' => 'pago',
            'valor_total' => (float) $invoice->valor_total,
            'oculta' => false,
            'centro_custo_id' => $invoice->centro_custo_id,
            'numero_recibo' => 'REC-DIRETO-001',
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

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado_pagamento')
            ->assertJsonPath('errors.estado_pagamento.0', 'A alteracao de estado financeiro da mensalidade tem de ser efetuada pelo fluxo canonico da mensalidade.');

        $invoice->refresh();

        $this->assertSame('pendente', $invoice->estado_pagamento);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_it_rejects_creating_an_invoice_as_paid_directly(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createInvoiceType();
        $user = User::factory()->create([
            'nome_completo' => 'Fatura Direta Bloqueada',
            'email' => 'fatura-direta@example.test',
        ]);
        $costCenter = $this->createCostCenter();

        $response = $this->actingAs($admin)->postJson(route('financeiro.store'), [
            'user_id' => $user->id,
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-10',
            'mes' => '2026-05',
            'tipo' => 'mensalidade',
            'estado_pagamento' => 'pago',
            'valor_total' => 30.00,
            'oculta' => false,
            'centro_custo_id' => $costCenter->id,
            'items' => [[
                'descricao' => 'Mensalidade maio 2026',
                'quantidade' => 1,
                'valor_unitario' => 30.00,
                'imposto_percentual' => 0,
                'total_linha' => 30.00,
                'centro_custo_id' => $costCenter->id,
            ]],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado_pagamento')
            ->assertJsonPath('errors.estado_pagamento.0', 'A liquidacao da fatura tem de ser efetuada pelo fluxo canonico de pagamento.');

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_it_rejects_marking_a_movement_as_paid_directly_via_update(): void
    {
        $admin = User::factory()->admin()->create();
        $movement = $this->createMovement('receita', 45.00, true);

        $response = $this->actingAs($admin)->putJson(route('financeiro.movimentos.update', $movement), [
            'user_id' => $movement->user_id,
            'supplier_id' => null,
            'nome_manual' => $movement->nome_manual,
            'nif_manual' => $movement->nif_manual,
            'morada_manual' => $movement->morada_manual,
            'classificacao' => $movement->classificacao,
            'categoria' => $movement->categoria,
            'data_emissao' => optional($movement->data_emissao)->toDateString(),
            'data_vencimento' => optional($movement->data_vencimento)->toDateString(),
            'valor_total' => abs((float) $movement->valor_total),
            'estado_pagamento' => 'pago',
            'numero_recibo' => 'MOV-DIRETO-001',
            'referencia_pagamento' => $movement->referencia_pagamento,
            'metodo_pagamento' => 'transferencia',
            'centro_custo_id' => $movement->centro_custo_id,
            'tipo' => $movement->tipo,
            'origem_tipo' => $movement->origem_tipo,
            'origem_id' => $movement->origem_id,
            'observacoes' => $movement->observacoes,
            'items' => [[
                'descricao' => 'Movimento receita',
                'quantidade' => 1,
                'valor_unitario' => 45.00,
                'imposto_percentual' => 0,
                'total_linha' => 45.00,
                'centro_custo_id' => $movement->centro_custo_id,
            ]],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado_pagamento')
            ->assertJsonPath('errors.estado_pagamento.0', 'A liquidacao ou reabertura do movimento tem de ser efetuada pelo fluxo canonico de pagamento.');

        $movement->refresh();

        $this->assertSame('pendente', $movement->estado_pagamento);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_it_rejects_marking_a_movement_as_reconciled_directly_via_update(): void
    {
        $admin = User::factory()->admin()->create();
        $movement = $this->createMovement('receita', 55.00, true);
        $movement->forceFill([
            'estado_conciliacao' => 'nao_conciliado',
        ])->save();

        $response = $this->actingAs($admin)->putJson(route('financeiro.movimentos.update', $movement), [
            'user_id' => $movement->user_id,
            'supplier_id' => null,
            'nome_manual' => $movement->nome_manual,
            'nif_manual' => $movement->nif_manual,
            'morada_manual' => $movement->morada_manual,
            'classificacao' => $movement->classificacao,
            'categoria' => $movement->categoria,
            'data_emissao' => optional($movement->data_emissao)->toDateString(),
            'data_vencimento' => optional($movement->data_vencimento)->toDateString(),
            'valor_total' => abs((float) $movement->valor_total),
            'estado_pagamento' => 'pendente',
            'estado_conciliacao' => 'conciliado',
            'numero_recibo' => $movement->numero_recibo,
            'referencia_pagamento' => $movement->referencia_pagamento,
            'metodo_pagamento' => $movement->metodo_pagamento,
            'centro_custo_id' => $movement->centro_custo_id,
            'tipo' => $movement->tipo,
            'origem_tipo' => $movement->origem_tipo,
            'origem_id' => $movement->origem_id,
            'observacoes' => $movement->observacoes,
            'items' => [[
                'descricao' => 'Movimento receita conciliacao',
                'quantidade' => 1,
                'valor_unitario' => 55.00,
                'imposto_percentual' => 0,
                'total_linha' => 55.00,
                'centro_custo_id' => $movement->centro_custo_id,
            ]],
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('estado_conciliacao')
            ->assertJsonPath('errors.estado_conciliacao.0', 'A alteracao do estado de conciliacao tem de ser efetuada pelo fluxo canonico de conciliacao.');

        $movement->refresh();

        $this->assertSame('nao_conciliado', $movement->estado_conciliacao);
        $this->assertDatabaseCount('mapa_conciliacao', 0);
    }

    public function test_payment_endpoint_uses_financial_settlement_service(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([100.00]);

        $service = \Mockery::mock(FinancialSettlementService::class, [
            app(PaymentAllocationService::class),
            app(\App\Services\Financeiro\FinancialBalanceService::class),
            app(FiscalEmissionQueueService::class),
            app(\App\Services\Financeiro\ReconciliationRepositoryService::class),
        ])->makePartial();
        $service->shouldReceive('settleInvoices')
            ->once()
            ->passthru();
        $this->instance(FinancialSettlementService::class, $service);

        $response = $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 100.00,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'TRX-SERVICE-001',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 100.00],
            ],
        ]);

        $response->assertOk();

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertDatabaseHas('payments', [
            'reference' => 'TRX-SERVICE-001',
            'status' => Payment::STATUS_CONFIRMED,
        ]);
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

    public function test_movement_settlement_endpoint_uses_financial_entry_settlement_service(): void
    {
        $admin = User::factory()->admin()->create();
        $movement = $this->createMovement('receita', 75.00, true);

        $service = \Mockery::mock(FinancialSettlementService::class, [
            app(PaymentAllocationService::class),
            app(\App\Services\Financeiro\FinancialBalanceService::class),
            app(FiscalEmissionQueueService::class),
            app(\App\Services\Financeiro\ReconciliationRepositoryService::class),
        ])->makePartial();
        $service->shouldReceive('findOrCreateFinancialEntryForMovement')
            ->once()
            ->passthru();
        $service->shouldReceive('settleFinancialEntry')
            ->once()
            ->passthru();
        $this->instance(FinancialSettlementService::class, $service);

        $response = $this->actingAs($admin)->postJson(route('financeiro.movimentos.liquidar', $movement), [
            'numero_recibo' => 'MOV-LIQ-001',
            'metodo_pagamento' => 'transferencia',
        ]);

        $response->assertOk();

        $movement->refresh();

        $this->assertSame('pago', $movement->estado_pagamento);
        $this->assertDatabaseHas('financial_entries', [
            'origem_tipo' => 'movement',
            'origem_id' => (string) $movement->id,
            'estado' => 'pago',
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

    public function test_multiple_settlements_complete_revenue_entry_without_creating_duplicate_active_fiscal_requests(): void
    {
        $entry = $this->createStandaloneFinancialEntry('receita', 100.00, true);
        $adminId = User::factory()->admin()->create()->id;

        $first = app(FinancialSettlementService::class)->settleFinancialEntry($entry, [
            'amount' => 40.00,
            'payment_amount' => 40.00,
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'PARTIAL-ENTRY-001',
            'created_by' => $adminId,
        ]);

        $entry = $first['financial_entry'];

        $this->assertSame('parcial', $entry->estado);
        $this->assertDatabaseCount('fiscal_document_requests', 0);

        $second = app(FinancialSettlementService::class)->settleFinancialEntry($entry, [
            'amount' => 60.00,
            'payment_amount' => 60.00,
            'payment_date' => '2026-05-06',
            'method' => 'transferencia',
            'reference' => 'PARTIAL-ENTRY-002',
            'created_by' => $adminId,
        ]);

        $entry = $second['financial_entry'];

        $this->assertSame('pago', $entry->estado);
        $this->assertDatabaseCount('fiscal_document_requests', 1);

        $requestId = FiscalDocumentRequest::query()
            ->where('financial_entry_id', $entry->id)
            ->value('id');

        $this->assertNotNull($requestId);

        $third = app(FiscalEmissionQueueService::class)->queueFinancialEntry($entry, [
            'paid_at' => '2026-05-06',
            'created_by' => $adminId,
        ]);

        $this->assertNotNull($third);
        $this->assertDatabaseCount('fiscal_document_requests', 1);
        $this->assertSame($requestId, FiscalDocumentRequest::query()
            ->where('financial_entry_id', $entry->id)
            ->value('id'));
    }

    public function test_expense_financial_entry_settlement_with_bank_statement_reconciles_without_fiscal_request(): void
    {
        $entry = $this->createStandaloneFinancialEntry('despesa', 80.00, false);
        $statement = $this->createBankStatement(80.00);

        $result = app(FinancialSettlementService::class)->settleFinancialEntry($entry, [
            'payment_date' => '2026-05-05',
            'method' => 'transferencia',
            'reference' => 'BANK-EXP-001',
            'bank_statement_id' => $statement->id,
            'created_by' => User::factory()->admin()->create()->id,
        ]);

        $entry = $result['financial_entry'];
        $statement->refresh();

        $this->assertSame('pago', $entry->estado);
        $this->assertTrue($statement->conciliado);
        $this->assertSame('reconciled', $statement->conciliacao_status);
        $this->assertSame('80.00', $statement->valor_conciliado);
        $this->assertDatabaseHas('payments', [
            'bank_statement_id' => $statement->id,
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        $this->assertDatabaseHas('payment_allocations', [
            'financial_entry_id' => $entry->id,
            'invoice_id' => null,
            'amount' => 80.00,
        ]);
        $this->assertDatabaseHas('mapa_conciliacao', [
            'extrato_id' => $statement->id,
            'lancamento_id' => $entry->id,
            'valor_conciliado' => 80.00,
        ]);
        $this->assertDatabaseMissing('fiscal_document_requests', [
            'financial_entry_id' => $entry->id,
        ]);
    }

    public function test_bank_reconciliation_endpoint_still_reconciles_invoice(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([60.00]);
        $statement = $this->createBankStatement(60.00);

        $response = $this->actingAs($admin)->postJson(route('financeiro.extratos.conciliar', $statement), [
            'tipo' => 'receita',
            'centro_custo_id' => $invoice->centro_custo_id,
            'fatura_id' => $invoice->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('extrato.conciliado', true);

        $invoice->refresh();
        $statement->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertTrue($statement->conciliado);
        $this->assertDatabaseHas('mapa_conciliacao', [
            'extrato_id' => $statement->id,
            'fatura_id' => $invoice->id,
            'status' => 'confirmado',
        ]);
    }

    public function test_bank_reconciliation_endpoint_accepts_financial_entry_directly(): void
    {
        $admin = User::factory()->admin()->create();
        $entry = $this->createStandaloneFinancialEntry('receita', 60.00, true);
        $statement = $this->createBankStatement(60.00);

        $response = $this->actingAs($admin)->postJson(route('financeiro.extratos.conciliar', $statement), [
            'tipo' => 'receita',
            'centro_custo_id' => $entry->centro_custo_id,
            'financial_entry_id' => $entry->id,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('extrato.conciliado', true);

        $entry->refresh();
        $statement->refresh();

        $this->assertSame('pago', $entry->estado);
        $this->assertTrue($statement->conciliado);
        $this->assertDatabaseHas('mapa_conciliacao', [
            'extrato_id' => $statement->id,
            'lancamento_id' => $entry->id,
            'status' => 'confirmado',
        ]);
    }

    public function test_unreconciling_bank_statement_cancels_invoice_allocations_and_account_credit(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $statement = $this->createBankStatement(50.00);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'create_credit' => true,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 30.00],
            ],
        ])->assertOk();

        $payment = Payment::query()->where('bank_statement_id', $statement->id)->firstOrFail();
        $credit = AccountCredit::query()->where('payment_id', $payment->id)->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.extratos.desconciliar', $statement));

        $response
            ->assertOk()
            ->assertJsonPath('extrato.conciliado', false)
            ->assertJsonPath('extrato.conciliacao_status', 'unreconciled');

        $invoice->refresh();
        $statement->refresh();
        $payment->refresh();

        $this->assertSame($invoice->data_vencimento->isPast() ? 'vencido' : 'pendente', $invoice->estado_pagamento);
        $this->assertSame('0.00', $invoice->valor_pago);
        $this->assertSame('30.00', $invoice->valor_em_aberto);
        $this->assertFalse($statement->conciliado);
        $this->assertSame('unreconciled', $statement->conciliacao_status);
        $this->assertSame('0.00', $statement->valor_conciliado);
        $this->assertSame('50.00', $statement->valor_por_conciliar);
        $this->assertSame('0.00', $payment->allocated_amount);
        $this->assertSame('50.00', $payment->unallocated_amount);
        $this->assertTrue(AccountCredit::withTrashed()->whereKey($credit->id)->where('status', AccountCredit::STATUS_CANCELLED)->exists());
        $this->assertTrue(PaymentAllocation::withTrashed()->where('payment_id', $payment->id)->where('status', PaymentAllocation::STATUS_CANCELLED)->exists());
        $this->assertDatabaseMissing('mapa_conciliacao', [
            'extrato_id' => $statement->id,
        ]);
    }

    public function test_unreconciling_bank_statement_restores_financial_entry_to_pending(): void
    {
        $admin = User::factory()->admin()->create();
        $entry = $this->createStandaloneFinancialEntry('receita', 60.00, true);
        $statement = $this->createBankStatement(60.00);

        $this->actingAs($admin)->postJson(route('financeiro.extratos.conciliar', $statement), [
            'tipo' => 'receita',
            'centro_custo_id' => $entry->centro_custo_id,
            'financial_entry_id' => $entry->id,
        ])->assertOk();

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.extratos.desconciliar', $statement));

        $response
            ->assertOk()
            ->assertJsonPath('extrato.conciliado', false)
            ->assertJsonPath('extrato.conciliacao_status', 'unreconciled');

        $entry->refresh();
        $statement->refresh();

        $this->assertSame('pendente', $entry->estado);
        $this->assertSame('0.00', $entry->valor_pago);
        $this->assertSame('60.00', $entry->valor_em_aberto);
        $this->assertFalse($statement->conciliado);
        $this->assertSame('0.00', $statement->valor_conciliado);
        $this->assertSame('60.00', $statement->valor_por_conciliar);
        $this->assertDatabaseMissing('mapa_conciliacao', [
            'extrato_id' => $statement->id,
        ]);
        $this->assertSame(0, FiscalDocumentRequest::query()->where('financial_entry_id', $entry->id)->count());
        $this->assertSame(1, FiscalDocumentRequest::withTrashed()->where('financial_entry_id', $entry->id)->count());
    }

    public function test_unreconciling_one_bank_statement_preserves_independent_previous_invoice_payments(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([100.00]);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'amount' => 40.00,
            'payment_date' => '2026-05-04',
            'method' => 'transferencia',
            'reference' => 'TRX-MANUAL-40',
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 40.00],
            ],
        ])->assertOk();

        $manualPayment = Payment::query()->where('reference', 'TRX-MANUAL-40')->firstOrFail();
        $manualAllocation = PaymentAllocation::query()->where('payment_id', $manualPayment->id)->firstOrFail();

        $statement = $this->createBankStatement(60.00);

        $this->actingAs($admin)->postJson(route('financeiro.extratos.conciliar', $statement), [
            'tipo' => 'receita',
            'centro_custo_id' => $invoice->centro_custo_id,
            'fatura_id' => $invoice->id,
        ])->assertOk();

        $statementPayment = Payment::query()->where('bank_statement_id', $statement->id)->firstOrFail();
        $statementAllocation = PaymentAllocation::query()->where('payment_id', $statementPayment->id)->firstOrFail();

        $invoice->refresh();
        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertSame('100.00', $invoice->valor_pago);
        $this->assertSame('0.00', $invoice->valor_em_aberto);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.extratos.desconciliar', $statement));

        $response
            ->assertOk()
            ->assertJsonPath('extrato.conciliado', false)
            ->assertJsonPath('extrato.conciliacao_status', 'unreconciled');

        $invoice->refresh();
        $statement->refresh();
        $manualPayment->refresh();
        $statementPayment->refresh();

        $this->assertSame('parcial', $invoice->estado_pagamento);
        $this->assertSame('40.00', $invoice->valor_pago);
        $this->assertSame('60.00', $invoice->valor_em_aberto);
        $this->assertSame('40.00', $manualPayment->allocated_amount);
        $this->assertSame('0.00', $manualPayment->unallocated_amount);
        $this->assertSame('0.00', $statementPayment->allocated_amount);
        $this->assertSame('60.00', $statementPayment->unallocated_amount);
        $this->assertTrue(PaymentAllocation::query()->whereKey($manualAllocation->id)->where('status', PaymentAllocation::STATUS_CONFIRMED)->exists());
        $this->assertTrue(PaymentAllocation::withTrashed()->whereKey($statementAllocation->id)->where('status', PaymentAllocation::STATUS_CANCELLED)->exists());
        $this->assertSame(1, PaymentAllocation::query()->confirmed()->where('invoice_id', $invoice->id)->count());
        $this->assertDatabaseMissing('mapa_conciliacao', [
            'extrato_id' => $statement->id,
        ]);
    }

    public function test_unreconciling_bank_statement_with_multiple_invoices_restores_all_and_rejects_active_suggestions(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoiceA, $invoiceB] = $this->createInvoicesForUser([20.00, 30.00]);
        $statement = $this->createBankStatement(50.00);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'amount' => 20.00],
                ['invoice_id' => $invoiceB->id, 'amount' => 30.00],
            ],
        ])->assertOk();

        $requestA = FiscalDocumentRequest::query()->where('invoice_id', $invoiceA->id)->firstOrFail();
        $requestB = FiscalDocumentRequest::query()->where('invoice_id', $invoiceB->id)->firstOrFail();
        $suggestion = BankReconciliationSuggestion::query()->create([
            'bank_statement_id' => $statement->id,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
            'score' => 88,
            'confidence_label' => BankReconciliationSuggestion::CONFIDENCE_HIGH,
            'total_bank_amount' => 50,
            'total_allocated_amount' => 50,
            'unallocated_amount' => 0,
            'suggested_allocations' => [],
            'matched_rules' => [],
            'explanation' => 'Sugestao a limpar apos desconciliacao.',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.extratos.desconciliar', $statement));

        $response
            ->assertOk()
            ->assertJsonPath('extrato.conciliacao_status', 'unreconciled');

        $invoiceA->refresh();
        $invoiceB->refresh();
        $statement->refresh();
        $suggestion->refresh();

        $this->assertSame($invoiceA->data_vencimento->isPast() ? 'vencido' : 'pendente', $invoiceA->estado_pagamento);
        $this->assertSame($invoiceB->data_vencimento->isPast() ? 'vencido' : 'pendente', $invoiceB->estado_pagamento);
        $this->assertSame('0.00', $invoiceA->valor_pago);
        $this->assertSame('0.00', $invoiceB->valor_pago);
        $this->assertSame('20.00', $invoiceA->valor_em_aberto);
        $this->assertSame('30.00', $invoiceB->valor_em_aberto);
        $this->assertSoftDeleted('fiscal_document_requests', ['id' => $requestA->id]);
        $this->assertSoftDeleted('fiscal_document_requests', ['id' => $requestB->id]);
        $this->assertSame(BankReconciliationSuggestion::STATUS_REJECTED, $suggestion->status);
        $this->assertFalse($statement->conciliado);
        $this->assertSame('unreconciled', $statement->conciliacao_status);
    }

    public function test_unreconciling_bank_statement_is_blocked_when_invoice_has_wintouch_document(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $statement = $this->createBankStatement(30.00);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 30.00],
            ],
        ])->assertOk();

        $request = FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->firstOrFail();
        app(FiscalDocumentRequestService::class)->markIssued($request, [
            'external_document_number' => 'RC 2026/99',
            'issued_at' => '2026-05-05 10:00:00',
        ]);

        $payment = Payment::query()->where('bank_statement_id', $statement->id)->firstOrFail();
        $allocation = PaymentAllocation::query()->where('payment_id', $payment->id)->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.extratos.desconciliar', $statement));

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('extrato');

        $invoice->refresh();
        $statement->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertTrue($statement->conciliado);
        $this->assertTrue(PaymentAllocation::query()->whereKey($allocation->id)->where('status', PaymentAllocation::STATUS_CONFIRMED)->exists());
    }

    public function test_unreconciling_bank_statement_for_movement_soft_deletes_pending_fiscal_request(): void
    {
        $admin = User::factory()->admin()->create();
        $movement = $this->createMovement('receita', 45.00, true);
        $statement = $this->createBankStatement(45.00);

        $this->actingAs($admin)->postJson(route('financeiro.bank-statements.allocate', $statement), [
            'movements' => [
                ['movement_id' => $movement->id, 'amount' => 45.00, 'centro_custo_id' => $movement->centro_custo_id],
            ],
        ])->assertOk();

        $entry = FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->where('origem_id', $movement->id)
            ->firstOrFail();
        $request = FiscalDocumentRequest::query()->where('financial_entry_id', $entry->id)->firstOrFail();

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.extratos.desconciliar', $statement));

        $response->assertOk();

        $movement->refresh();
        $entry->refresh();

        $this->assertSame('pendente', $movement->estado_pagamento);
        $this->assertSame('pendente', $entry->estado);
        $this->assertSame('0.00', $entry->valor_pago);
        $this->assertSame('45.00', $entry->valor_em_aberto);
        $this->assertSoftDeleted('fiscal_document_requests', ['id' => $request->id]);
    }

    public function test_unreconciling_bank_statement_is_blocked_when_generated_credit_was_used(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $statement = $this->createBankStatement(50.00);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'create_credit' => true,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 30.00],
            ],
        ])->assertOk();

        $credit = AccountCredit::query()->where('payment_id', Payment::query()->where('bank_statement_id', $statement->id)->value('id'))->firstOrFail();
        $credit->forceFill([
            'status' => AccountCredit::STATUS_USED,
            'remaining_amount' => 0,
        ])->save();

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.extratos.desconciliar', $statement));

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('extrato');

        $statement->refresh();
        $this->assertTrue($statement->conciliado);
        $this->assertSame('reconciled', $statement->conciliacao_status);
    }

    public function test_unreconciling_partial_bank_statement_restores_invoice_and_statement_to_unreconciled(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $statement = $this->createBankStatement(50.00);

        $this->actingAs($admin)->postJson(route('financeiro.payments.allocate'), [
            'bank_statement_id' => $statement->id,
            'create_credit' => false,
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 30.00],
            ],
        ])->assertOk();

        $statement->refresh();
        $this->assertSame('partial', $statement->conciliacao_status);
        $this->assertFalse($statement->conciliado);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.extratos.desconciliar', $statement));

        $response
            ->assertOk()
            ->assertJsonPath('extrato.conciliacao_status', 'unreconciled');

        $invoice->refresh();
        $statement->refresh();

        $this->assertSame($invoice->data_vencimento->isPast() ? 'vencido' : 'pendente', $invoice->estado_pagamento);
        $this->assertSame('0.00', $invoice->valor_pago);
        $this->assertSame('30.00', $invoice->valor_em_aberto);
        $this->assertFalse($statement->conciliado);
        $this->assertSame('unreconciled', $statement->conciliacao_status);
        $this->assertSame('0.00', $statement->valor_conciliado);
        $this->assertSame('50.00', $statement->valor_por_conciliar);
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

    public function test_store_movimento_allows_manual_text_origin_reference_without_writing_invalid_uuid(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = $this->createCostCenter();

        $response = $this->actingAs($admin)->postJson(route('financeiro.movimentos.store'), [
            'nome_manual' => 'Fornecedor Manual',
            'nif_manual' => '516848160',
            'classificacao' => 'despesa',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-13',
            'valor_total' => -1537.50,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => 'FT 2026/8',
            'observacoes' => 'Observacao teste',
            'items' => [
                [
                    'descricao' => 'Assessoria Tecnica Abril',
                    'quantidade' => 1,
                    'valor_unitario' => 1250,
                    'imposto_percentual' => 23,
                    'total_linha' => 1537.50,
                    'centro_custo_id' => $costCenter->id,
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('movimento.origem_tipo', 'manual')
            ->assertJsonPath('movimento.origem_id', 'FT 2026/8')
            ->assertJsonPath('movimento.observacoes', 'Observacao teste');

        $movement = Movement::query()->latest('created_at')->firstOrFail();

        $this->assertNull($movement->origem_id);
        $this->assertSame(
            "Observacao teste\n[ORIGEM_REF] FT 2026/8",
            $movement->observacoes,
        );
        $this->assertDatabaseHas('movement_items', [
            'movimento_id' => $movement->id,
            'descricao' => 'Assessoria Tecnica Abril',
            'total_linha' => 1537.50,
        ]);
    }

    public function test_store_movimento_can_snapshot_existing_supplier_data(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = $this->createCostCenter();
        $supplier = Supplier::query()->create([
            'nome' => 'Fornecedor Canonico',
            'nif' => '504321987',
            'morada' => 'Rua do Fornecedor 10',
            'email' => 'fornecedor@example.test',
            'ativo' => true,
        ]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.movimentos.store'), [
            'supplier_id' => $supplier->id,
            'classificacao' => 'despesa',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-13',
            'valor_total' => -99.99,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'observacoes' => 'Movimento com fornecedor existente',
            'items' => [
                [
                    'descricao' => 'Servico teste',
                    'quantidade' => 1,
                    'valor_unitario' => 99.99,
                    'imposto_percentual' => 0,
                    'total_linha' => 99.99,
                    'centro_custo_id' => $costCenter->id,
                ],
            ],
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('movimento.supplier_id', $supplier->id)
            ->assertJsonPath('movimento.nome_manual', 'Fornecedor Canonico')
            ->assertJsonPath('movimento.nif_manual', '504321987')
            ->assertJsonPath('movimento.morada_manual', 'Rua do Fornecedor 10');

        $this->assertDatabaseHas('movements', [
            'supplier_id' => $supplier->id,
            'user_id' => null,
            'nome_manual' => 'Fornecedor Canonico',
            'nif_manual' => '504321987',
            'morada_manual' => 'Rua do Fornecedor 10',
        ]);
    }

    public function test_bank_statement_allocate_endpoint_accepts_invoice_payload_and_preserves_origin_cost_centers(): void
    {
        $admin = User::factory()->admin()->create();
        $userA = User::factory()->create(['nome_completo' => 'User Centro A']);
        $userB = User::factory()->create(['nome_completo' => 'User Centro B']);
        $costCenterA = CostCenter::create([
            'codigo' => 'CC-ORIGEM-A',
            'nome' => 'Centro Origem A',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);
        $costCenterB = CostCenter::create([
            'codigo' => 'CC-ORIGEM-B',
            'nome' => 'Centro Origem B',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $invoiceA = Invoice::create([
            'user_id' => $userA->id,
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-10',
            'valor_total' => 25.00,
            'estado_pagamento' => 'pendente',
            'referencia_pagamento' => 'CC-A-REF',
            'centro_custo_id' => $costCenterA->id,
            'tipo' => 'mensalidade',
        ]);
        InvoiceItem::create([
            'fatura_id' => $invoiceA->id,
            'descricao' => 'Mensalidade A',
            'quantidade' => 1,
            'valor_unitario' => 25.00,
            'imposto_percentual' => 0,
            'total_linha' => 25.00,
            'centro_custo_id' => $costCenterA->id,
        ]);

        $invoiceB = Invoice::create([
            'user_id' => $userB->id,
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-10',
            'valor_total' => 30.00,
            'estado_pagamento' => 'pendente',
            'referencia_pagamento' => 'CC-B-REF',
            'centro_custo_id' => $costCenterB->id,
            'tipo' => 'mensalidade',
        ]);
        InvoiceItem::create([
            'fatura_id' => $invoiceB->id,
            'descricao' => 'Mensalidade B',
            'quantidade' => 1,
            'valor_unitario' => 30.00,
            'imposto_percentual' => 0,
            'total_linha' => 30.00,
            'centro_custo_id' => $costCenterB->id,
        ]);

        $statement = $this->createBankStatement(55.00);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.allocate', $statement), [
                'invoices' => [
                    ['invoice_id' => $invoiceA->id, 'amount' => 25.00],
                    ['invoice_id' => $invoiceB->id, 'amount' => 30.00],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('summary.bank_statement_reconciled', true);

        $invoiceA->refresh();
        $invoiceB->refresh();
        $statement->refresh();

        $this->assertSame('pago', $invoiceA->estado_pagamento);
        $this->assertSame('pago', $invoiceB->estado_pagamento);
        $this->assertTrue($statement->conciliado);

        $allocationA = PaymentAllocation::query()->where('invoice_id', $invoiceA->id)->latest('created_at')->firstOrFail();
        $allocationB = PaymentAllocation::query()->where('invoice_id', $invoiceB->id)->latest('created_at')->firstOrFail();

        $this->assertDatabaseHas('financial_entries', [
            'origem_tipo' => 'payment_allocation',
            'origem_id' => (string) $allocationA->id,
            'centro_custo_id' => $costCenterA->id,
        ]);
        $this->assertDatabaseHas('financial_entries', [
            'origem_tipo' => 'payment_allocation',
            'origem_id' => (string) $allocationB->id,
            'centro_custo_id' => $costCenterB->id,
        ]);
    }

    public function test_bank_statement_allocate_endpoint_accepts_movement_payload_and_can_create_credit_with_explicit_target(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $movement = $this->createMovement('receita', 40.00, true);
        $statement = $this->createBankStatement(100.00);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.allocate', $statement), [
                'invoices' => [
                    ['invoice_id' => $invoice->id, 'amount' => 30.00],
                ],
                'movements' => [
                    ['movement_id' => $movement->id, 'amount' => 40.00],
                ],
                'create_credit' => true,
                'credit_user_id' => $invoice->user_id,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.created_credit', true)
            ->assertJsonPath('summary.bank_statement_reconciled', true);

        $movement->refresh();
        $statement->refresh();

        $this->assertSame('pago', $movement->estado_pagamento);
        $this->assertTrue($statement->conciliado);
        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $invoice->id,
            'amount' => 30.00,
        ]);

        $movementEntryId = FinancialEntry::query()
            ->where('origem_tipo', 'movement')
            ->where('origem_id', $movement->id)
            ->value('id');

        $this->assertNotNull($movementEntryId);
        $this->assertDatabaseHas('payment_allocations', [
            'financial_entry_id' => $movementEntryId,
            'invoice_id' => null,
            'amount' => 40.00,
        ]);
        $this->assertDatabaseHas('account_credits', [
            'user_id' => $invoice->user_id,
            'amount' => 30.00,
            'status' => AccountCredit::STATUS_AVAILABLE,
        ]);
    }

    public function test_bank_statement_allocate_endpoint_rejects_credit_without_explicit_target(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $statement = $this->createBankStatement(50.00);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.allocate', $statement), [
                'invoices' => [
                    ['invoice_id' => $invoice->id, 'amount' => 30.00],
                ],
                'create_credit' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('create_credit');
    }

    public function test_bank_statement_allocate_endpoint_rejects_multiple_credit_targets(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00]);
        $statement = $this->createBankStatement(50.00);
        $family = Familia::create([
            'nome' => 'Familia Credito Duplicado',
            'responsavel_user_id' => $invoice->user_id,
            'ativo' => true,
        ]);
        $family->members()->attach($invoice->user_id, [
            'papel_na_familia' => 'responsavel',
            'pode_editar' => true,
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
        ]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.allocate', $statement), [
                'invoices' => [
                    ['invoice_id' => $invoice->id, 'amount' => 30.00],
                ],
                'create_credit' => true,
                'credit_user_id' => $invoice->user_id,
                'credit_family_id' => $family->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('create_credit');
    }

    public function test_open_movements_endpoint_is_paginated_searchable_and_returns_default_cost_center(): void
    {
        $admin = User::factory()->admin()->create();
        $defaultCostCenter = CostCenter::create([
            'codigo' => 'CC-DEFAULT-SEARCH',
            'nome' => 'Centro Default Search',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);
        $user = User::factory()->create([
            'nome_completo' => 'Pesquisa Silva',
            'numero_socio' => '7001',
            'nif' => '999888777',
        ]);
        \DB::table('centro_custo_user')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'centro_custo_id' => $defaultCostCenter->id,
            'peso' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $movement = Movement::create([
            'user_id' => $user->id,
            'classificacao' => 'receita',
            'data_emissao' => '2026-05-05',
            'data_vencimento' => '2026-05-05',
            'valor_total' => 45.00,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => null,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'observacoes' => 'Transferencia familia Silva socio 7001',
        ]);

        foreach (range(1, 12) as $index) {
            Movement::create([
                'user_id' => null,
                'nome_manual' => 'Outro movimento ' . $index,
                'classificacao' => 'receita',
                'data_emissao' => '2026-05-05',
                'data_vencimento' => '2026-05-05',
                'valor_total' => 10.00 + $index,
                'estado_pagamento' => 'pendente',
                'centro_custo_id' => $defaultCostCenter->id,
                'tipo' => 'servico',
                'origem_tipo' => 'manual',
                'origem_id' => null,
                'observacoes' => 'Outro movimento aberto ' . $index,
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.movements.open', [
                'per_page' => 10,
                'search' => 'Silva 7001',
            ]));

        $response
            ->assertOk()
            ->assertJsonPath('per_page', 10);

        $row = collect($response->json('data') ?? [])->firstWhere('id', $movement->id);

        $this->assertNotNull($row);
        $this->assertSame($defaultCostCenter->id, $row['default_centro_custo_id'] ?? null);
        $this->assertFalse((bool) ($row['requires_centro_custo'] ?? true));
        $this->assertLessThanOrEqual(10, count($response->json('data') ?? []));
    }

    public function test_open_movements_endpoint_accepts_family_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $matchingUser = User::factory()->create();
        $nonMatchingUser = User::factory()->create();
        $family = Familia::create([
            'nome' => 'Familia Filter',
            'responsavel_user_id' => $matchingUser->id,
            'ativo' => true,
        ]);

        $family->members()->attach($matchingUser->id, [
            'papel_na_familia' => 'responsavel',
            'pode_editar' => true,
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
        ]);

        $matchingMovement = Movement::create([
            'user_id' => $matchingUser->id,
            'classificacao' => 'receita',
            'data_emissao' => '2026-05-05',
            'data_vencimento' => '2026-05-05',
            'valor_total' => 25.00,
            'estado_pagamento' => 'pendente',
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'observacoes' => 'Movimento familia certa',
        ]);

        Movement::create([
            'user_id' => $nonMatchingUser->id,
            'classificacao' => 'receita',
            'data_emissao' => '2026-05-05',
            'data_vencimento' => '2026-05-05',
            'valor_total' => 35.00,
            'estado_pagamento' => 'pendente',
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'observacoes' => 'Movimento familia errada',
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.movements.open', [
                'family_id' => $family->id,
            ]));

        $response->assertOk();

        $ids = collect($response->json('data') ?? [])->pluck('id');

        $this->assertTrue($ids->contains($matchingMovement->id));
        $this->assertCount(1, $ids);
    }

    public function test_open_movements_endpoint_keeps_pending_expense_without_financial_entry_visible(): void
    {
        $admin = User::factory()->admin()->create();
        $costCenter = $this->createCostCenter();

        $movement = Movement::create([
            'user_id' => null,
            'nome_manual' => 'Fornecedor XPTO',
            'classificacao' => 'despesa',
            'data_emissao' => '2026-05-05',
            'data_vencimento' => '2026-05-05',
            'valor_total' => -1537.50,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
            'observacoes' => 'Transferencia fornecedor XPTO',
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.movements.open', [
                'per_page' => 25,
            ]));

        $response->assertOk();

        $row = collect($response->json('data') ?? [])->firstWhere('id', $movement->id);

        $this->assertNotNull($row);
        $this->assertSame('despesa', $row['classificacao']);
        $this->assertSame(1537.5, (float) $row['valor_em_aberto']);
        $this->assertSame(0.0, (float) $row['valor_pago']);
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
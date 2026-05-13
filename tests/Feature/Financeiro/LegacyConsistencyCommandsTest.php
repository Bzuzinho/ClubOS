<?php

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LegacyConsistencyCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_command_flags_paid_invoice_without_active_fiscal_request_when_only_soft_deleted_exists(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('AUDIT');
        $invoice = $this->createInvoice($user, $costCenter, 55.00, [
            'estado_pagamento' => 'vencido',
            'valor_pago' => 0,
            'valor_em_aberto' => 55.00,
            'metodo_pagamento' => null,
        ]);

        $payment = $this->createPayment($user, 55.00, [
            'allocated_amount' => 55.00,
            'unallocated_amount' => 0,
            'payment_date' => '2026-01-15',
            'reference' => 'PAY-AUDIT',
        ]);

        $this->createAllocation($payment, $invoice, 55.00, '2026-01-15 10:00:00');
        $this->createSoftDeletedFiscalRequest($invoice);

        $paymentWithoutCredit = $this->createPayment($user, 30.00, [
            'allocated_amount' => 0,
            'unallocated_amount' => 30.00,
            'reference' => 'UNALLOCATED',
        ]);

        $paymentWithoutAllocations = $this->createPayment($user, 25.00, [
            'allocated_amount' => 25.00,
            'unallocated_amount' => 0,
            'reference' => 'BROKEN-ALLOC',
        ]);

        Artisan::call('financeiro:audit-legacy-consistency');
        $output = Artisan::output();

        $this->assertStringContainsString('Invoices pagas por allocation mas sem fiscal request ativo', $output);
        $this->assertStringContainsString($invoice->id, $output);
        $this->assertStringContainsString($paymentWithoutCredit->id, $output);
        $this->assertStringContainsString($paymentWithoutAllocations->id, $output);
    }

    public function test_repair_commit_turns_overdue_invoice_with_confirmed_allocation_into_paid(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('FULL');
        $invoice = $this->createInvoice($user, $costCenter, 55.00, [
            'estado_pagamento' => 'vencido',
            'valor_pago' => 0,
            'valor_em_aberto' => 55.00,
        ]);

        $payment = $this->createPayment($user, 55.00, [
            'allocated_amount' => 55.00,
            'unallocated_amount' => 0,
            'payment_date' => '2026-01-15',
            'method' => 'transferencia',
            'reference' => 'PAY-FULL',
        ]);

        $this->createAllocation($payment, $invoice, 55.00, '2026-01-15 10:00:00');

        $this->artisan('financeiro:repair-legacy-consistency', ['--commit' => true])
            ->assertExitCode(0);

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertSame('55.00', $invoice->valor_pago);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
        $this->assertSame('2026-01-15', $invoice->data_pagamento?->toDateString());
        $this->assertSame('transferencia', $invoice->metodo_pagamento);
    }

    public function test_repair_commit_turns_partially_allocated_invoice_into_partial(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('PARTIAL');
        $invoice = $this->createInvoice($user, $costCenter, 100.00, [
            'estado_pagamento' => 'vencido',
            'valor_pago' => 0,
            'valor_em_aberto' => 100.00,
        ]);

        $payment = $this->createPayment($user, 40.00, [
            'allocated_amount' => 40.00,
            'unallocated_amount' => 0,
            'payment_date' => '2026-02-15',
            'method' => 'mbway',
            'reference' => 'PAY-PARTIAL',
        ]);

        $this->createAllocation($payment, $invoice, 40.00, '2026-02-15 10:00:00');

        $this->artisan('financeiro:repair-legacy-consistency', ['--commit' => true])
            ->assertExitCode(0);

        $invoice->refresh();

        $this->assertSame('parcial', $invoice->estado_pagamento);
        $this->assertSame('40.00', $invoice->valor_pago);
        $this->assertSame('60.00', $invoice->valor_em_aberto);
        $this->assertNull($invoice->data_pagamento);
        $this->assertSame('mbway', $invoice->metodo_pagamento);
    }

    public function test_invoice_without_allocation_keeps_current_pending_or_overdue_state(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('NOALLOC');
        $overdueInvoice = $this->createInvoice($user, $costCenter, 75.00, [
            'estado_pagamento' => 'vencido',
            'valor_pago' => 0,
            'valor_em_aberto' => 75.00,
            'data_vencimento' => '2026-01-10',
        ]);
        $pendingInvoice = $this->createInvoice($user, $costCenter, 80.00, [
            'estado_pagamento' => 'pendente',
            'valor_pago' => 0,
            'valor_em_aberto' => 80.00,
            'data_vencimento' => '2099-01-10',
            'referencia_pagamento' => 'REF-NOALLOC-2',
        ]);

        $this->artisan('financeiro:repair-legacy-consistency', ['--commit' => true])
            ->assertExitCode(0);

        $overdueInvoice->refresh();
        $pendingInvoice->refresh();

        $this->assertSame('vencido', $overdueInvoice->estado_pagamento);
        $this->assertSame('pendente', $pendingInvoice->estado_pagamento);
        $this->assertSame('0.00', $overdueInvoice->valor_pago);
        $this->assertSame('0.00', $pendingInvoice->valor_pago);
    }

    public function test_soft_deleted_fiscal_request_does_not_count_as_active_and_repair_creates_new_active_request_for_paid_invoice(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('FISCAL');
        $invoice = Invoice::withoutEvents(fn () => $this->createInvoice($user, $costCenter, 60.00, [
            'estado_pagamento' => 'pago',
            'valor_pago' => 60.00,
            'valor_em_aberto' => 0,
            'data_pagamento' => '2026-03-10',
            'metodo_pagamento' => 'transferencia',
        ]));

        $payment = $this->createPayment($user, 60.00, [
            'allocated_amount' => 60.00,
            'unallocated_amount' => 0,
            'payment_date' => '2026-03-10',
            'reference' => 'PAY-FISCAL',
        ]);

        $this->createAllocation($payment, $invoice, 60.00, '2026-03-10 09:00:00');
        $deletedRequest = $this->createSoftDeletedFiscalRequest($invoice);

        $this->assertSame(0, FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->count());

        $this->artisan('financeiro:repair-legacy-consistency', ['--commit' => true])
            ->assertExitCode(0);

        $activeRequests = FiscalDocumentRequest::query()
            ->where('invoice_id', $invoice->id)
            ->get();

        $this->assertCount(1, $activeRequests);
        $this->assertNotSame($deletedRequest->id, $activeRequests->first()->id);
        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $activeRequests->first()->status);
        $this->assertSame(1, FiscalDocumentRequest::withTrashed()->where('invoice_id', $invoice->id)->whereNotNull('deleted_at')->count());
    }

    public function test_repair_dry_run_does_not_change_database(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('DRYRUN');
        $invoice = $this->createInvoice($user, $costCenter, 45.00, [
            'estado_pagamento' => 'vencido',
            'valor_pago' => 0,
            'valor_em_aberto' => 45.00,
        ]);

        $payment = $this->createPayment($user, 45.00, [
            'allocated_amount' => 45.00,
            'unallocated_amount' => 0,
            'payment_date' => '2026-04-10',
            'reference' => 'PAY-DRY',
        ]);

        $this->createAllocation($payment, $invoice, 45.00, '2026-04-10 10:00:00');
        $this->createSoftDeletedFiscalRequest($invoice);

        $this->artisan('financeiro:repair-legacy-consistency')
            ->assertExitCode(0);

        $invoice->refresh();

        $this->assertSame('vencido', $invoice->estado_pagamento);
        $this->assertSame('0.00', $invoice->valor_pago);
        $this->assertSame('45.00', $invoice->valor_em_aberto);
        $this->assertSame(0, FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_commit_alters_only_eligible_cases_and_does_not_touch_audit_only_payments(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('ELIGIBLE');
        $invoice = $this->createInvoice($user, $costCenter, 70.00, [
            'estado_pagamento' => 'vencido',
            'valor_pago' => 0,
            'valor_em_aberto' => 70.00,
        ]);

        $payment = $this->createPayment($user, 70.00, [
            'allocated_amount' => 70.00,
            'unallocated_amount' => 0,
            'payment_date' => '2026-05-10',
            'reference' => 'PAY-ELIGIBLE',
        ]);

        $this->createAllocation($payment, $invoice, 70.00, '2026-05-10 10:00:00');

        $unallocatedPayment = $this->createPayment($user, 22.00, [
            'allocated_amount' => 0,
            'unallocated_amount' => 22.00,
            'reference' => 'UNALLOCATED-ONLY-AUDIT',
        ]);

        $brokenTrackedPayment = $this->createPayment($user, 18.00, [
            'allocated_amount' => 18.00,
            'unallocated_amount' => 0,
            'reference' => 'BROKEN-TRACKING-ONLY-AUDIT',
        ]);

        $this->artisan('financeiro:repair-legacy-consistency', ['--commit' => true])
            ->assertExitCode(0);

        $invoice->refresh();
        $unallocatedPayment->refresh();
        $brokenTrackedPayment->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertDatabaseCount('account_credits', 0);
        $this->assertSame('22.00', $unallocatedPayment->unallocated_amount);
        $this->assertSame('18.00', $brokenTrackedPayment->allocated_amount);
        $this->assertDatabaseMissing('payment_allocations', [
            'payment_id' => $brokenTrackedPayment->id,
        ]);
    }

    /**
     * @return array{0: User, 1: CostCenter}
     */
    private function createFinanceContext(string $suffix): array
    {
        $user = User::factory()->create([
            'nome_completo' => 'Socio Legacy ' . $suffix,
            'nif' => '123456789',
            'morada' => 'Rua Legacy 1',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa',
            'email' => strtolower($suffix) . '@example.com',
        ]);

        $costCenter = CostCenter::create([
            'codigo' => 'CC-' . $suffix,
            'nome' => 'Centro ' . $suffix,
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        return [$user, $costCenter];
    }

    private function createInvoice(User $user, CostCenter $costCenter, float $amount, array $overrides = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'user_id' => $user->id,
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'data_vencimento' => '2026-01-10',
            'valor_total' => $amount,
            'valor_pago' => 0,
            'valor_em_aberto' => $amount,
            'estado_pagamento' => 'pendente',
            'numero_recibo' => null,
            'referencia_pagamento' => 'REF-' . uniqid(),
            'metodo_pagamento' => null,
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'mensalidade',
            'observacoes' => 'Mensalidade legacy',
        ], $overrides));

        InvoiceItem::create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Mensalidade legacy',
            'quantidade' => 1,
            'valor_unitario' => $amount,
            'imposto_percentual' => 0,
            'total_linha' => $amount,
            'centro_custo_id' => $costCenter->id,
        ]);

        return $invoice;
    }

    private function createPayment(User $user, float $amount, array $overrides = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $user->id,
            'family_id' => null,
            'bank_statement_id' => null,
            'amount' => $amount,
            'allocated_amount' => 0,
            'unallocated_amount' => $amount,
            'payment_date' => '2026-01-15',
            'method' => 'transferencia',
            'reference' => 'PAY-' . uniqid(),
            'description' => 'Pagamento legacy',
            'source' => Payment::SOURCE_RECONCILIATION,
            'status' => Payment::STATUS_CONFIRMED,
        ], $overrides));
    }

    private function createAllocation(Payment $payment, Invoice $invoice, float $amount, string $allocatedAt): PaymentAllocation
    {
        return PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => $allocatedAt,
        ]);
    }

    private function createSoftDeletedFiscalRequest(Invoice $invoice): FiscalDocumentRequest
    {
        $request = FiscalDocumentRequest::create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => $invoice->valor_total,
            'paid_at' => $invoice->data_pagamento,
            'due_at' => $invoice->data_vencimento,
            'customer_name' => $invoice->user?->nome_completo,
            'customer_tax_number' => $invoice->user?->nif,
            'customer_email' => $invoice->user?->email,
            'customer_address' => $invoice->user?->morada,
            'description' => 'Pedido fiscal legacy',
            'internal_reference' => $invoice->referencia_pagamento,
            'cost_center_id' => $invoice->centro_custo_id,
        ]);

        $request->delete();

        return $request->fresh(['invoice']);
    }
}
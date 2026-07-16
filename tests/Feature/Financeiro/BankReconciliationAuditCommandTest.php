<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class BankReconciliationAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-16 12:00:00'));

        InvoiceType::query()->firstOrCreate(
            ['codigo' => 'material'],
            ['nome' => 'Material', 'ativo' => true],
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_old_bank_transaction_without_payment_generates_historical_info(): void
    {
        $bank = $this->bankStatement([
            'valor' => 25,
            'data_movimento' => '2026-01-10',
        ]);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertFinding($payload, 'historical_bank_transaction_without_payment', 'info', bankTransactionId: $bank->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['info_count']);
        $this->assertSame(1, $payload['summary']['historical_without_payment_count']);
    }

    public function test_recent_bank_transaction_without_matching_signals_generates_unclassified_import_info(): void
    {
        $bank = $this->bankStatement([
            'valor' => 25,
            'descricao' => 'Entrada sem referencia operacional',
            'data_movimento' => '2026-07-10',
        ]);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertFinding($payload, 'bank_transaction_unclassified_import', 'info', bankTransactionId: $bank->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['unclassified_import_count']);
    }

    public function test_bank_transaction_outside_payment_domain_generates_info(): void
    {
        $bank = $this->bankStatement([
            'valor' => 25,
            'descricao' => 'Juros bancarios',
            'data_movimento' => '2026-07-10',
        ]);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertFinding($payload, 'bank_transaction_outside_payment_domain', 'info', bankTransactionId: $bank->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['outside_payment_domain_count']);
    }

    public function test_recent_bank_transaction_with_clear_open_invoice_match_generates_actionable_warning(): void
    {
        $user = User::factory()->create([
            'name' => 'Ana Silva',
            'nome_completo' => 'Ana Silva',
        ]);
        $invoice = $this->invoice([
            'user_id' => $user->id,
            'valor_total' => 25,
            'valor_pago' => 0,
            'valor_em_aberto' => 25,
            'estado_pagamento' => 'pendente',
        ]);
        $bank = $this->bankStatement([
            'valor' => 25,
            'descricao' => 'Pagamento mensalidade Ana Silva',
            'data_movimento' => '2026-07-10',
        ]);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertFinding($payload, 'bank_transaction_without_payment_actionable', 'warning', bankTransactionId: $bank->id);
        $this->assertSame(1, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['actionable_without_payment_count']);
        $this->assertSame(1, $payload['summary']['candidate_invoice_match_count']);
        $this->assertSame(1, $payload['findings'][0]['context']['matched_open_invoice_candidates_count']);
        $this->assertContains($invoice->id, $payload['findings'][0]['context']['matched_open_invoice_candidate_ids']);
    }

    public function test_ignored_bank_transaction_without_payment_is_not_warning(): void
    {
        $bank = $this->bankStatement([
            'valor' => 25,
            'conciliacao_status' => 'ignored',
        ]);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertNoFinding($payload, 'bank_transaction_unclassified_import', bankTransactionId: $bank->id);
        $this->assertNoFinding($payload, 'bank_transaction_without_payment_actionable', bankTransactionId: $bank->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
    }

    public function test_clean_bank_to_payment_to_allocation_to_invoice_is_info_only_with_include_clean(): void
    {
        [$bank, $payment] = $this->cleanReconciledChain();

        $withoutClean = $this->jsonPayload(['--bank-transaction' => $bank->id]);
        $this->assertNoFinding($withoutClean, 'clean_reconciled_payment', bankTransactionId: $bank->id);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id, '--include-clean' => true]);

        $this->assertFinding($payload, 'clean_reconciled_payment', 'info', bankTransactionId: $bank->id, paymentId: $payment->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['info_count']);
    }

    public function test_bank_transaction_with_two_active_payments_is_critical(): void
    {
        $bank = $this->bankStatement(['valor' => 20]);
        $this->payment(['bank_statement_id' => $bank->id, 'amount' => 20, 'source' => Payment::SOURCE_BANK_STATEMENT]);
        $this->payment(['bank_statement_id' => $bank->id, 'amount' => 20, 'source' => Payment::SOURCE_BANK_STATEMENT]);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertFinding($payload, 'bank_transaction_duplicate_payment', 'critical', bankTransactionId: $bank->id);
    }

    public function test_payment_source_reconciliation_without_bank_trace_generates_warning(): void
    {
        $payment = $this->payment([
            'source' => Payment::SOURCE_RECONCILIATION,
            'bank_statement_id' => null,
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_without_bank_trace', 'warning', paymentId: $payment->id);
    }

    public function test_payment_amount_different_from_bank_amount_generates_critical(): void
    {
        $bank = $this->bankStatement(['valor' => 30]);
        $payment = $this->payment([
            'bank_statement_id' => $bank->id,
            'amount' => 20,
            'source' => Payment::SOURCE_BANK_STATEMENT,
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_bank_amount_mismatch', 'critical', bankTransactionId: $bank->id, paymentId: $payment->id);
    }

    public function test_reconciliation_sum_above_bank_transaction_generates_critical(): void
    {
        $bank = $this->bankStatement(['valor' => 20]);
        $payment = $this->payment(['bank_statement_id' => $bank->id, 'amount' => 30, 'source' => Payment::SOURCE_BANK_STATEMENT]);
        $this->reconciliation($bank, $payment, null, ['valor_conciliado' => 25]);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertFinding($payload, 'reconciliation_amount_exceeds_bank_transaction', 'critical', bankTransactionId: $bank->id);
    }

    public function test_reconciliation_sum_above_payment_generates_critical(): void
    {
        $bank = $this->bankStatement(['valor' => 30]);
        $payment = $this->payment(['bank_statement_id' => $bank->id, 'amount' => 20, 'source' => Payment::SOURCE_BANK_STATEMENT]);
        $this->reconciliation($bank, $payment, null, ['valor_conciliado' => 25]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'reconciliation_amount_exceeds_payment', 'critical', paymentId: $payment->id);
    }

    public function test_confirmed_allocations_above_payment_generates_critical(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 25, 'unallocated_amount' => 0]);
        $allocation = $this->allocation($payment, ['amount' => 25]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'payment_allocation_exceeds_payment', 'critical', paymentId: $payment->id, allocationId: $allocation->id);
    }

    public function test_paid_invoice_with_required_bank_payment_without_trace_generates_warning(): void
    {
        $invoice = $this->invoice(['valor_total' => 20, 'valor_pago' => 20, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $payment = $this->payment([
            'amount' => 20,
            'allocated_amount' => 20,
            'unallocated_amount' => 0,
            'method' => 'transferencia',
            'source' => Payment::SOURCE_RECONCILIATION,
            'bank_statement_id' => null,
        ]);
        $allocation = $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 20]);

        $payload = $this->jsonPayload(['--invoice' => $invoice->id]);

        $this->assertFinding($payload, 'paid_invoice_without_reconciled_payment', 'warning', paymentId: $payment->id, allocationId: $allocation->id, invoiceId: $invoice->id);
    }

    public function test_reconciled_payment_without_allocation_or_credit_generates_warning(): void
    {
        $bank = $this->bankStatement(['valor' => 20]);
        $payment = $this->payment([
            'bank_statement_id' => $bank->id,
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
            'source' => Payment::SOURCE_BANK_STATEMENT,
        ]);
        $this->reconciliation($bank, $payment, null, ['valor_conciliado' => 20]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'reconciled_payment_without_invoice_allocation', 'warning', paymentId: $payment->id);
    }

    public function test_reconciled_payment_with_account_credit_does_not_generate_unallocated_warning(): void
    {
        $bank = $this->bankStatement(['valor' => 20]);
        $payment = $this->payment([
            'bank_statement_id' => $bank->id,
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
            'source' => Payment::SOURCE_BANK_STATEMENT,
        ]);
        $this->reconciliation($bank, $payment, null, ['valor_conciliado' => 20]);
        AccountCredit::query()->create([
            'user_id' => $payment->user_id,
            'payment_id' => $payment->id,
            'amount' => 20,
            'remaining_amount' => 20,
            'source' => AccountCredit::SOURCE_PAYMENT_OVERALLOCATION,
            'status' => AccountCredit::STATUS_AVAILABLE,
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertNoFinding($payload, 'reconciled_payment_without_invoice_allocation', paymentId: $payment->id);
    }

    public function test_cancelled_payment_with_active_reconciliation_generates_warning(): void
    {
        $bank = $this->bankStatement(['valor' => 20]);
        $payment = $this->payment([
            'bank_statement_id' => $bank->id,
            'status' => Payment::STATUS_CANCELLED,
            'amount' => 20,
            'source' => Payment::SOURCE_BANK_STATEMENT,
        ]);
        $this->reconciliation($bank, $payment, null, ['valor_conciliado' => 20]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'cancelled_payment_still_reconciled', 'warning', paymentId: $payment->id);
    }

    public function test_cancelled_reconciliation_status_is_ignored_for_active_amounts(): void
    {
        $bank = $this->bankStatement(['valor' => 20]);
        $payment = $this->payment([
            'bank_statement_id' => $bank->id,
            'amount' => 20,
            'source' => Payment::SOURCE_BANK_STATEMENT,
        ]);
        $this->reconciliation($bank, $payment, null, ['valor_conciliado' => 40, 'status' => 'cancelled']);

        $payload = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        $this->assertNoFinding($payload, 'reconciliation_amount_exceeds_bank_transaction', bankTransactionId: $bank->id);
    }

    public function test_date_sequence_inconsistent_generates_info(): void
    {
        $bank = $this->bankStatement(['valor' => 20, 'data_movimento' => '2026-07-10']);
        $payment = $this->payment([
            'bank_statement_id' => $bank->id,
            'amount' => 20,
            'payment_date' => '2026-09-20',
            'source' => Payment::SOURCE_BANK_STATEMENT,
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertFinding($payload, 'date_sequence_inconsistent', 'info', paymentId: $payment->id);
    }

    public function test_filters_from_to_bank_transaction_payment_invoice_and_user_work(): void
    {
        [$bankA, $paymentA, $invoiceA] = $this->cleanReconciledChain(['data_movimento' => '2026-07-10'], ['payment_date' => '2026-07-10']);
        [$bankB, $paymentB] = $this->cleanReconciledChain(['data_movimento' => '2026-08-10'], ['payment_date' => '2026-08-10']);

        $this->assertSame(1, $this->jsonPayload(['--from' => '2026-08-01', '--to' => '2026-08-31'])['summary']['total_bank_transactions_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--bank-transaction' => $bankA->id])['summary']['total_bank_transactions_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--payment' => $paymentA->id])['summary']['total_payments_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--invoice' => $invoiceA->id])['summary']['total_invoices_touched']);
        $this->assertSame(1, $this->jsonPayload(['--user' => $paymentB->user_id])['summary']['total_payments_scanned']);
    }

    public function test_only_actionable_filters_info_findings(): void
    {
        $bank = $this->bankStatement([
            'valor' => 25,
            'descricao' => 'Entrada sem referencia operacional',
        ]);

        $payload = $this->jsonPayload([
            '--bank-transaction' => $bank->id,
            '--only-actionable' => true,
        ]);

        $this->assertSame([], $payload['findings']);
    }

    public function test_fail_on_warning_does_not_fail_when_only_unpaid_bank_transactions_are_infos(): void
    {
        $bank = $this->bankStatement([
            'valor' => 25,
            'descricao' => 'Entrada sem referencia operacional',
        ]);

        $exitCode = Artisan::call('finance:audit-bank-reconciliation', [
            '--bank-transaction' => $bank->id,
            '--fail-on-warning' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_json_output_includes_schema_detected(): void
    {
        $this->bankStatement();

        $payload = $this->jsonPayload();

        $this->assertContains('bank_statements', $payload['schema_detected']['bank_transaction_tables']);
        $this->assertContains('mapa_conciliacao', $payload['schema_detected']['reconciliation_tables']);
    }

    public function test_report_path_writes_json_file(): void
    {
        $this->bankStatement();
        $relativePath = 'storage/app/audits/bank-reconciliation-audit-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:audit-bank-reconciliation', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);
    }

    public function test_fail_on_critical_returns_exit_one(): void
    {
        $bank = $this->bankStatement(['valor' => 20]);
        $this->payment(['bank_statement_id' => $bank->id, 'amount' => 20, 'source' => Payment::SOURCE_BANK_STATEMENT]);
        $this->payment(['bank_statement_id' => $bank->id, 'amount' => 20, 'source' => Payment::SOURCE_BANK_STATEMENT]);

        $exitCode = Artisan::call('finance:audit-bank-reconciliation', [
            '--bank-transaction' => $bank->id,
            '--fail-on-critical' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_fail_on_warning_returns_exit_one(): void
    {
        $user = User::factory()->create([
            'name' => 'Bruno Costa',
            'nome_completo' => 'Bruno Costa',
        ]);
        $this->invoice([
            'user_id' => $user->id,
            'valor_total' => 25,
            'valor_pago' => 0,
            'valor_em_aberto' => 25,
            'estado_pagamento' => 'pendente',
        ]);
        $bank = $this->bankStatement([
            'valor' => 25,
            'descricao' => 'Pagamento mensalidade Bruno Costa',
            'data_movimento' => '2026-07-10',
        ]);

        $exitCode = Artisan::call('finance:audit-bank-reconciliation', [
            '--bank-transaction' => $bank->id,
            '--fail-on-warning' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_read_only_snapshot_stays_equal(): void
    {
        [$bank] = $this->cleanReconciledChain();
        $before = $this->snapshot();

        $this->jsonPayload(['--bank-transaction' => $bank->id, '--include-clean' => true, '--include-deleted' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $bankOverrides
     * @param array<string,mixed> $paymentOverrides
     * @return array{0:BankStatement,1:Payment,2:Invoice,3:PaymentAllocation}
     */
    private function cleanReconciledChain(array $bankOverrides = [], array $paymentOverrides = []): array
    {
        $user = User::factory()->create();
        $invoice = $this->invoice([
            'user_id' => $user->id,
            'valor_total' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-07-10',
        ]);
        $bank = $this->bankStatement(array_merge([
            'valor' => 20,
            'data_movimento' => '2026-07-10',
            'conciliado' => true,
            'valor_conciliado' => 20,
            'valor_por_conciliar' => 0,
            'conciliacao_status' => 'reconciled',
        ], $bankOverrides));
        $payment = $this->payment(array_merge([
            'user_id' => $user->id,
            'bank_statement_id' => $bank->id,
            'amount' => 20,
            'allocated_amount' => 20,
            'unallocated_amount' => 0,
            'payment_date' => '2026-07-10',
            'source' => Payment::SOURCE_BANK_STATEMENT,
            'method' => 'transferencia',
        ], $paymentOverrides));
        $allocation = $this->allocation($payment, [
            'invoice_id' => $invoice->id,
            'amount' => 20,
            'allocated_at' => '2026-07-10 12:00:00',
        ]);
        $entry = $this->financialEntry($invoice, $payment, $allocation, $bank);
        $allocation->forceFill(['financial_entry_id' => $entry->id])->save();
        $this->reconciliation($bank, $payment, $allocation, [
            'lancamento_id' => $entry->id,
            'fatura_id' => $invoice->id,
            'valor_conciliado' => 20,
        ]);

        BankTransactionAllocation::query()->create([
            'bank_statement_id' => $bank->id,
            'invoice_id' => $invoice->id,
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation->id,
            'valor_alocado' => 20,
            'status' => BankTransactionAllocation::STATUS_CONFIRMED,
            'origem' => 'test',
        ]);

        return [$bank, $payment, $invoice, $allocation];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-bank-reconciliation', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(
        array $payload,
        string $code,
        string $severity,
        ?string $bankTransactionId = null,
        ?string $paymentId = null,
        ?string $allocationId = null,
        ?string $invoiceId = null,
    ): void {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['severity'] === $severity
                && ($bankTransactionId === null || $finding['bank_transaction_id'] === $bankTransactionId)
                && ($paymentId === null || $finding['payment_id'] === $paymentId)
                && ($allocationId === null || $finding['allocation_id'] === $allocationId)
                && ($invoiceId === null || $finding['invoice_id'] === $invoiceId),
        );

        $this->assertIsArray($finding, sprintf('Missing finding %s/%s.', $severity, $code));
    }

    private function assertNoFinding(array $payload, string $code, ?string $bankTransactionId = null, ?string $paymentId = null): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && ($bankTransactionId === null || $finding['bank_transaction_id'] === $bankTransactionId)
                && ($paymentId === null || $finding['payment_id'] === $paymentId),
        );

        $this->assertNull($finding, sprintf('Unexpected finding %s.', $code));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function bankStatement(array $overrides = []): BankStatement
    {
        return BankStatement::query()->create(array_merge([
            'data_movimento' => '2026-07-10',
            'descricao' => 'Transferencia bancaria',
            'referencia' => 'TRF-1',
            'valor' => 20,
            'saldo' => 20,
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 20,
            'conciliacao_status' => 'unreconciled',
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function payment(array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
            'payment_date' => '2026-07-10',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function invoice(array $overrides = []): Invoice
    {
        $invoice = Invoice::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'data_fatura' => '2026-07-01',
            'data_emissao' => '2026-07-01',
            'data_vencimento' => '2026-07-15',
            'mes' => '2026-07',
            'valor_total' => 20,
            'valor_pago' => 0,
            'valor_em_aberto' => 20,
            'estado_pagamento' => 'pendente',
            'tipo' => 'material',
            'origem_tipo' => 'manual',
        ], $overrides));

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Linha auditada',
            'quantidade' => 1,
            'valor_unitario' => $invoice->valor_total,
            'imposto_percentual' => 0,
            'total_linha' => $invoice->valor_total,
        ]);

        return $invoice->fresh('items');
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function allocation(Payment $payment, array $overrides = []): PaymentAllocation
    {
        return PaymentAllocation::query()->create(array_merge([
            'payment_id' => $payment->id,
            'invoice_id' => null,
            'financial_entry_id' => null,
            'amount' => 20,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => '2026-07-10 12:00:00',
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function reconciliation(BankStatement $bank, Payment $payment, ?PaymentAllocation $allocation, array $overrides = []): MapaConciliacao
    {
        return MapaConciliacao::query()->create(array_merge([
            'extrato_id' => $bank->id,
            'lancamento_id' => $overrides['lancamento_id'] ?? $this->bareFinancialEntry()->id,
            'fatura_id' => $allocation?->invoice_id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation?->id,
            'valor_conciliado' => 20,
            'status' => 'confirmado',
            'regra_usada' => 'test',
        ], $overrides));
    }

    private function financialEntry(Invoice $invoice, Payment $payment, PaymentAllocation $allocation, BankStatement $bank): FinancialEntry
    {
        return FinancialEntry::query()->create([
            'data' => '2026-07-10',
            'tipo' => 'receita',
            'categoria' => 'Pagamento de Fatura',
            'descricao' => 'Pagamento auditado',
            'valor' => $allocation->amount,
            'valor_pago' => $allocation->amount,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'fatura_id' => $invoice->id,
            'payment_id' => $payment->id,
            'bank_statement_id' => $bank->id,
            'origem_tipo' => 'payment_allocation',
            'origem_id' => $allocation->id,
        ]);
    }

    private function bareFinancialEntry(): FinancialEntry
    {
        return FinancialEntry::query()->create([
            'data' => '2026-07-10',
            'tipo' => 'receita',
            'categoria' => 'Teste',
            'descricao' => 'Entrada auditada',
            'valor' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'bank_statements' => BankStatement::query()->orderBy('id')->get()->toArray(),
            'mapa_conciliacao' => MapaConciliacao::query()->orderBy('id')->get()->toArray(),
            'bank_transaction_allocations' => BankTransactionAllocation::query()->orderBy('id')->get()->toArray(),
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'payment_allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->toArray(),
        ];
    }
}

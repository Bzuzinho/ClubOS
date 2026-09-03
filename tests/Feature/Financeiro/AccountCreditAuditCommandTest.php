<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\AccountCreditUsage;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountCreditAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));

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

    public function test_no_account_credit_records_returns_info_without_failure(): void
    {
        $payload = $this->jsonPayload();

        $this->assertSame(0, $payload['summary']['total_account_credits_scanned']);
        $this->assertFinding($payload, 'account_credit_no_records', 'info');
        $this->assertSame(0, $payload['summary']['critical_count']);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['info_count']);
    }

    public function test_clean_account_credit_has_no_critical_or_warning_findings(): void
    {
        $credit = $this->cleanCredit();

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertSame(1, $payload['summary']['total_account_credits_scanned']);
        $this->assertSame(0, $payload['summary']['critical_count']);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame([], $payload['findings']);
    }

    public function test_negative_amount_is_critical(): void
    {
        $credit = $this->credit(['amount' => -10, 'remaining_amount' => 0]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_negative_amount', 'critical', creditId: $credit->id);
    }

    public function test_negative_balance_is_critical(): void
    {
        $credit = $this->credit(['amount' => 20, 'remaining_amount' => -5, 'status' => AccountCredit::STATUS_USED]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_negative_balance', 'critical', creditId: $credit->id);
        $this->assertFinding($payload, 'account_credit_used_amount_exceeds_amount', 'critical', creditId: $credit->id);
    }

    public function test_balance_above_amount_is_critical(): void
    {
        $credit = $this->credit(['amount' => 20, 'remaining_amount' => 25]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_balance_exceeds_amount', 'critical', creditId: $credit->id);
    }

    public function test_used_status_with_available_balance_is_warning(): void
    {
        $credit = $this->credit(['amount' => 20, 'remaining_amount' => 5, 'status' => AccountCredit::STATUS_USED]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_status_balance_mismatch', 'warning', creditId: $credit->id);
    }

    public function test_cancelled_credit_with_available_balance_is_warning(): void
    {
        $credit = $this->credit(['amount' => 20, 'remaining_amount' => 5, 'status' => AccountCredit::STATUS_CANCELLED]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_cancelled_with_available_balance', 'warning', creditId: $credit->id);
    }

    public function test_missing_origin_payment_is_warning_for_overpayment_credit(): void
    {
        $credit = $this->credit(['payment_id' => null, 'source' => 'overpayment']);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_origin_missing', 'warning', creditId: $credit->id);
    }

    public function test_missing_payment_origin_reference_is_critical_when_modelled(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Este fixture de FK quebrada esta preparado para SQLite local.');
        }

        try {
            DB::statement('PRAGMA foreign_keys = OFF');
            $credit = $this->credit([
                'payment_id' => (string) Str::uuid(),
                'source' => 'overpayment',
            ]);
        } catch (QueryException) {
            $this->markTestSkipped('O schema atual impede modelar account_credits.payment_id inexistente com FKs ativas.');
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_origin_not_found', 'critical', creditId: $credit->id);
    }

    public function test_missing_financial_entry_trace_is_warning(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 0]);
        $credit = $this->credit(['payment_id' => $payment->id, 'user_id' => $payment->user_id, 'amount' => 20, 'remaining_amount' => 20]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_open_without_usage_trace', 'warning', creditId: $credit->id);
    }

    public function test_duplicate_financial_entry_trace_is_warning(): void
    {
        $credit = $this->cleanCredit();
        $this->financialEntry($credit);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_duplicate_usage', 'warning', creditId: $credit->id);
    }

    public function test_financial_entry_amount_mismatch_is_warning(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 0]);
        $credit = $this->credit(['payment_id' => $payment->id, 'user_id' => $payment->user_id, 'amount' => 20, 'remaining_amount' => 20]);
        $this->financialEntry($credit, ['valor' => 15]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_balance_differs_from_usages', 'warning', creditId: $credit->id);
    }

    public function test_payment_unallocated_mismatch_is_warning(): void
    {
        $payment = $this->payment(['amount' => 30, 'allocated_amount' => 10, 'unallocated_amount' => 5]);
        $invoice = $this->invoice(['user_id' => $payment->user_id, 'valor_total' => 10, 'valor_pago' => 10, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 10]);
        $credit = $this->credit(['payment_id' => $payment->id, 'user_id' => $payment->user_id, 'amount' => 20, 'remaining_amount' => 20]);
        $this->financialEntry($credit);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_payment_unallocated_mismatch', 'warning', creditId: $credit->id);
    }

    public function test_used_credit_without_active_usage_is_critical_with_usage_ledger(): void
    {
        $credit = $this->cleanCredit(['status' => AccountCredit::STATUS_USED, 'remaining_amount' => 0]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_balance_differs_from_usages', 'critical', creditId: $credit->id);
    }

    public function test_usage_above_credit_amount_is_critical(): void
    {
        $credit = $this->cleanCredit(['amount' => 20, 'remaining_amount' => 0, 'status' => AccountCredit::STATUS_USED]);
        $invoice = $this->invoice(['user_id' => $credit->user_id, 'valor_total' => 25, 'valor_pago' => 25, 'valor_em_aberto' => 0, 'estado_pagamento' => 'pago']);
        $this->usage($credit, $invoice, ['amount' => 25]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_usage_exceeds_amount', 'critical', creditId: $credit->id);
    }

    public function test_usage_on_cancelled_invoice_is_warning(): void
    {
        $credit = $this->cleanCredit(['amount' => 20, 'remaining_amount' => 10, 'status' => AccountCredit::STATUS_PARTIALLY_USED]);
        $invoice = $this->invoice(['user_id' => $credit->user_id, 'estado_pagamento' => 'cancelado']);
        $this->usage($credit, $invoice, ['amount' => 10]);

        $payload = $this->jsonPayload(['--credit' => $credit->id]);

        $this->assertFinding($payload, 'account_credit_usage_cancelled_invoice', 'warning', creditId: $credit->id);
    }

    public function test_json_output_report_path_and_schema_detection(): void
    {
        $credit = $this->cleanCredit();
        $relativePath = 'storage/app/audits/account-credit-audit-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:audit-account-credits', [
            '--credit' => $credit->id,
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $payload = json_decode(substr($output, (int) strpos($output, '{')), true);
        $this->assertIsArray($payload);
        $this->assertTrue($payload['detected_models']['account_credit']);
        $this->assertSame(['PaymentReversal'], $payload['schema_detected']['refund_reversal_entities_detected']);
        $this->assertTrue($payload['schema_detected']['usage_tables_detected']);
        $this->assertContains('account_credit_usages', $payload['schema_detected']['usage_tables']);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);
    }

    public function test_filters_credit_user_payment_invoice_from_to_and_only_open_work(): void
    {
        $creditA = $this->cleanCredit();
        $paymentA = Payment::query()->findOrFail($creditA->payment_id);
        $invoiceA = PaymentAllocation::query()->where('payment_id', $paymentA->id)->firstOrFail()->invoice;
        $creditB = $this->cleanCredit();
        $creditB->forceFill([
            'created_at' => '2026-08-10 12:00:00',
            'updated_at' => '2026-08-10 12:00:00',
        ])->save();

        $this->assertSame(1, $this->jsonPayload(['--credit' => $creditA->id])['summary']['total_account_credits_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--user' => $creditA->user_id])['summary']['total_account_credits_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--payment' => $paymentA->id])['summary']['total_account_credits_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--invoice' => $invoiceA->id])['summary']['total_account_credits_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--from' => '2026-08-01', '--to' => '2026-08-31'])['summary']['total_account_credits_scanned']);
        $this->assertSame(2, $this->jsonPayload(['--only-open' => true])['summary']['total_account_credits_scanned']);
        $this->assertNotSame($creditA->id, $creditB->id);
    }

    public function test_fail_flags_return_expected_exit_codes(): void
    {
        $criticalCredit = $this->credit(['amount' => -10, 'remaining_amount' => 0]);
        $warningCredit = $this->credit(['amount' => 20, 'remaining_amount' => 0, 'status' => AccountCredit::STATUS_AVAILABLE]);

        $this->assertSame(1, Artisan::call('finance:audit-account-credits', [
            '--credit' => $criticalCredit->id,
            '--fail-on-critical' => true,
        ]));

        $this->assertSame(1, Artisan::call('finance:audit-account-credits', [
            '--credit' => $warningCredit->id,
            '--fail-on-warning' => true,
        ]));
    }

    public function test_audit_is_read_only(): void
    {
        $credit = $this->cleanCredit();
        $before = $this->snapshot();

        $this->jsonPayload(['--credit' => $credit->id, '--include-deleted' => true]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-account-credits', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertFinding(array $payload, string $code, string $severity, ?string $creditId = null): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['severity'] === $severity
                && ($creditId === null || $finding['account_credit_id'] === $creditId),
        );

        $this->assertIsArray($finding, sprintf('Missing finding %s/%s.', $severity, $code));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function cleanCredit(array $overrides = []): AccountCredit
    {
        $user = User::factory()->create();
        $payment = $this->payment([
            'user_id' => $user->id,
            'amount' => 30,
            'allocated_amount' => 10,
            'unallocated_amount' => 0,
        ]);
        $invoice = $this->invoice([
            'user_id' => $user->id,
            'valor_total' => 10,
            'valor_pago' => 10,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
        ]);
        $this->allocation($payment, ['invoice_id' => $invoice->id, 'amount' => 10]);
        $credit = $this->credit(array_merge([
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'amount' => 20,
            'remaining_amount' => 20,
            'status' => AccountCredit::STATUS_AVAILABLE,
            'source' => 'overpayment',
        ], $overrides));
        $this->financialEntry($credit);

        return $credit;
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function credit(array $overrides = []): AccountCredit
    {
        return AccountCredit::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'family_id' => null,
            'payment_id' => null,
            'amount' => 20,
            'remaining_amount' => 20,
            'source' => 'manual',
            'status' => AccountCredit::STATUS_AVAILABLE,
            'description' => 'Credito auditado',
            'created_at' => '2026-07-15 12:00:00',
            'updated_at' => '2026-07-15 12:00:00',
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
            'descricao' => 'Linha credito',
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
    private function financialEntry(AccountCredit $credit, array $overrides = []): FinancialEntry
    {
        return FinancialEntry::query()->create(array_merge([
            'data' => '2026-07-10',
            'tipo' => 'receita',
            'categoria' => 'Credito em Conta Corrente',
            'descricao' => 'Excedente convertido em credito de conta corrente',
            'valor' => $credit->amount,
            'valor_pago' => 0,
            'valor_em_aberto' => $credit->amount,
            'estado' => 'pendente',
            'user_id' => $credit->user_id,
            'payment_id' => $credit->payment_id,
            'origem_tipo' => 'account_credit',
            'origem_id' => $credit->id,
        ], $overrides));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function usage(AccountCredit $credit, Invoice $invoice, array $overrides = []): AccountCreditUsage
    {
        return AccountCreditUsage::query()->create(array_merge([
            'account_credit_id' => $credit->id,
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'status' => AccountCreditUsage::STATUS_APPLIED,
            'applied_at' => '2026-07-15 12:00:00',
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'account_credits' => AccountCredit::withTrashed()->orderBy('id')->get()->toArray(),
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'payment_allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'account_credit_usages' => AccountCreditUsage::withTrashed()->orderBy('id')->get()->toArray(),
            'financial_entries' => FinancialEntry::query()->orderBy('id')->get()->toArray(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class UnallocatedPaymentAuditCommandTest extends TestCase
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

    public function test_cancelled_unallocated_payment_without_active_allocation_is_info(): void
    {
        $payment = $this->payment([
            'status' => Payment::STATUS_CANCELLED,
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
        ]);

        $payload = $this->jsonPayload([
            '--payment' => $payment->id,
            '--include-cancelled' => true,
        ]);

        $this->assertItem($payload, 'cancelled_stale_unallocated_payment', 'info', false, $payment->id);
        $this->assertSame(0, $payload['summary']['warning_count']);
        $this->assertSame(1, $payload['summary']['info_count']);
    }

    public function test_confirmed_unallocated_payment_without_credit_is_warning_actionable(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 20]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertItem($payload, 'unallocated_payment_candidate_for_account_credit', 'warning', true, $payment->id);
        $this->assertSame('confirmed_unallocated_payment_without_credit', $payload['items'][0]['balance_classification']);
        $this->assertSame('confirmed_unallocated_payment_without_credit', $payload['findings'][0]['balance_code']);
        $this->assertSame(1, $payload['summary']['candidate_account_credit_count']);
    }

    public function test_confirmed_unallocated_payment_with_existing_credit_is_info_when_coherent(): void
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
        $this->credit($payment, ['amount' => 20, 'remaining_amount' => 20]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertItem($payload, 'confirmed_unallocated_payment_with_credit', 'info', false, $payment->id);
        $this->assertSame(1, $payload['summary']['with_existing_credit_count']);
    }

    public function test_existing_credit_remaining_amount_divergence_is_warning(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 0]);
        $this->credit($payment, ['amount' => 20, 'remaining_amount' => 25]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertItem($payload, 'confirmed_unallocated_payment_with_credit', 'warning', true, $payment->id);
    }

    public function test_payment_unallocated_amount_inconsistent_is_warning_actionable(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 0, 'unallocated_amount' => 5]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertItem($payload, 'payment_unallocated_amount_inconsistent', 'warning', true, $payment->id);
    }

    public function test_payment_allocated_amount_inconsistent_is_warning_actionable(): void
    {
        $payment = $this->payment(['amount' => 20, 'allocated_amount' => 5, 'unallocated_amount' => 15]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertItem($payload, 'payment_allocated_amount_inconsistent', 'warning', true, $payment->id);
    }

    public function test_confirmed_unallocated_payment_without_owner_requires_review(): void
    {
        $payment = $this->payment([
            'user_id' => null,
            'family_id' => null,
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
        ]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertItem($payload, 'unallocated_payment_without_user_or_family', 'warning', true, $payment->id);
        $this->assertSame(1, $payload['summary']['ownership_review_count']);
    }

    public function test_confirmed_unallocated_payment_with_open_invoices_should_allocate_before_credit(): void
    {
        $user = User::factory()->create();
        $payment = $this->payment(['user_id' => $user->id, 'amount' => 20, 'unallocated_amount' => 20]);
        $this->invoice(['user_id' => $user->id, 'valor_total' => 15, 'valor_pago' => 0, 'valor_em_aberto' => 15]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertItem($payload, 'unallocated_payment_has_open_invoices', 'warning', true, $payment->id);
        $this->assertSame(1, $payload['summary']['open_invoice_allocation_candidate_count']);
        $this->assertEquals(15.0, $payload['items'][0]['open_invoice_amount_for_owner']);
    }

    public function test_confirmed_unallocated_payment_without_open_invoices_is_account_credit_candidate(): void
    {
        $payment = $this->payment(['amount' => 20, 'unallocated_amount' => 20]);

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertItem($payload, 'unallocated_payment_candidate_for_account_credit', 'warning', true, $payment->id);
    }

    public function test_only_actionable_filters_historical_infos(): void
    {
        $this->payment([
            'status' => Payment::STATUS_CANCELLED,
            'amount' => 20,
            'allocated_amount' => 0,
            'unallocated_amount' => 20,
        ]);
        $actionable = $this->payment(['amount' => 30, 'unallocated_amount' => 30]);

        $payload = $this->jsonPayload([
            '--include-cancelled' => true,
            '--only-actionable' => true,
        ]);

        $this->assertSame(1, $payload['summary']['total_unallocated_payments']);
        $this->assertSame($actionable->id, $payload['items'][0]['payment_id']);
    }

    public function test_filters_payment_user_and_dates_work(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $paymentA = $this->payment([
            'user_id' => $userA->id,
            'amount' => 20,
            'unallocated_amount' => 20,
            'payment_date' => '2026-07-10',
        ]);
        $paymentB = $this->payment([
            'user_id' => $userB->id,
            'amount' => 30,
            'unallocated_amount' => 30,
            'payment_date' => '2026-08-10',
        ]);

        $this->assertSame(1, $this->jsonPayload(['--payment' => $paymentA->id])['summary']['total_payments_scanned']);
        $this->assertSame(1, $this->jsonPayload(['--user' => $userA->id])['summary']['total_payments_scanned']);
        $payload = $this->jsonPayload(['--from' => '2026-08-01', '--to' => '2026-08-31']);
        $this->assertSame(1, $payload['summary']['total_payments_scanned']);
        $this->assertSame($paymentB->id, $payload['items'][0]['payment_id']);
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        $payment = $this->payment();
        $relativePath = 'storage/app/audits/unallocated-payment-audit-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:audit-unallocated-payments', [
            '--payment' => $payment->id,
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $output = Artisan::output();
        $payload = json_decode(substr($output, (int) strpos($output, '{')), true);
        $this->assertIsArray($payload);
        $this->assertSame('a4-4-unallocated-payment-audit-v1', $payload['version']);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);
    }

    public function test_fail_flags_return_exit_one_for_actionable_or_warning(): void
    {
        $payment = $this->payment(['amount' => 20, 'unallocated_amount' => 20]);

        $this->assertSame(1, Artisan::call('finance:audit-unallocated-payments', [
            '--payment' => $payment->id,
            '--fail-on-actionable' => true,
        ]));

        $this->assertSame(1, Artisan::call('finance:audit-unallocated-payments', [
            '--payment' => $payment->id,
            '--fail-on-warning' => true,
        ]));
    }

    public function test_audit_is_read_only(): void
    {
        $payment = $this->payment(['amount' => 20, 'unallocated_amount' => 20]);
        $invoice = $this->invoice(['user_id' => $payment->user_id, 'valor_total' => 10, 'valor_em_aberto' => 10]);
        $allocation = $this->allocation($payment, [
            'invoice_id' => $invoice->id,
            'amount' => 0,
            'status' => PaymentAllocation::STATUS_CANCELLED,
        ]);
        $allocation->delete();
        $this->credit($payment, ['amount' => 5, 'remaining_amount' => 5, 'status' => AccountCredit::STATUS_CANCELLED]);
        $before = $this->snapshot();

        $this->jsonPayload([
            '--payment' => $payment->id,
            '--include-cancelled' => true,
        ]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-unallocated-payments', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    private function assertItem(array $payload, string $classification, string $severity, bool $actionable, string $paymentId): void
    {
        $item = collect($payload['items'])->first(
            fn (array $item): bool => $item['payment_id'] === $paymentId
                && $item['classification'] === $classification
                && $item['severity'] === $severity
                && $item['actionable'] === $actionable,
        );

        $this->assertIsArray($item, sprintf('Missing item %s/%s.', $severity, $classification));
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['payment_id'] === $paymentId
                && $finding['code'] === $classification
                && $finding['severity'] === $severity
                && $finding['actionable'] === $actionable,
        );
        $this->assertIsArray($finding, sprintf('Missing finding %s/%s.', $severity, $classification));
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function payment(array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'family_id' => null,
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
            'oculta' => false,
        ], $overrides));

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Linha auditoria',
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
    private function credit(Payment $payment, array $overrides = []): AccountCredit
    {
        return AccountCredit::query()->create(array_merge([
            'user_id' => $payment->user_id,
            'family_id' => $payment->family_id,
            'payment_id' => $payment->id,
            'amount' => 20,
            'remaining_amount' => 20,
            'source' => AccountCredit::SOURCE_PAYMENT_OVERALLOCATION,
            'status' => AccountCredit::STATUS_AVAILABLE,
            'description' => 'Credito auditado',
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'payments' => Payment::withTrashed()->orderBy('id')->get()->toArray(),
            'payment_allocations' => PaymentAllocation::withTrashed()->orderBy('id')->get()->toArray(),
            'invoices' => Invoice::query()->orderBy('id')->get()->toArray(),
            'account_credits' => AccountCredit::withTrashed()->orderBy('id')->get()->toArray(),
        ];
    }
}

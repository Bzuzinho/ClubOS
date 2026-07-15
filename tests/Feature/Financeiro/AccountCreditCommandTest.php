<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\User;
use App\Services\Financeiro\AccountCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AccountCreditCommandTest extends TestCase
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

    public function test_create_credit_command_dry_run_does_not_change_data(): void
    {
        $payment = $this->payment(['amount' => 30, 'unallocated_amount' => 30]);

        $exitCode = Artisan::call('finance:create-account-credit-from-payment', [
            'payment' => $payment->id,
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode);
        $this->assertSame('dry-run', $payload['mode']);
        $this->assertFalse($payload['applied']);
        $this->assertDatabaseCount('account_credits', 0);
    }

    public function test_create_credit_command_apply_creates_credit_and_report(): void
    {
        $payment = $this->payment(['amount' => 30, 'unallocated_amount' => 30]);
        $relativePath = 'storage/app/audits/account-credit-create-command-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:create-account-credit-from-payment', [
            'payment' => $payment->id,
            '--apply' => true,
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['applied']);
        $this->assertDatabaseCount('account_credits', 1);
        $this->assertFileExists($absolutePath);
        @unlink($absolutePath);
    }

    public function test_apply_credit_command_dry_run_does_not_change_data(): void
    {
        [$credit, $invoice] = $this->creditAndInvoice();

        $exitCode = Artisan::call('finance:apply-account-credit', [
            'credit' => $credit->id,
            'invoice' => $invoice->id,
            '--amount' => 10,
            '--json' => true,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode);
        $this->assertSame('dry-run', $payload['mode']);
        $this->assertDatabaseCount('account_credit_usages', 0);
        $this->assertSame('0.00', $invoice->fresh()->valor_pago);
    }

    public function test_apply_credit_command_apply_updates_credit_invoice_and_report(): void
    {
        [$credit, $invoice] = $this->creditAndInvoice();
        $relativePath = 'storage/app/audits/account-credit-apply-command-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:apply-account-credit', [
            'credit' => $credit->id,
            'invoice' => $invoice->id,
            '--amount' => 10,
            '--apply' => true,
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['applied']);
        $this->assertDatabaseCount('account_credit_usages', 1);
        $this->assertSame('10.00', $invoice->fresh()->valor_pago);
        $this->assertSame('10.00', $credit->fresh()->remaining_amount);
        $this->assertFileExists($absolutePath);
        @unlink($absolutePath);
    }

    /**
     * @return array{0: AccountCredit, 1: Invoice}
     */
    private function creditAndInvoice(): array
    {
        $user = User::factory()->create();
        $payment = $this->payment(['user_id' => $user->id, 'amount' => 20, 'unallocated_amount' => 20]);
        $credit = app(AccountCreditService::class)->createFromPaymentOverpayment($payment);
        $invoice = $this->invoice(['user_id' => $user->id, 'valor_total' => 20, 'valor_em_aberto' => 20]);

        return [$credit, $invoice];
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
}

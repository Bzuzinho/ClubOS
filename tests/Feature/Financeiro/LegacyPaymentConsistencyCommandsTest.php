<?php

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LegacyPaymentConsistencyCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_lists_detailed_orphan_and_mismatch_payment_sections(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('AUDITPAY');

        $statement = $this->createBankStatement($costCenter, 72.00, [
            'conciliado' => false,
            'conciliacao_status' => 'unreconciled',
            'valor_conciliado' => 0,
        ]);

        $orphanReconciliation = $this->createPayment($user, 72.00, [
            'source' => Payment::SOURCE_RECONCILIATION,
            'bank_statement_id' => $statement->id,
            'allocated_amount' => 0,
            'unallocated_amount' => 72.00,
            'reference' => 'ORPHAN-RECON',
        ]);

        $orphanManual = $this->createPayment($user, 25.00, [
            'source' => Payment::SOURCE_MANUAL,
            'allocated_amount' => 0,
            'unallocated_amount' => 25.00,
            'reference' => 'ORPHAN-MANUAL',
        ]);

        $mismatch = $this->createPayment($user, 30.00, [
            'source' => Payment::SOURCE_RECONCILIATION,
            'allocated_amount' => 30.00,
            'unallocated_amount' => 0,
            'reference' => 'MISMATCH',
        ]);
        $this->createAllocation($mismatch, 10.00);

        Artisan::call('financeiro:audit-legacy-consistency');
        $output = Artisan::output();

        $this->assertStringContainsString('Payments de reconciliacao orfaos', $output);
        $this->assertStringContainsString('Payments manuais orfaos', $output);
        $this->assertStringContainsString('Payments com allocated_amount/unallocated_amount incoerente', $output);
        $this->assertStringContainsString($orphanReconciliation->id, $output);
        $this->assertStringContainsString($orphanManual->id, $output);
        $this->assertStringContainsString($mismatch->id, $output);
    }

    public function test_payment_repair_dry_run_does_not_change_database(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('DRYRUNPAY');
        $statement = $this->createBankStatement($costCenter, 72.00, [
            'conciliado' => false,
            'conciliacao_status' => 'unreconciled',
            'valor_conciliado' => 0,
        ]);

        $payment = $this->createPayment($user, 72.00, [
            'source' => Payment::SOURCE_RECONCILIATION,
            'bank_statement_id' => $statement->id,
            'allocated_amount' => 0,
            'unallocated_amount' => 72.00,
        ]);

        $this->artisan('financeiro:repair-legacy-payments')
            ->assertExitCode(0);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertNull($payment->cancelled_at);
        $this->assertSame('72.00', $payment->unallocated_amount);
    }

    public function test_orphan_reconciliation_payment_is_cancelled_with_commit(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('RECONCANCEL');
        $statement = $this->createBankStatement($costCenter, 72.00, [
            'conciliado' => false,
            'conciliacao_status' => 'unreconciled',
            'valor_conciliado' => 0,
        ]);

        $payment = $this->createPayment($user, 72.00, [
            'source' => Payment::SOURCE_RECONCILIATION,
            'bank_statement_id' => $statement->id,
            'allocated_amount' => 0,
            'unallocated_amount' => 72.00,
        ]);

        $this->artisan('financeiro:repair-legacy-payments', ['--commit' => true])
            ->assertExitCode(0);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertStringContainsString('[legacy-repair]', (string) $payment->notes);
    }

    public function test_manual_orphan_is_not_cancelled_without_flag(): void
    {
        [$user] = $this->createFinanceContext('MANUALNOFLAG');
        $payment = $this->createPayment($user, 25.00, [
            'source' => Payment::SOURCE_MANUAL,
            'allocated_amount' => 0,
            'unallocated_amount' => 25.00,
        ]);

        $this->artisan('financeiro:repair-legacy-payments', ['--commit' => true])
            ->assertExitCode(0);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertNull($payment->cancelled_at);
    }

    public function test_manual_orphan_is_cancelled_with_explicit_flag(): void
    {
        [$user] = $this->createFinanceContext('MANUALFLAG');
        $payment = $this->createPayment($user, 25.00, [
            'source' => Payment::SOURCE_MANUAL,
            'allocated_amount' => 0,
            'unallocated_amount' => 25.00,
        ]);

        $this->artisan('financeiro:repair-legacy-payments', [
            '--commit' => true,
            '--cancel-manual-orphans' => true,
        ])->assertExitCode(0);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CANCELLED, $payment->status);
        $this->assertNotNull($payment->cancelled_at);
    }

    public function test_allocated_amount_mismatch_is_recalculated_from_confirmed_allocations(): void
    {
        [$user] = $this->createFinanceContext('MISMATCHFIX');
        $payment = $this->createPayment($user, 30.00, [
            'source' => Payment::SOURCE_RECONCILIATION,
            'allocated_amount' => 30.00,
            'unallocated_amount' => 0.00,
        ]);
        $this->createAllocation($payment, 10.00);

        $this->artisan('financeiro:repair-legacy-payments', ['--commit' => true])
            ->assertExitCode(0);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertSame('10.00', $payment->allocated_amount);
        $this->assertSame('20.00', $payment->unallocated_amount);
    }

    public function test_payment_with_active_account_credit_is_not_cancelled(): void
    {
        [$user] = $this->createFinanceContext('ACTIVECREDIT');
        $payment = $this->createPayment($user, 40.00, [
            'source' => Payment::SOURCE_RECONCILIATION,
            'allocated_amount' => 0.00,
            'unallocated_amount' => 40.00,
        ]);
        $this->createActiveCredit($user, $payment, 40.00);

        $this->artisan('financeiro:repair-legacy-payments', ['--commit' => true])
            ->assertExitCode(0);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertNull($payment->cancelled_at);
    }

    public function test_payment_with_reconciled_bank_statement_is_not_cancelled(): void
    {
        [$user, $costCenter] = $this->createFinanceContext('RECONCILEDSTMT');
        $statement = $this->createBankStatement($costCenter, 72.00, [
            'conciliado' => true,
            'conciliacao_status' => 'reconciled',
            'valor_conciliado' => 72.00,
            'valor_por_conciliar' => 0.00,
        ]);

        $payment = $this->createPayment($user, 72.00, [
            'source' => Payment::SOURCE_RECONCILIATION,
            'bank_statement_id' => $statement->id,
            'allocated_amount' => 0,
            'unallocated_amount' => 72.00,
        ]);

        $this->artisan('financeiro:repair-legacy-payments', ['--commit' => true])
            ->assertExitCode(0);

        $payment->refresh();

        $this->assertSame(Payment::STATUS_CONFIRMED, $payment->status);
        $this->assertNull($payment->cancelled_at);
    }

    /**
     * @return array{0: User, 1: CostCenter}
     */
    private function createFinanceContext(string $suffix): array
    {
        $user = User::factory()->create([
            'nome_completo' => 'Socio Payment ' . $suffix,
            'nif' => '123456789',
            'morada' => 'Rua Payments 1',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa',
            'email' => strtolower($suffix) . '@example.com',
        ]);

        $costCenter = CostCenter::create([
            'codigo' => 'CC-PAY-' . $suffix,
            'nome' => 'Centro Payments ' . $suffix,
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        return [$user, $costCenter];
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
            'description' => 'Pagamento legacy payment consistency',
            'source' => Payment::SOURCE_RECONCILIATION,
            'status' => Payment::STATUS_CONFIRMED,
            'notes' => null,
        ], $overrides));
    }

    private function createBankStatement(CostCenter $costCenter, float $amount, array $overrides = []): BankStatement
    {
        return BankStatement::create(array_merge([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-01-15',
            'descricao' => 'Extrato legacy payments',
            'valor' => $amount,
            'saldo' => $amount,
            'referencia' => 'BST-' . uniqid(),
            'centro_custo_id' => $costCenter->id,
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => $amount,
            'conciliacao_status' => 'unreconciled',
        ], $overrides));
    }

    private function createAllocation(Payment $payment, float $amount): PaymentAllocation
    {
        return PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => null,
            'amount' => $amount,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);
    }

    private function createActiveCredit(User $user, Payment $payment, float $amount): AccountCredit
    {
        return AccountCredit::create([
            'user_id' => $user->id,
            'family_id' => null,
            'payment_id' => $payment->id,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'source' => 'overpayment',
            'status' => AccountCredit::STATUS_AVAILABLE,
            'description' => 'Credito ativo legacy',
        ]);
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\FinancialEntry;
use App\Models\MonthlyFee;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use App\Services\Financeiro\MonthlyFeeOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MonthlyFeeOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_monthly_fee_has_canonical_owner_and_source(): void
    {
        $plan = MonthlyFee::query()->create([
            'designacao' => 'Mensalidade FEE1',
            'valor' => 35,
            'ativo' => true,
        ]);
        $user = User::factory()->create([
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);
        $athleteType = UserType::query()->firstOrCreate(
            ['codigo' => 'atleta'],
            ['nome' => 'Atleta', 'descricao' => 'Atleta', 'ativo' => true],
        );
        $user->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)->generateForUser(
            $user->fresh(['dadosFinanceiros.mensalidade', 'centrosCusto']),
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-01'),
            ['manual_trigger' => true],
        )->first();

        $this->assertNotNull($invoice);
        $this->assertSame($user->id, $invoice->user_id);
        $this->assertSame('monthly_fee', $invoice->origem_tipo);
        $this->assertSame($plan->id, $invoice->origem_id);
    }

    public function test_audit_detects_financial_entry_owner_mismatch(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $invoice = $this->monthlyInvoice($owner);
        FinancialEntry::query()->create([
            'data' => '2026-07-01',
            'tipo' => 'receita',
            'categoria' => 'Mensalidade',
            'descricao' => 'Entrada legacy',
            'valor' => 30,
            'user_id' => $other->id,
            'fatura_id' => $invoice->id,
        ]);

        $payload = app(MonthlyFeeOwnershipService::class)->audit();

        $this->assertSame(1, $payload['summary']['financial_entries_owner_mismatch_count']);
        $this->assertContains('financial_entry_owner_mismatch', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_repair_defaults_to_dry_run_and_does_not_change_data(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->monthlyInvoice($owner);
        $payment = $this->ownerlessPayment();
        $this->allocate($payment, $invoice);

        $this->artisan('finance:repair-monthly-fee-ownership', ['--json' => true])
            ->assertSuccessful();

        $payload = app(MonthlyFeeOwnershipService::class)->repair(false);
        $this->assertArrayHasKey('changes', $payload);
        $this->assertArrayHasKey('skipped', $payload);
        $this->assertSame($owner->id, $invoice->fresh()->user_id);
        $this->assertNull($payment->fresh()->user_id);
    }

    public function test_ownerless_payment_with_valid_allocated_invoice_is_planned_in_dry_run(): void
    {
        $owner = User::factory()->create();
        $payment = $this->ownerlessPayment();
        $this->allocate($payment, $this->monthlyInvoice($owner));

        $payload = app(MonthlyFeeOwnershipService::class)->repair(false);

        $this->assertSame(1, $payload['summary']['planned_change_count']);
        $this->assertSame([
            'table' => 'payments',
            'id' => $payment->id,
            'field' => 'user_id',
            'from' => null,
            'to' => $owner->id,
        ], $payload['changes'][0]);
        $this->assertNull($payment->fresh()->user_id);
    }

    public function test_confirmed_repair_applies_payment_owner_and_clears_audit_finding(): void
    {
        $owner = User::factory()->create();
        $payment = $this->ownerlessPayment();
        $this->allocate($payment, $this->monthlyInvoice($owner));

        $this->assertSame(1, app(MonthlyFeeOwnershipService::class)->audit()['summary']['payments_without_owner_count']);

        $this->artisan('finance:repair-monthly-fee-ownership', [
            '--confirm' => 'REPAIR_MONTHLY_FEE_OWNERSHIP',
            '--json' => true,
        ])->assertSuccessful();

        $this->assertSame($owner->id, $payment->fresh()->user_id);
        $audit = app(MonthlyFeeOwnershipService::class)->audit();
        $this->assertSame(0, $audit['summary']['payments_without_owner_count']);
        $this->assertSame(0, $audit['summary']['actionable_count']);
    }

    public function test_payment_allocated_to_multiple_invoices_of_same_owner_is_repaired(): void
    {
        $owner = User::factory()->create();
        $payment = $this->ownerlessPayment(60);
        $this->allocate($payment, $this->monthlyInvoice($owner), 30);
        $this->allocate($payment, $this->monthlyInvoice($owner, '2026-08'), 30);

        app(MonthlyFeeOwnershipService::class)->repair(true);

        $this->assertSame($owner->id, $payment->fresh()->user_id);
    }

    public function test_payment_allocated_to_invoices_of_different_owners_is_skipped(): void
    {
        $payment = $this->ownerlessPayment(60);
        $this->allocate($payment, $this->monthlyInvoice(User::factory()->create()), 30);
        $this->allocate($payment, $this->monthlyInvoice(User::factory()->create(), '2026-08'), 30);

        $payload = app(MonthlyFeeOwnershipService::class)->repair(false);

        $this->assertSame('ambiguous_payment_owner', $payload['skipped'][0]['reason']);
        $this->assertNull($payment->fresh()->user_id);
    }

    public function test_payment_allocated_to_ownerless_invoice_is_skipped(): void
    {
        Schema::table('invoices', function ($table): void {
            $table->uuid('user_id')->nullable()->change();
        });
        $payment = $this->ownerlessPayment();
        $invoice = $this->monthlyInvoice(User::factory()->create());
        $invoice->forceFill(['user_id' => null])->save();
        $this->allocate($payment, $invoice);

        $payload = app(MonthlyFeeOwnershipService::class)->repair(false);

        $paymentSkip = collect($payload['skipped'])->firstWhere('payment_id', $payment->id);
        $this->assertSame('allocated_invoice_without_owner', $paymentSkip['reason']);
        $this->assertNull($payment->fresh()->user_id);
    }

    public function test_payment_without_allocations_is_skipped(): void
    {
        $payment = $this->ownerlessPayment();

        $payload = app(MonthlyFeeOwnershipService::class)->repair(false);

        $this->assertSame('payment_without_invoice_allocation', $payload['skipped'][0]['reason']);
        $this->assertNull($payment->fresh()->user_id);
    }

    private function monthlyInvoice(User $owner, string $month = '2026-07')
    {
        return $owner->invoices()->create([
            'data_fatura' => '2026-07-01',
            'mes' => $month,
            'data_emissao' => '2026-07-01',
            'data_vencimento' => '2026-07-08',
            'valor_total' => 30,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);
    }

    private function ownerlessPayment(float $amount = 30): Payment
    {
        return Payment::query()->create([
            'amount' => $amount,
            'allocated_amount' => $amount,
            'unallocated_amount' => 0,
            'payment_date' => '2026-07-01',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);
    }

    private function allocate(Payment $payment, $invoice, float $amount = 30): PaymentAllocation
    {
        return PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);
    }
}

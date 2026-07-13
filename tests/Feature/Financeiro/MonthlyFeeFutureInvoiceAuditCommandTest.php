<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\ClubSetting;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MonthlyFee;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MonthlyFeeFutureInvoiceAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00'));
        $this->createUserTypeIfMissing('atleta', 'Atleta');
        $this->createClubSettings();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_detects_reconcilable_future_invoice_for_ineligible_member_as_critical(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $invoice = $this->generateInvoice($user, '2026-08');

        $user->forceFill(['ativo_desportivo' => false])->save();

        $payload = $this->jsonAuditPayload();
        $finding = $this->firstFinding($payload, 'future_invoice_for_ineligible_member');

        $this->assertSame('critical', $finding['severity']);
        $this->assertSame($invoice->id, $finding['invoice_id']);
        $this->assertTrue($finding['reconcilable']);
    }

    public function test_detects_protected_future_invoice_for_ineligible_member_as_warning(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $invoice = $this->generateInvoice($user, '2026-08');

        $payment = Payment::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'amount' => 10,
            'allocated_amount' => 10,
            'unallocated_amount' => 0,
            'payment_date' => '2026-07-15',
            'method' => 'dinheiro',
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $invoice->forceFill(['valor_pago' => 10, 'valor_em_aberto' => 30])->save();
        $user->forceFill(['estado' => 'inativo'])->save();

        $payload = $this->jsonAuditPayload();
        $finding = $this->firstFinding($payload, 'future_invoice_for_ineligible_member');

        $this->assertSame('warning', $finding['severity']);
        $this->assertFalse($finding['reconcilable']);
        $this->assertContains('paid_amount', $finding['protection_reasons']);
        $this->assertContains('payment_allocation', $finding['protection_reasons']);
    }

    public function test_detects_outside_window_due_day_hidden_terms_and_duplicate_findings(): void
    {
        $plan = $this->createMonthlyPlan();
        $outside = $this->generateInvoice($this->createEligibleUser($plan), '2027-01');
        $dueDivergent = $this->generateInvoice($this->createEligibleUser($plan), '2026-08');
        $hiddenDivergent = $this->generateInvoice($this->createEligibleUser($plan), '2026-09');
        $termsDivergent = $this->generateInvoice($this->createEligibleUser($plan), '2026-10');
        $duplicateUser = $this->createEligibleUser($plan);
        $duplicateA = $this->generateInvoice($duplicateUser, '2026-11');
        $duplicateB = $this->manualInvoice($duplicateUser, $plan, '2026-11');

        $dueDivergent->forceFill(['data_vencimento' => '2026-08-15'])->save();
        $hiddenDivergent->forceFill(['oculta' => false])->save();
        $termsDivergent->items()->where('descricao', $plan->designacao)->firstOrFail()->forceFill([
            'descricao' => 'Mensalidade antiga',
        ])->save();

        $payload = $this->jsonAuditPayload();

        $this->assertFindingForInvoice($payload, 'future_invoice_outside_current_window', $outside->id, 'critical');
        $this->assertFindingForInvoice($payload, 'future_invoice_cycle_projection_diverges', $dueDivergent->id, 'critical');
        $this->assertFindingForInvoice($payload, 'future_invoice_cycle_projection_diverges', $hiddenDivergent->id, 'critical');
        $this->assertFindingForInvoice($payload, 'future_invoice_terms_diverge', $termsDivergent->id, 'critical');

        $duplicateFinding = collect($payload['findings'])
            ->first(fn (array $finding): bool => $finding['code'] === 'duplicate_active_future_monthly_invoice');

        $this->assertIsArray($duplicateFinding);
        $this->assertSame('critical', $duplicateFinding['severity']);
        $this->assertContains($duplicateA->id, $duplicateFinding['actual']['duplicate_invoice_ids']);
        $this->assertContains($duplicateB->id, $duplicateFinding['actual']['duplicate_invoice_ids']);
    }

    public function test_legacy_origin_otherwise_projected_invoice_is_warning_and_projection_ok_is_info(): void
    {
        $plan = $this->createMonthlyPlan();
        $legacy = $this->generateInvoice($this->createEligibleUser($plan), '2026-08');
        $ok = $this->generateInvoice($this->createEligibleUser($plan), '2026-09');

        $legacy->forceFill([
            'origem_tipo' => 'manual',
            'origem_id' => null,
        ])->save();

        $payload = $this->jsonAuditPayload();

        $this->assertFindingForInvoice($payload, 'future_invoice_legacy_origin', $legacy->id, 'warning');
        $this->assertFindingForInvoice($payload, 'future_invoice_projection_ok', $ok->id, 'info');
    }

    public function test_filters_json_report_path_exit_codes_and_read_only_contract(): void
    {
        $plan = $this->createMonthlyPlan();
        $protectedUser = $this->createEligibleUser($plan);
        $reconcilableUser = $this->createEligibleUser($plan);
        $protected = $this->generateInvoice($protectedUser, '2026-08');
        $reconcilable = $this->generateInvoice($reconcilableUser, '2026-08');

        $protected->forceFill(['valor_pago' => 5, 'valor_em_aberto' => 35])->save();
        $protectedUser->forceFill(['ativo_desportivo' => false])->save();
        $reconcilableUser->forceFill(['ativo_desportivo' => false])->save();

        $before = Invoice::query()->orderBy('id')->get(['id', 'estado_pagamento', 'valor_pago', 'valor_em_aberto', 'updated_at'])->toArray();

        $payload = $this->jsonAuditPayload([
            '--only-reconcilable' => true,
        ]);

        $this->assertTrue(collect($payload['findings'])->contains(fn (array $finding): bool => $finding['invoice_id'] === $reconcilable->id));
        $this->assertFalse(collect($payload['findings'])->contains(fn (array $finding): bool => $finding['invoice_id'] === $protected->id));

        $userPayload = $this->jsonAuditPayload([
            '--user' => $reconcilableUser->id,
        ]);

        $this->assertSame([$reconcilableUser->id], collect($userPayload['findings'])->pluck('user_id')->unique()->values()->all());

        $fromPayload = $this->jsonAuditPayload([
            '--from' => '2026-08-15',
        ]);

        $this->assertSame('2026-09', $fromPayload['cutoff_month']);
        $this->assertSame(0, $fromPayload['summary']['total_future_monthly_invoices']);

        $relativePath = 'storage/app/audits/monthly-fee-future-audit-test.json';
        $absolutePath = base_path($relativePath);
        $exitCode = Artisan::call('finance:audit-future-monthly-fees', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);
        $this->assertIsArray(json_decode((string) file_get_contents($absolutePath), true));
        @unlink($absolutePath);

        $this->assertSame(1, Artisan::call('finance:audit-future-monthly-fees', ['--fail-on-critical' => true]));
        $this->assertSame(1, Artisan::call('finance:audit-future-monthly-fees', ['--fail-on-warning' => true]));

        $after = Invoice::query()->orderBy('id')->get(['id', 'estado_pagamento', 'valor_pago', 'valor_em_aberto', 'updated_at'])->toArray();
        $this->assertSame($before, $after);
    }

    public function test_command_outputs_expected_json_contract(): void
    {
        $plan = $this->createMonthlyPlan();
        $this->generateInvoice($this->createEligibleUser($plan), '2026-08');

        $payload = $this->jsonAuditPayload();

        $this->assertSame('a2-6-future-monthly-fee-audit-v1', $payload['version']);
        $this->assertArrayHasKey('generated_at', $payload);
        $this->assertSame('2026-07-15', $payload['effective_date']);
        $this->assertSame('2026-08', $payload['cutoff_month']);
        $this->assertArrayHasKey('settings', $payload);
        $this->assertArrayHasKey('window', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('findings', $payload);
        $this->assertSame(1, $payload['summary']['total_future_monthly_invoices']);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonAuditPayload(array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-future-monthly-fees', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstFinding(array $payload, string $code): array
    {
        $finding = collect($payload['findings'])->first(fn (array $finding): bool => $finding['code'] === $code);
        $this->assertIsArray($finding);

        return $finding;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function assertFindingForInvoice(array $payload, string $code, string $invoiceId, string $severity): void
    {
        $finding = collect($payload['findings'])->first(
            fn (array $finding): bool => $finding['code'] === $code
                && $finding['invoice_id'] === $invoiceId
                && $finding['severity'] === $severity,
        );

        $this->assertIsArray($finding, sprintf('Missing finding %s for invoice %s.', $code, $invoiceId));
    }

    private function generateInvoice(User $user, string $month): Invoice
    {
        $period = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfMonth();

        app(MonthlyFeeGenerationService::class)->generateForUserWithSummary(
            $user->fresh(['dadosFinanceiros.mensalidade', 'centrosCusto', 'userTypes']) ?? $user,
            $period,
            $period,
            [
                'today' => Carbon::today(),
                'start_date' => $period->toDateString(),
                'load_created_items' => true,
            ],
        );

        return Invoice::query()
            ->with('items')
            ->where('user_id', $user->id)
            ->where('mes', $month)
            ->firstOrFail();
    }

    private function manualInvoice(User $user, MonthlyFee $plan, string $month): Invoice
    {
        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'data_fatura' => $month . '-01',
            'mes' => $month,
            'data_emissao' => $month . '-01',
            'data_vencimento' => $month . '-01',
            'valor_total' => (float) $plan->valor,
            'valor_pago' => 0,
            'valor_em_aberto' => (float) $plan->valor,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee',
            'origem_id' => $plan->id,
            'oculta' => true,
        ]);

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => $plan->designacao,
            'quantidade' => 1,
            'valor_unitario' => (float) $plan->valor,
            'imposto_percentual' => 0,
            'total_linha' => (float) $plan->valor,
        ]);

        return $invoice->fresh('items');
    }

    private function createMonthlyPlan(float $amount = 40.00, string $designation = 'Mensalidade Base'): MonthlyFee
    {
        return MonthlyFee::query()->create([
            'designacao' => $designation,
            'valor' => $amount,
            'ativo' => true,
        ]);
    }

    private function createEligibleUser(MonthlyFee $plan, array $overrides = [], array $financeOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'nome_completo' => 'Atleta Auditoria',
            'email' => 'audit-' . Str::uuid() . '@example.com',
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ], $overrides));

        DadosFinanceiros::query()->create(array_merge([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ], $financeOverrides));

        $user->userTypes()->sync([(string) UserType::query()->where('codigo', 'atleta')->value('id')]);

        return $user->fresh(['dadosFinanceiros.mensalidade', 'userTypes']) ?? $user;
    }

    private function createUserTypeIfMissing(string $codigo, string $nome): void
    {
        UserType::query()->firstOrCreate(
            ['codigo' => $codigo],
            [
                'nome' => $nome,
                'descricao' => $nome,
                'ativo' => true,
            ],
        );
    }

    private function createClubSettings(array $overrides = []): ClubSetting
    {
        return ClubSetting::query()->create(array_merge([
            'nome_clube' => 'Clube Teste',
            'sigla' => 'CT',
            'monthly_fee_generation_enabled' => true,
            'monthly_fee_start_month' => 7,
            'monthly_fee_end_month' => 12,
            'monthly_fee_due_day' => 1,
            'monthly_fee_hide_future' => true,
            'monthly_fee_auto_activate_due' => true,
            'monthly_fee_respect_registration_date' => true,
            'monthly_fee_generate_months_ahead' => null,
            'monthly_fee_default_period_mode' => 'financial_cycle',
        ], $overrides));
    }
}

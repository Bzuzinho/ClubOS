<?php

namespace Tests\Feature\Financeiro;

use App\Models\ClubSetting;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationDelivery;
use App\Models\DadosFinanceiros;
use App\Models\FiscalDocumentRequest;
use App\Models\InAppAlert;
use App\Models\Invoice;
use App\Models\MonthlyFee;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Financeiro\MemberMonthlyFeeLifecycleService;
use Illuminate\Console\Scheduling\Schedule;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class MonthlyFeeGenerationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUserTypeIfMissing('atleta', 'Atleta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_generates_monthly_fees_for_all_eligible_users_via_endpoint(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = $this->createMonthlyPlan();

        $this->createEligibleUser($plan, [
            'nome_completo' => 'Atleta A',
            'email' => 'atleta-a@example.com',
            'data_inscricao' => '2026-05-10',
        ]);
        $this->createEligibleUser($plan, [
            'nome_completo' => 'Atleta B',
            'email' => 'atleta-b@example.com',
            'data_inscricao' => '2026-05-03',
        ]);
        User::factory()->create([
            'nome_completo' => 'Sem Plano',
            'email' => 'sem-plano@example.com',
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
        ]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.monthly-fees.generate'), [
            'generate_for_all' => true,
            'current_season' => false,
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('summary.created_count', 4)
            ->assertJsonPath('summary.users_with_new_fees', 2);

        $this->assertSame(4, Invoice::query()->where('tipo', 'mensalidade')->count());
    }

    public function test_it_does_not_duplicate_monthly_fees_for_the_same_period(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $service = app(MonthlyFeeGenerationService::class);

        $firstRun = $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-06-01'));
        $secondRun = $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-06-01'));

        $this->assertCount(2, $firstRun);
        $this->assertCount(0, $secondRun);
        $this->assertSame(2, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_generation_uses_canonical_monthly_fee_over_legacy_without_warning(): void
    {
        Log::spy();

        $canonicalPlan = $this->createMonthlyPlan(40.00);

        $user = $this->createEligibleUser($canonicalPlan, [
            'data_inscricao' => '2026-05-01',
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user->fresh('dadosFinanceiros'), Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('40.00', $invoice->valor_total);

        Log::shouldNotHaveReceived('warning', [
            'Monthly fee generation used monthly fee legacy fallback.',
        ]);
    }

    public function test_generation_ignores_legacy_monthly_fee_when_no_canonical_plan_exists(): void
    {
        Log::spy();

        $user = User::factory()->create([
            'nome_completo' => 'Fallback Monthly Fee',
            'email' => 'fallback-monthly-fee@example.com',
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);
        $user->userTypes()->sync([$this->findUserTypeId('atleta')]);

        $result = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'));

        $this->assertCount(0, $result);
        Log::shouldNotHaveReceived('warning');
    }

    public function test_it_generates_using_financial_cycle_without_requiring_active_sporting_season(): void
    {
        $this->createClubSettings([
            'monthly_fee_start_month' => 9,
            'monthly_fee_end_month' => 7,
        ]);

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-09-05',
        ]);

        $summary = app(MonthlyFeeGenerationService::class)->generateConfiguredCycle([
            'today' => Carbon::parse('2026-09-10'),
        ]);

        $this->assertSame(11, $summary['created_count']);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'mes' => '2026-09',
            'tipo' => 'mensalidade',
        ]);
        $this->assertDatabaseHas('invoices', [
            'user_id' => $user->id,
            'mes' => '2027-07',
            'tipo' => 'mensalidade',
        ]);
    }

    public function test_future_monthly_fees_stay_hidden_and_current_month_is_visible(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $service = app(MonthlyFeeGenerationService::class);

        $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-07-01'), [
            'today' => Carbon::parse('2026-05-01'),
        ]);

        $mayInvoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-05')->firstOrFail();
        $juneInvoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-06')->firstOrFail();
        $julyInvoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-07')->firstOrFail();

        $this->assertFalse((bool) $mayInvoice->oculta);
        $this->assertSame('pendente', $mayInvoice->estado_pagamento);
        $this->assertTrue((bool) $juneInvoice->oculta);
        $this->assertTrue((bool) $julyInvoice->oculta);
        $this->assertSame(1, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->where('oculta', false)->count());
    }

    public function test_due_future_fee_becomes_visible_when_period_arrives(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $service = app(MonthlyFeeGenerationService::class);

        $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-06-01'), [
            'today' => Carbon::parse('2026-05-01'),
        ]);

        $juneInvoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-06')->firstOrFail();
        $this->assertTrue((bool) $juneInvoice->oculta);

        $service->activateDueInvoices(Carbon::parse('2026-06-01'));

        $this->assertFalse((bool) $juneInvoice->fresh()->oculta);
    }

    public function test_configured_due_day_keeps_current_month_hidden_until_it_becomes_due(): void
    {
        $this->createClubSettings([
            'monthly_fee_due_day' => 15,
        ]);

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $service = app(MonthlyFeeGenerationService::class);

        $service->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'), [
            'today' => Carbon::parse('2026-05-01'),
        ]);

        $invoice = Invoice::query()->where('user_id', $user->id)->where('mes', '2026-05')->firstOrFail();

        $this->assertSame('2026-05-15', $invoice->data_vencimento?->format('Y-m-d'));
        $this->assertTrue((bool) $invoice->oculta);

        $service->activateDueInvoices(Carbon::parse('2026-05-15'));

        $this->assertFalse((bool) $invoice->fresh()->oculta);
    }

    public function test_user_without_discount_generates_invoice_with_base_amount_only(): void
    {
        $plan = $this->createMonthlyPlan(30.00);
        $user = $this->createEligibleUser($plan);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('30.00', $invoice->valor_total);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('Mensalidade Base', $invoice->items[0]->descricao);
        $this->assertSame('30.00', $invoice->items[0]->total_linha);
        $this->assertSame('Mensalidade maio 2026', $invoice->observacoes);
    }

    public function test_percentage_discount_generates_base_and_discount_lines(): void
    {
        $plan = $this->createMonthlyPlan(30.00);
        $user = $this->createEligibleUser($plan, financeOverrides: [
            'discount_type' => 'percent',
            'discount_value' => 10,
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('27.00', $invoice->valor_total);
        $this->assertSame('27.00', $invoice->valor_em_aberto);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('Mensalidade Base', $invoice->items[0]->descricao);
        $this->assertSame('30.00', $invoice->items[0]->total_linha);
        $this->assertSame('Desconto/Correcao 10%', $invoice->items[1]->descricao);
        $this->assertSame('-3.00', $invoice->items[1]->valor_unitario);
        $this->assertSame('-3.00', $invoice->items[1]->total_linha);
    }

    public function test_fixed_discount_generates_negative_line_with_fixed_amount(): void
    {
        $plan = $this->createMonthlyPlan(30.00);
        $user = $this->createEligibleUser($plan, financeOverrides: [
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'discount_reason' => 'Ajuste manual',
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('25.00', $invoice->valor_total);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('Desconto/Correcao financeira', $invoice->items[1]->descricao);
        $this->assertSame('-5.00', $invoice->items[1]->total_linha);
    }

    public function test_discount_cannot_make_invoice_total_negative(): void
    {
        $plan = $this->createMonthlyPlan(30.00);
        $user = $this->createEligibleUser($plan, financeOverrides: [
            'discount_type' => 'fixed',
            'discount_value' => 50,
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($user, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-01'))
            ->sole();

        $this->assertSame('0.00', $invoice->valor_total);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
        $this->assertCount(2, $invoice->items);
        $this->assertSame('-30.00', $invoice->items[1]->total_linha);
        $this->assertStringContainsString('Desconto/correcao limitada ao valor base da mensalidade', (string) $invoice->observacoes);
    }

    public function test_manual_endpoint_and_artisan_command_remain_idempotent_for_same_period(): void
    {
        $admin = User::factory()->admin()->create();
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);

        $this->actingAs($admin)->postJson(route('financeiro.monthly-fees.generate'), [
            'generate_for_all' => false,
            'user_id' => $user->id,
            'start_date' => '2026-05-01',
            'end_date' => '2026-06-01',
        ])->assertOk()->assertJsonPath('summary.created_count', 2);

        Artisan::call('finance:generate-monthly-fees', [
            '--start' => '2026-05-01',
            '--end' => '2026-06-01',
        ]);

        $this->assertStringContainsString('Mensalidades geradas: 0', Artisan::output());
        $this->assertSame(2, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_existing_paid_and_fiscalized_monthly_invoice_is_not_recalculated(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => '2026-05-01',
            'mes' => '2026-05',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-01',
            'valor_total' => 40,
            'valor_pago' => 40,
            'valor_em_aberto' => 0,
            'oculta' => false,
            'estado_pagamento' => 'pago',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'manual',
        ]);

        FiscalDocumentRequest::create([
            'invoice_id' => $invoice->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'external_document_number' => 'RC 2026/1',
        ]);

        $summary = app(MonthlyFeeGenerationService::class)->generateForAllEligibleUsers(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-01'),
        );

        $this->assertSame(0, $summary['created_count']);
        $this->assertSame(1, $summary['skipped_existing_count']);
        $this->assertSame(1, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_active_admin_without_eligible_member_type_does_not_generate_monthly_fee(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'perfil' => 'admin',
            'tipo_membro' => [],
            'ativo_desportivo' => false,
        ]);
        $user->userTypes()->sync([]);

        $summary = app(MonthlyFeeGenerationService::class)->generateForAllEligibleUsers(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-01')
        );

        $this->assertSame(0, $summary['created_count']);
        $this->assertSame(0, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_active_generic_user_without_functional_type_does_not_generate_monthly_fee(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'tipo_membro' => [],
            'ativo_desportivo' => false,
        ]);
        $user->userTypes()->sync([]);

        $summary = app(MonthlyFeeGenerationService::class)->generateForAllEligibleUsers(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-01')
        );

        $this->assertSame(0, $summary['created_count']);
        $this->assertSame(0, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_inactive_athlete_does_not_receive_new_monthly_fee(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'estado' => 'inativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);
        $user->userTypes()->sync([$this->findUserTypeId('atleta')]);

        $summary = app(MonthlyFeeGenerationService::class)->generateForAllEligibleUsers(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-01')
        );

        $this->assertSame(0, $summary['created_count']);
        $this->assertSame(0, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_active_athlete_with_inactive_sports_does_not_receive_new_monthly_fee(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => false,
        ]);
        $user->userTypes()->sync([$this->findUserTypeId('atleta')]);

        $summary = app(MonthlyFeeGenerationService::class)->generateForAllEligibleUsers(
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-01')
        );

        $this->assertSame(0, $summary['created_count']);
        $this->assertSame(0, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_command_outputs_summary_and_scheduler_registers_financial_tasks(): void
    {
        $plan = $this->createMonthlyPlan();
        $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);

        Artisan::call('finance:generate-monthly-fees', [
            '--start' => '2026-05-01',
            '--end' => '2026-05-01',
        ]);

        $output = Artisan::output();

        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->filter();

        $this->assertStringContainsString('Mensalidades geradas: 1', $output);
        $this->assertFalse($commands->contains(fn (string $command) => str_contains($command, 'finance:generate-monthly-fees')));
        $this->assertFalse($commands->contains(fn (string $command) => str_contains($command, 'finance:activate-due-monthly-fees')));

        config()->set('clubos.automations.monthly_fee_scheduler', true);
        require base_path('routes/console.php');

        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->filter();

        $this->assertTrue($commands->contains(fn (string $command) => str_contains($command, 'finance:generate-monthly-fees')));
        $this->assertTrue($commands->contains(fn (string $command) => str_contains($command, 'finance:activate-due-monthly-fees')));
    }

    public function test_generate_command_does_not_create_invoices_without_club_settings(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);

        $exitCode = Artisan::call('finance:generate-monthly-fees');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mensalidades geradas: 0', Artisan::output());
        $this->assertSame(0, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_generate_command_does_not_create_invoices_when_generation_is_disabled(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $this->createClubSettings([
            'monthly_fee_generation_enabled' => false,
        ]);

        $exitCode = Artisan::call('finance:generate-monthly-fees');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mensalidades geradas: 0', Artisan::output());
        $this->assertSame(0, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_generate_command_creates_expected_monthly_fees_when_generation_is_enabled(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-09-05',
        ]);
        $this->createClubSettings([
            'monthly_fee_generation_enabled' => true,
        ]);

        Carbon::setTestNow('2026-09-10');

        $exitCode = Artisan::call('finance:generate-monthly-fees');

        Carbon::setTestNow();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mensalidades geradas: 11', Artisan::output());
        $this->assertSame(11, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_generate_command_with_explicit_period_can_override_disabled_automation(): void
    {
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $this->createClubSettings([
            'monthly_fee_generation_enabled' => false,
        ]);

        $exitCode = Artisan::call('finance:generate-monthly-fees', [
            '--start' => '2026-05-01',
            '--end' => '2026-06-01',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mensalidades geradas: 2', Artisan::output());
        $this->assertSame(2, Invoice::query()->where('user_id', $user->id)->where('tipo', 'mensalidade')->count());
    }

    public function test_activate_command_returns_zero_without_club_settings(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createHiddenMonthlyInvoice($user, '2026-05', '2026-05-01');

        $exitCode = Artisan::call('finance:activate-due-monthly-fees');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mensalidades ativadas: 0', Artisan::output());
        $this->assertTrue((bool) $invoice->fresh()->oculta);
    }

    public function test_activate_command_does_not_activate_hidden_invoices_when_auto_activation_is_disabled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createHiddenMonthlyInvoice($user, '2026-05', '2026-05-01');
        $this->createClubSettings([
            'monthly_fee_auto_activate_due' => false,
        ]);

        $exitCode = Artisan::call('finance:activate-due-monthly-fees');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mensalidades ativadas: 0', Artisan::output());
        $this->assertTrue((bool) $invoice->fresh()->oculta);
    }

    public function test_activate_command_activates_due_hidden_invoices_when_auto_activation_is_enabled(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createHiddenMonthlyInvoice($user, '2026-05', '2026-05-01');
        $this->createClubSettings([
            'monthly_fee_auto_activate_due' => true,
        ]);

        $exitCode = Artisan::call('finance:activate-due-monthly-fees');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Mensalidades ativadas: 1', Artisan::output());
        $this->assertFalse((bool) $invoice->fresh()->oculta);
    }

    public function test_monthly_fee_generation_command_does_not_create_communication_artifacts(): void
    {
        $plan = $this->createMonthlyPlan();
        $this->createEligibleUser($plan, [
            'data_inscricao' => '2026-05-01',
        ]);
        $this->createClubSettings([
            'monthly_fee_generation_enabled' => true,
        ]);

        Artisan::call('finance:generate-monthly-fees');

        $this->assertSame(0, CommunicationCampaign::query()->count());
        $this->assertSame(0, CommunicationDelivery::query()->count());
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_monthly_fee_lifecycle_cancels_only_future_unpaid_invoices_after_sports_inactivation(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, ['data_inscricao' => '2026-07-01']);
        $july = $this->createMonthlyInvoiceForLifecycle($user, '2026-07', hidden: false);
        $august = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);
        $september = $this->createMonthlyInvoiceForLifecycle($user, '2026-09', hidden: true);

        $summary = app(MemberMonthlyFeeLifecycleService::class)
            ->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $this->assertSame(2, $summary['cancelled_count']);
        $this->assertSame('pendente', $july->fresh()->estado_pagamento);
        $this->assertSame('cancelado', $august->fresh()->estado_pagamento);
        $this->assertSame('0.00', $august->fresh()->valor_em_aberto);
        $this->assertFalse((bool) $august->fresh()->oculta);
        $this->assertSame('cancelado', $september->fresh()->estado_pagamento);

        $breakdownIds = collect(app(CurrentAccountService::class)->summarize([
            'user_id' => $user->id,
        ])['breakdown']['invoices'])->pluck('id');

        $this->assertTrue($breakdownIds->contains($july->id));
        $this->assertFalse($breakdownIds->contains($august->id));
        $this->assertFalse($breakdownIds->contains($september->id));

        app(MonthlyFeeGenerationService::class)->activateDueInvoices(Carbon::parse('2026-09-01'));

        $this->assertSame('cancelado', $august->fresh()->estado_pagamento);
        $this->assertFalse((bool) $august->fresh()->oculta);
        $this->assertSame('cancelado', $september->fresh()->estado_pagamento);
    }

    public function test_monthly_fee_lifecycle_cancels_future_invoices_when_member_becomes_inactive(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $august = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);

        $user->forceFill(['estado' => 'inativo'])->save();

        app(MemberMonthlyFeeLifecycleService::class)
            ->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $this->assertSame('cancelado', $august->fresh()->estado_pagamento);
    }

    public function test_monthly_fee_lifecycle_cancels_future_invoices_when_athlete_type_is_removed(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $august = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);

        $user->userTypes()->sync([]);
        $user->forceFill(['tipo_membro' => []])->save();

        app(MemberMonthlyFeeLifecycleService::class)
            ->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $this->assertSame('cancelado', $august->fresh()->estado_pagamento);
    }

    public function test_monthly_fee_lifecycle_preserves_future_invoice_with_partial_or_full_payment_trail(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $partial = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true, overrides: [
            'estado_pagamento' => 'parcial',
            'valor_pago' => 10,
            'valor_em_aberto' => 30,
        ]);
        $paid = $this->createMonthlyInvoiceForLifecycle($user, '2026-09', hidden: true, overrides: [
            'estado_pagamento' => 'pago',
            'valor_pago' => 40,
            'valor_em_aberto' => 0,
        ]);

        $summary = app(MemberMonthlyFeeLifecycleService::class)
            ->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $this->assertSame(0, $summary['cancelled_count']);
        $this->assertSame('parcial', $partial->fresh()->estado_pagamento);
        $this->assertSame('pago', $paid->fresh()->estado_pagamento);
    }

    public function test_monthly_fee_lifecycle_preserves_future_invoice_with_confirmed_payment_allocation(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $invoice = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => 10,
            'allocated_amount' => 10,
            'unallocated_amount' => 0,
            'payment_date' => '2026-07-20',
            'source' => Payment::SOURCE_MANUAL,
            'status' => 'confirmed',
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        app(MemberMonthlyFeeLifecycleService::class)
            ->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $this->assertSame('pendente', $invoice->fresh()->estado_pagamento);
    }

    public function test_monthly_fee_lifecycle_preserves_future_invoice_with_external_fiscal_document(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $invoice = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);

        FiscalDocumentRequest::create([
            'invoice_id' => $invoice->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'external_document_number' => 'RC 2026/200',
        ]);

        app(MemberMonthlyFeeLifecycleService::class)
            ->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $this->assertSame('pendente', $invoice->fresh()->estado_pagamento);
    }

    public function test_monthly_fee_lifecycle_preserves_previous_and_effective_month_debt(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $june = $this->createMonthlyInvoiceForLifecycle($user, '2026-06', hidden: false, overrides: [
            'estado_pagamento' => 'vencido',
        ]);
        $july = $this->createMonthlyInvoiceForLifecycle($user, '2026-07', hidden: false);
        $august = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);

        app(MemberMonthlyFeeLifecycleService::class)
            ->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $this->assertSame('vencido', $june->fresh()->estado_pagamento);
        $this->assertSame('pendente', $july->fresh()->estado_pagamento);
        $this->assertSame('cancelado', $august->fresh()->estado_pagamento);
    }

    public function test_monthly_fee_lifecycle_is_idempotent(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $invoice = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);
        $service = app(MemberMonthlyFeeLifecycleService::class);

        $first = $service->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));
        $second = $service->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $this->assertSame(1, $first['cancelled_count']);
        $this->assertSame(0, $second['cancelled_count']);
        $this->assertSame('cancelado', $invoice->fresh()->estado_pagamento);
    }

    public function test_monthly_fee_reactivation_does_not_reopen_cancelled_invoices_and_generation_can_create_future_active_invoice(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan);
        $cancelledAugust = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);

        app(MemberMonthlyFeeLifecycleService::class)
            ->reconcileEligibilityTransition($user->fresh('userTypes'), true, false, Carbon::parse('2026-07-15'));

        $user->forceFill(['ativo_desportivo' => true, 'estado' => 'ativo'])->save();

        $summary = app(MonthlyFeeGenerationService::class)->generateForUserWithSummary(
            $user->fresh(['userTypes', 'dadosFinanceiros']),
            Carbon::parse('2026-08-01'),
            Carbon::parse('2026-08-01'),
            ['today' => Carbon::parse('2026-08-01')]
        );

        $this->assertSame('cancelado', $cancelledAugust->fresh()->estado_pagamento);
        $this->assertSame(1, $summary['created_count']);
        $this->assertSame(1, Invoice::query()
            ->where('user_id', $user->id)
            ->where('mes', '2026-08')
            ->where('tipo', 'mensalidade')
            ->where('estado_pagamento', '!=', 'cancelado')
            ->count());
    }

    public function test_member_update_reconciles_future_monthly_fees_when_sports_activity_is_disabled(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $admin = User::factory()->admin()->create();
        $plan = $this->createMonthlyPlan();
        $user = $this->createEligibleUser($plan, [
            'nome_completo' => 'Atleta Lifecycle',
            'email' => 'atleta.lifecycle@example.test',
            'numero_socio' => 'LIFE-100',
            'sexo' => 'masculino',
        ]);
        $august = $this->createMonthlyInvoiceForLifecycle($user, '2026-08', hidden: true);

        $this->actingAs($admin)
            ->from(route('membros.show', $user))
            ->put(route('membros.update', $user), [
                'nome_completo' => 'Atleta Lifecycle',
                'email_utilizador' => 'atleta.lifecycle@example.test',
                'numero_socio' => 'LIFE-100',
                'sexo' => 'masculino',
                'estado' => 'ativo',
                'perfil' => 'atleta',
                'tipo_membro' => ['atleta'],
                'user_types' => [$this->findUserTypeId('atleta')],
                'ativo_desportivo' => '0',
                'tipo_mensalidade' => $plan->id,
            ])
            ->assertRedirect(route('membros.show', ['member' => $user->id]));

        $this->assertFalse((bool) $user->fresh()->ativo_desportivo);
        $this->assertSame('cancelado', $august->fresh()->estado_pagamento);
    }

    private function createMonthlyPlan(float $amount = 40.00): MonthlyFee
    {
        return MonthlyFee::create([
            'designacao' => 'Mensalidade Base',
            'valor' => $amount,
            'ativo' => true,
        ]);
    }

    private function createEligibleUser(MonthlyFee $plan, array $overrides = [], array $financeOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'nome_completo' => 'Utilizador Elegivel',
            'email' => 'eligible-' . uniqid() . '@example.com',
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ], $overrides));

        DadosFinanceiros::create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ] + $financeOverrides);

        if (($overrides['tipo_membro'][0] ?? null) === 'atleta') {
            $user->userTypes()->sync([$this->findUserTypeId('atleta')]);
        }

        return $user->fresh('dadosFinanceiros');
    }

    private function createUserTypeIfMissing(string $codigo, string $nome): void
    {
        if (UserType::query()->where('codigo', $codigo)->exists()) {
            return;
        }

        UserType::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'descricao' => $nome,
            'ativo' => true,
        ]);
    }

    private function findUserTypeId(string $codigo): string
    {
        return (string) UserType::query()->where('codigo', $codigo)->value('id');
    }

    private function createClubSettings(array $overrides = []): ClubSetting
    {
        return ClubSetting::create(array_merge([
            'nome_clube' => 'Clube Teste',
            'sigla' => 'CT',
            'monthly_fee_generation_enabled' => true,
            'monthly_fee_start_month' => 9,
            'monthly_fee_end_month' => 7,
            'monthly_fee_due_day' => 1,
            'monthly_fee_hide_future' => true,
            'monthly_fee_auto_activate_due' => true,
            'monthly_fee_respect_registration_date' => true,
            'monthly_fee_generate_months_ahead' => null,
            'monthly_fee_default_period_mode' => 'financial_cycle',
        ], $overrides));
    }

    private function createHiddenMonthlyInvoice(User $user, string $month, string $dueDate): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => $month . '-01',
            'mes' => $month,
            'data_emissao' => $month . '-01',
            'data_vencimento' => $dueDate,
            'valor_total' => 40,
            'valor_pago' => 0,
            'valor_em_aberto' => 40,
            'oculta' => true,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'manual',
        ]);
    }

    private function createMonthlyInvoiceForLifecycle(User $user, string $month, bool $hidden, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'user_id' => $user->id,
            'data_fatura' => $month . '-01',
            'mes' => $month,
            'data_emissao' => $month . '-01',
            'data_vencimento' => $month . '-01',
            'valor_total' => 40,
            'valor_pago' => 0,
            'valor_em_aberto' => 40,
            'oculta' => $hidden,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'manual',
        ], $overrides));
    }
}

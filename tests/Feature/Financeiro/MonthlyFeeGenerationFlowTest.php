<?php

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MonthlyFeeGenerationFlowTest extends TestCase
{
    use RefreshDatabase;

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

    private function createMonthlyPlan(): MonthlyFee
    {
        return MonthlyFee::create([
            'designacao' => 'Mensalidade Base',
            'valor' => 40.00,
            'ativo' => true,
        ]);
    }

    private function createEligibleUser(MonthlyFee $plan, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'nome_completo' => 'Utilizador Elegivel',
            'email' => 'eligible-' . uniqid() . '@example.com',
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
        ], $overrides));

        DadosFinanceiros::create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        return $user->fresh('dadosFinanceiros');
    }
}
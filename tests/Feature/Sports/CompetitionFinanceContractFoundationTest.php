<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\CompetitionFinancePolicy;
use App\Models\CompetitionFinancialObligation;
use App\Models\CompetitionRegistration;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Prova;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsDesportivoAccess;
use Tests\TestCase;

class CompetitionFinanceContractFoundationTest extends TestCase
{
    use RefreshDatabase;
    use GrantsDesportivoAccess;

    public function test_new_competition_defaults_to_club_pays_without_athlete_charge(): void
    {
        $admin = $this->authorizedAdmin();

        $competitionId = (string) $this->actingAs($admin)
            ->postJson('/api/desportivo/competitions', [
                'nome' => 'Torneio F5 Club Pays',
                'data_inicio' => '2026-09-10',
                'data_fim' => '2026-09-11',
                'local' => 'Piscina Municipal',
                'tipo_prova' => 'piscina',
            ])
            ->assertCreated()
            ->json('id');

        $this->assertDatabaseHas('competition_finance_policies', [
            'competition_id' => $competitionId,
            'payer_mode' => 'club',
            'charge_mode' => 'none',
            'active' => 1,
        ]);

        $competition = Competition::query()->findOrFail($competitionId);
        $prova = $this->createProva($competition, 1);
        $athlete = User::factory()->create();

        $registrationId = (string) $this->actingAs($admin)
            ->postJson('/api/desportivo/competition-registrations', [
                'prova_id' => $prova->id,
                'user_id' => $athlete->id,
                'estado' => 'inscrito',
                'valor_inscricao' => 25,
            ])
            ->assertCreated()
            ->json('id');

        $registration = CompetitionRegistration::query()->findOrFail($registrationId);
        $this->assertNull($registration->fatura_id);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertDatabaseHas('competition_financial_obligations', [
            'competition_id' => $competition->id,
            'user_id' => $athlete->id,
            'status' => 'no_charge',
            'calculated_amount' => 0,
        ]);
    }

    public function test_per_race_policy_aggregates_multiple_races_into_one_invoice(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        $competition = $this->createCompetition($admin, 'Torneio Agregado');
        $firstProva = $this->createProva($competition, 1);
        $secondProva = $this->createProva($competition, 2, 'COSTAS');

        CompetitionFinancePolicy::query()->create([
            'club_id' => $competition->club_id,
            'competition_id' => $competition->id,
            'payer_mode' => 'athlete',
            'charge_mode' => 'per_race',
            'per_race_amount' => 12.50,
            'active' => true,
        ]);

        $firstId = $this->register($admin, $athlete, $firstProva);
        $firstInvoiceId = (string) CompetitionRegistration::query()->findOrFail($firstId)->fatura_id;

        $secondId = $this->register($admin, $athlete, $secondProva);
        $first = CompetitionRegistration::query()->findOrFail($firstId);
        $second = CompetitionRegistration::query()->findOrFail($secondId);

        $this->assertSame($firstInvoiceId, (string) $first->fatura_id);
        $this->assertSame($firstInvoiceId, (string) $second->fatura_id);
        $this->assertSame(1, Invoice::query()->count());

        $invoice = Invoice::query()->findOrFail($firstInvoiceId);
        $this->assertSame(25.0, (float) $invoice->valor_total);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(0, FinancialEntry::query()->where('fatura_id', $invoice->id)->count());

        $obligation = CompetitionFinancialObligation::query()
            ->where('competition_id', $competition->id)
            ->where('user_id', $athlete->id)
            ->sole();
        $this->assertSame((string) $invoice->id, (string) $obligation->invoice_id);
        $this->assertSame('active', $obligation->status);
        $this->assertSame(25.0, (float) $obligation->calculated_amount);
    }

    public function test_removing_one_pending_race_recalculates_same_aggregate_invoice(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        $competition = $this->createCompetition($admin, 'Torneio Recalculo');
        $firstProva = $this->createProva($competition, 1);
        $secondProva = $this->createProva($competition, 2, 'MARIPOSA');

        CompetitionFinancePolicy::query()->create([
            'club_id' => $competition->club_id,
            'competition_id' => $competition->id,
            'payer_mode' => 'athlete',
            'charge_mode' => 'per_race',
            'per_race_amount' => 10,
            'active' => true,
        ]);

        $firstId = $this->register($admin, $athlete, $firstProva);
        $secondId = $this->register($admin, $athlete, $secondProva);
        $invoiceId = (string) CompetitionRegistration::query()->findOrFail($firstId)->fatura_id;

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$secondId)
            ->assertOk();

        $this->assertDatabaseMissing('competition_registrations', ['id' => $secondId]);
        $this->assertSame(10.0, (float) Invoice::query()->findOrFail($invoiceId)->valor_total);
        $this->assertSame($invoiceId, (string) CompetitionRegistration::query()->findOrFail($firstId)->fatura_id);
    }

    public function test_closed_aggregate_invoice_blocks_sports_registration_removal(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        $competition = $this->createCompetition($admin, 'Torneio Fechado');
        $prova = $this->createProva($competition, 1);

        CompetitionFinancePolicy::query()->create([
            'club_id' => $competition->club_id,
            'competition_id' => $competition->id,
            'payer_mode' => 'athlete',
            'charge_mode' => 'fixed',
            'fixed_amount' => 30,
            'active' => true,
        ]);

        $registrationId = $this->register($admin, $athlete, $prova);
        $registration = CompetitionRegistration::query()->findOrFail($registrationId);
        Invoice::query()->whereKey($registration->fatura_id)->update([
            'estado_pagamento' => 'pago',
            'valor_pago' => 30,
            'valor_em_aberto' => 0,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registrationId)
            ->assertStatus(422)
            ->assertJsonValidationErrors('competition_registration');

        $this->assertDatabaseHas('competition_registrations', ['id' => $registrationId]);
    }

    public function test_mixed_policy_supports_fixed_plus_per_race_calculation(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        $competition = $this->createCompetition($admin, 'Torneio Mixed');
        $firstProva = $this->createProva($competition, 1);
        $secondProva = $this->createProva($competition, 2, 'BRUCOS');

        CompetitionFinancePolicy::query()->create([
            'club_id' => $competition->club_id,
            'competition_id' => $competition->id,
            'payer_mode' => 'athlete',
            'charge_mode' => 'mixed',
            'fixed_amount' => 5,
            'per_race_amount' => 7.50,
            'active' => true,
        ]);

        $firstId = $this->register($admin, $athlete, $firstProva);
        $this->register($admin, $athlete, $secondProva);

        $invoiceId = (string) CompetitionRegistration::query()->findOrFail($firstId)->fatura_id;
        $this->assertSame(20.0, (float) Invoice::query()->findOrFail($invoiceId)->valor_total);
    }

    private function authorizedAdmin(): User
    {
        $admin = User::factory()->create();
        $this->grantDesportivoAccess($admin);

        return $admin;
    }

    private function createCompetition(User $admin, string $name): Competition
    {
        return Competition::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'nome' => $name,
            'local' => 'Piscina Municipal',
            'data_inicio' => '2026-09-20',
            'data_fim' => '2026-09-21',
            'tipo' => 'natacao',
            'status' => 'scheduled',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
    }

    private function createProva(Competition $competition, int $order, string $stroke = 'LIVRE'): Prova
    {
        return Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => $stroke,
            'distancia_m' => 100,
            'genero' => 'M',
            'ordem_prova' => $order,
        ]);
    }

    private function register(User $admin, User $athlete, Prova $prova): string
    {
        return (string) $this->actingAs($admin)
            ->postJson('/api/desportivo/competition-registrations', [
                'prova_id' => $prova->id,
                'user_id' => $athlete->id,
                'estado' => 'inscrito',
            ])
            ->assertCreated()
            ->json('id');
    }
}

<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\CompetitionFinancePolicy;
use App\Models\CompetitionFinancialObligation;
use App\Models\CompetitionRegistration;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Prova;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\GrantsDesportivoAccess;
use Tests\TestCase;

class CompetitionRegistrationFinancialLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use GrantsDesportivoAccess;

    public function test_create_with_explicit_value_uses_canonical_obligation_and_no_parallel_financial_entry(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        [$competition, $prova] = $this->createCompetitionAndProva(25);

        $response = $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', [
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 12.5,
        ]);

        $response->assertCreated();
        $registrationId = (string) $response->json('id');
        $invoice = $this->invoiceFor($competition, $athlete);
        $obligation = $this->obligationFor($competition, $athlete);

        $this->assertSame('competition_registration', $invoice->origem_tipo);
        $this->assertSame($registrationId, (string) $invoice->origem_id);
        $this->assertSame('inscricao', $invoice->tipo);
        $this->assertSame(12.5, (float) $invoice->valor_total);
        $this->assertDatabaseHas('competition_registrations', ['id' => $registrationId, 'fatura_id' => null]);

        $this->assertDatabaseHas('invoice_items', [
            'fatura_id' => $invoice->id,
            'descricao' => 'Inscricao em prova - '.$competition->nome,
            'quantidade' => 1,
            'total_linha' => 12.5,
        ]);

        $this->assertDatabaseMissing('financial_entries', [
            'fatura_id' => $invoice->id,
        ]);
    }

    public function test_create_without_explicit_value_uses_canonical_per_race_policy(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        [$competition, $prova] = $this->createCompetitionAndProva(19.9);

        $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', [
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
        ])->assertCreated();

        $this->assertSame(19.9, (float) $this->invoiceFor($competition, $athlete)->valor_total);
    }

    public function test_create_zero_value_with_club_pays_policy_does_not_create_financial_debt(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        [$competition, $prova] = $this->createCompetitionAndProva(null);

        $response = $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', [
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 0,
        ])->assertCreated();

        $this->assertDatabaseHas('competition_registrations', [
            'id' => (string) $response->json('id'),
            'fatura_id' => null,
        ]);
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, InvoiceItem::query()->count());
        $this->assertSame('no_charge', $this->obligationFor($competition, $athlete)->status);
    }

    public function test_duplicate_registration_for_same_user_and_prova_remains_blocked(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        [, $prova] = $this->createCompetitionAndProva(12);

        $payload = [
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 12,
        ];

        $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', $payload)->assertCreated();
        $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', $payload)->assertStatus(422);
    }

    public function test_destroy_registration_without_invoice_is_allowed(): void
    {
        $admin = $this->authorizedAdmin();
        $registration = $this->createRegistrationWithoutInvoice();

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registration->id)
            ->assertOk();

        $this->assertDatabaseMissing('competition_registrations', ['id' => $registration->id]);
    }

    public function test_destroy_registration_with_pending_invoice_and_no_payments_removes_both_records(): void
    {
        $admin = $this->authorizedAdmin();
        $registration = $this->createRegistrationWithoutInvoice();
        $invoice = $this->attachInvoiceToObligation($registration, [
            'estado_pagamento' => 'pendente',
            'valor_total' => 22,
            'valor_pago' => 0,
            'valor_em_aberto' => 22,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registration->id)
            ->assertOk();

        $this->assertDatabaseMissing('competition_registrations', ['id' => $registration->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['fatura_id' => $invoice->id]);
    }

    public function test_destroy_with_partial_invoice_is_blocked(): void
    {
        $admin = $this->authorizedAdmin();
        $registration = $this->createRegistrationWithoutInvoice();

        $this->attachInvoiceToObligation($registration, [
            'estado_pagamento' => 'parcial',
            'valor_total' => 30,
            'valor_pago' => 10,
            'valor_em_aberto' => 20,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registration->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('competition_registration');

        $this->assertDatabaseHas('competition_registrations', ['id' => $registration->id]);
    }

    public function test_destroy_with_paid_invoice_is_blocked(): void
    {
        $admin = $this->authorizedAdmin();
        $registration = $this->createRegistrationWithoutInvoice();

        $this->attachInvoiceToObligation($registration, [
            'estado_pagamento' => 'pago',
            'valor_total' => 30,
            'valor_pago' => 30,
            'valor_em_aberto' => 0,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registration->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('competition_registration');

        $this->assertDatabaseHas('competition_registrations', ['id' => $registration->id]);
    }

    public function test_destroy_with_confirmed_payment_allocation_is_blocked(): void
    {
        $admin = $this->authorizedAdmin();
        $registration = $this->createRegistrationWithoutInvoice();
        $invoice = $this->attachInvoiceToObligation($registration, [
            'estado_pagamento' => 'pendente',
            'valor_total' => 30,
            'valor_pago' => 0,
            'valor_em_aberto' => 30,
        ]);

        $payment = Payment::query()->create([
            'user_id' => $registration->user_id,
            'amount' => 15,
            'allocated_amount' => 15,
            'unallocated_amount' => 0,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 15,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registration->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('competition_registration');
    }

    public function test_destroy_with_issued_fiscal_document_request_is_blocked(): void
    {
        $admin = $this->authorizedAdmin();
        $registration = $this->createRegistrationWithoutInvoice();
        $invoice = $this->attachInvoiceToObligation($registration, [
            'estado_pagamento' => 'pendente',
            'valor_total' => 30,
            'valor_pago' => 0,
            'valor_em_aberto' => 30,
        ]);

        FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $registration->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 30,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registration->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('competition_registration');
    }

    public function test_destroy_with_invoice_receipt_number_is_blocked(): void
    {
        $admin = $this->authorizedAdmin();
        $registration = $this->createRegistrationWithoutInvoice();

        $this->attachInvoiceToObligation($registration, [
            'estado_pagamento' => 'pendente',
            'valor_total' => 30,
            'valor_pago' => 0,
            'valor_em_aberto' => 30,
            'numero_recibo' => 'RCPT-001',
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registration->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('competition_registration');
    }

    private function authorizedAdmin(): User
    {
        $admin = User::factory()->create();
        $this->grantDesportivoAccess($admin);

        return $admin;
    }

    /** @return array{0:Competition,1:Prova} */
    private function createCompetitionAndProva(?float $perRaceAmount): array
    {
        $competition = Competition::query()->create([
            'club_id' => config('sports.club_id', 'bscn'),
            'nome' => 'Competicao Teste',
            'local' => 'Piscina Municipal',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->addDay()->toDateString(),
            'tipo' => 'natacao',
            'status' => 'scheduled',
        ]);

        CompetitionFinancePolicy::query()->create([
            'club_id' => $competition->club_id,
            'competition_id' => $competition->id,
            'payer_mode' => $perRaceAmount !== null ? 'athlete' : 'club',
            'charge_mode' => $perRaceAmount !== null ? 'per_race' : 'none',
            'per_race_amount' => $perRaceAmount,
            'active' => true,
        ]);

        $prova = Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => 'LIVRE',
            'distancia_m' => 100,
            'genero' => 'M',
            'ordem_prova' => 1,
        ]);

        return [$competition, $prova];
    }

    private function createRegistrationWithoutInvoice(): CompetitionRegistration
    {
        $athlete = User::factory()->create();
        [, $prova] = $this->createCompetitionAndProva(null);

        return CompetitionRegistration::query()->create([
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 0,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private function attachInvoiceToObligation(CompetitionRegistration $registration, array $overrides = []): Invoice
    {
        $registration->loadMissing('prova.competition');
        $competition = $registration->prova->competition;

        $obligation = CompetitionFinancialObligation::query()->firstOrCreate(
            [
                'club_id' => $competition->club_id,
                'competition_id' => $competition->id,
                'user_id' => $registration->user_id,
            ],
            [
                'status' => 'active',
                'calculated_amount' => (float) ($overrides['valor_total'] ?? 10),
                'calculation_snapshot' => ['registration_ids' => [(string) $registration->id]],
                'synchronized_at' => now(),
            ]
        );

        $base = [
            'user_id' => $registration->user_id,
            'data_fatura' => now()->toDateString(),
            'mes' => now()->format('Y-m'),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(7)->toDateString(),
            'valor_total' => 10,
            'valor_pago' => 0,
            'valor_em_aberto' => 10,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'inscricao',
            'origem_tipo' => 'competition_registration',
            'origem_id' => $registration->id,
            'referencia_pagamento' => (string) Str::uuid(),
            'observacoes' => 'Inscricao em prova - teste',
        ];

        $invoice = Invoice::query()->create(array_merge($base, $overrides));

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Inscricao em prova - teste',
            'quantidade' => 1,
            'valor_unitario' => (float) $invoice->valor_total,
            'imposto_percentual' => 0,
            'total_linha' => (float) $invoice->valor_total,
            'centro_custo_id' => $invoice->centro_custo_id,
        ]);

        $obligation->update([
            'invoice_id' => $invoice->id,
            'status' => 'active',
            'calculated_amount' => (float) $invoice->valor_total,
            'calculation_snapshot' => ['registration_ids' => [(string) $registration->id]],
            'synchronized_at' => now(),
        ]);

        return $invoice;
    }

    private function obligationFor(Competition $competition, User $athlete): CompetitionFinancialObligation
    {
        return CompetitionFinancialObligation::query()
            ->where('competition_id', $competition->id)
            ->where('user_id', $athlete->id)
            ->sole();
    }

    private function invoiceFor(Competition $competition, User $athlete): Invoice
    {
        return Invoice::query()->findOrFail($this->obligationFor($competition, $athlete)->invoice_id);
    }
}

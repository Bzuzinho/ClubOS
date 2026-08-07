<?php

namespace Tests\Feature\Sports;

use App\Models\Competition;
use App\Models\CompetitionRegistration;
use App\Models\Event;
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

    public function test_create_with_explicit_value_creates_invoice_item_and_no_parallel_financial_entry(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        $prova = $this->createProvaWithEventFee(25);

        $response = $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', [
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 12.5,
        ]);

        $response->assertCreated();
        $registrationId = $response->json('id');

        $registration = CompetitionRegistration::query()->findOrFail($registrationId);
        $invoice = Invoice::query()->findOrFail($registration->fatura_id);

        $this->assertSame('competition_registration', $invoice->origem_tipo);
        $this->assertSame((string) $registration->id, (string) $invoice->origem_id);
        $this->assertSame('inscricao', $invoice->tipo);
        $this->assertSame(12.5, (float) $invoice->valor_total);

        $this->assertDatabaseHas('invoice_items', [
            'fatura_id' => $invoice->id,
            'descricao' => 'Inscricao em prova - '.$prova->competition->evento->titulo,
            'quantidade' => 1,
            'total_linha' => 12.5,
        ]);

        $this->assertDatabaseMissing('financial_entries', [
            'fatura_id' => $invoice->id,
        ]);
    }

    public function test_create_without_explicit_value_uses_event_fee(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        $prova = $this->createProvaWithEventFee(19.9);

        $response = $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', [
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
        ]);

        $response->assertCreated();

        $registration = CompetitionRegistration::query()->findOrFail($response->json('id'));
        $invoice = Invoice::query()->findOrFail($registration->fatura_id);

        $this->assertSame(19.9, (float) $invoice->valor_total);
    }

    public function test_create_zero_value_without_event_fee_does_not_create_financial_debt(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        $prova = $this->createProvaWithEventFee(null);

        $response = $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', [
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 0,
        ]);

        $response->assertCreated();

        $registration = CompetitionRegistration::query()->findOrFail($response->json('id'));
        $this->assertNull($registration->fatura_id);

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, InvoiceItem::query()->count());
    }

    public function test_duplicate_registration_for_same_user_and_prova_remains_blocked(): void
    {
        $admin = $this->authorizedAdmin();
        $athlete = User::factory()->create();
        $prova = $this->createProvaWithEventFee(12);

        $payload = [
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 12,
        ];

        $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', $payload)->assertCreated();

        $this->actingAs($admin)
            ->postJson('/api/desportivo/competition-registrations', $payload)
            ->assertStatus(422);
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
        $invoice = $this->attachInvoiceToRegistration($registration, [
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

        $this->attachInvoiceToRegistration($registration, [
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

        $this->attachInvoiceToRegistration($registration, [
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
        $invoice = $this->attachInvoiceToRegistration($registration, [
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
        $invoice = $this->attachInvoiceToRegistration($registration, [
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

        $this->attachInvoiceToRegistration($registration, [
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

    private function createProvaWithEventFee(?float $eventFee): Prova
    {
        $creator = User::factory()->create();

        $event = Event::query()->create([
            'titulo' => 'Meeting Regional',
            'descricao' => 'Evento de teste',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'prova',
            'taxa_inscricao' => $eventFee,
            'estado' => 'agendado',
            'criado_por' => $creator->id,
        ]);

        $competition = Competition::query()->create([
            'nome' => 'Competicao Teste',
            'local' => 'Piscina Municipal',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->addDay()->toDateString(),
            'tipo' => 'natacao',
            'evento_id' => $event->id,
        ]);

        return Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => 'LIVRE',
            'distancia_m' => 100,
            'genero' => 'M',
            'ordem_prova' => 1,
        ]);
    }

    private function createRegistrationWithoutInvoice(): CompetitionRegistration
    {
        $athlete = User::factory()->create();
        $prova = $this->createProvaWithEventFee(null);

        return CompetitionRegistration::query()->create([
            'prova_id' => $prova->id,
            'user_id' => $athlete->id,
            'estado' => 'inscrito',
            'valor_inscricao' => 0,
            'fatura_id' => null,
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function attachInvoiceToRegistration(CompetitionRegistration $registration, array $overrides = []): Invoice
    {
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

        $registration->update(['fatura_id' => $invoice->id]);

        return $invoice;
    }
}

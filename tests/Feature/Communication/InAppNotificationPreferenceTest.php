<?php

namespace Tests\Feature\Communication;

use App\Mail\PublicFormSubmissionReceived;
use App\Models\InAppAlert;
use App\Models\NotificationPreference;
use App\Models\PublicFormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InAppNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_email_remains_active_when_in_app_alerts_are_disabled(): void
    {
        Mail::fake();
        User::factory()->create(['perfil' => 'admin']);

        NotificationPreference::query()->create([
            'email_notificacoes' => true,
            'alertas_aplicacao' => false,
            'alertas_atividade' => true,
            'alertas_pagamento' => true,
            'automacoes_financeiro' => true,
            'automacoes_eventos' => true,
            'automacoes_logistica' => true,
            'automacoes_faturas_financeiras' => true,
            'automacoes_movimentos_financeiros' => true,
            'automacoes_convocatorias_eventos' => true,
            'automacoes_requisicoes_logistica' => true,
            'automacoes_alertas_operacionais' => true,
        ]);

        $this->post('/junta-te', $this->contactPayload())->assertRedirect('/junta-te');

        $submission = PublicFormSubmission::query()->sole();

        Mail::assertQueued(PublicFormSubmissionReceived::class);
        $this->assertNotNull($submission->fresh()->email_queued_at);
        $this->assertNull($submission->fresh()->admin_notified_at);
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_website_in_app_alert_requires_global_channel_and_activity_category(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['perfil' => 'admin']);

        NotificationPreference::query()->create([
            'email_notificacoes' => false,
            'alertas_aplicacao' => true,
            'alertas_atividade' => false,
            'alertas_pagamento' => true,
            'automacoes_financeiro' => true,
            'automacoes_eventos' => true,
            'automacoes_logistica' => true,
            'automacoes_faturas_financeiras' => true,
            'automacoes_movimentos_financeiros' => true,
            'automacoes_convocatorias_eventos' => true,
            'automacoes_requisicoes_logistica' => true,
            'automacoes_alertas_operacionais' => true,
        ]);

        $this->post('/junta-te', $this->contactPayload())->assertRedirect('/junta-te');

        $submission = PublicFormSubmission::query()->sole();

        Mail::assertNothingQueued();
        $this->assertNull($submission->fresh()->email_queued_at);
        $this->assertNull($submission->fresh()->admin_notified_at);
        $this->assertDatabaseMissing('in_app_alerts', ['user_id' => $admin->id]);
    }

    public function test_website_in_app_alert_is_created_when_both_switches_are_enabled(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['perfil' => 'admin']);

        NotificationPreference::query()->create([
            'email_notificacoes' => false,
            'alertas_aplicacao' => true,
            'alertas_atividade' => true,
            'alertas_pagamento' => true,
            'automacoes_financeiro' => true,
            'automacoes_eventos' => true,
            'automacoes_logistica' => true,
            'automacoes_faturas_financeiras' => true,
            'automacoes_movimentos_financeiros' => true,
            'automacoes_convocatorias_eventos' => true,
            'automacoes_requisicoes_logistica' => true,
            'automacoes_alertas_operacionais' => true,
        ]);

        $this->post('/junta-te', $this->contactPayload())->assertRedirect('/junta-te');

        $submission = PublicFormSubmission::query()->sole();

        $this->assertDatabaseHas('in_app_alerts', [
            'user_id' => $admin->id,
            'link' => '/website/pedidos/'.$submission->id,
            'is_read' => false,
        ]);
        $this->assertNotNull($submission->fresh()->admin_notified_at);
    }

    private function contactPayload(): array
    {
        return [
            'athleteName' => 'Atleta Adulto',
            'birthDate' => today()->subYears(25)->toDateString(),
            'email' => 'atleta@example.com',
            'phone' => '912345678',
            'program' => 'Masters',
            'experience' => 'Entre 2 e 5 anos',
            'notes' => 'Pedido de informação.',
            'consent' => true,
            'company' => '',
        ];
    }
}

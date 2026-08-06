<?php

namespace Tests\Feature\Configuracoes;

use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InAppNotificationPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_notification_endpoint_persists_in_app_channel_without_disabling_other_preferences(): void
    {
        $admin = User::factory()->create([
            'perfil' => 'admin',
            'email_verified_at' => now(),
        ]);

        $preferences = NotificationPreference::query()->create($this->payload(true));

        $this->actingAs($admin)
            ->put(route('configuracoes.notificacoes.update'), $this->payload(false))
            ->assertRedirect(route('configuracoes'));

        $preferences->refresh();

        $this->assertFalse($preferences->alertas_aplicacao);
        $this->assertTrue($preferences->email_notificacoes);
        $this->assertTrue($preferences->alertas_pagamento);
        $this->assertTrue($preferences->alertas_atividade);
        $this->assertTrue($preferences->automacoes_financeiro);
        $this->assertTrue($preferences->automacoes_eventos);
        $this->assertTrue($preferences->automacoes_logistica);
    }

    public function test_notification_endpoint_creates_preferences_with_in_app_channel_when_record_is_missing(): void
    {
        $admin = User::factory()->create([
            'perfil' => 'admin',
            'email_verified_at' => now(),
        ]);

        $this->assertDatabaseCount('notification_preferences', 0);

        $this->actingAs($admin)
            ->put(route('configuracoes.notificacoes.update'), $this->payload(false))
            ->assertRedirect(route('configuracoes'));

        $this->assertDatabaseCount('notification_preferences', 1);
        $this->assertFalse((bool) NotificationPreference::query()->sole()->alertas_aplicacao);
    }

    public function test_notification_model_has_no_http_request_coupling(): void
    {
        $modelSource = file_get_contents(app_path('Models/NotificationPreference.php'));
        $middlewareSource = file_get_contents(app_path('Http/Middleware/PersistInAppNotificationPreference.php'));

        $this->assertStringNotContainsString('Illuminate\\Http\\Request', $modelSource);
        $this->assertStringNotContainsString('routeIs(', $modelSource);
        $this->assertStringNotContainsString('protected static function booted', $modelSource);
        $this->assertStringContainsString("routeIs('configuracoes.notificacoes.update')", $middlewareSource);
        $this->assertStringContainsString("'alertas_aplicacao' => ['required', 'boolean']", $middlewareSource);
    }

    public function test_configuration_channel_is_not_mounted_outside_the_inertia_context(): void
    {
        $appSource = file_get_contents(resource_path('js/app.tsx'));
        $controlSource = file_get_contents(resource_path('js/Components/Configuracoes/InAppNotificationChannelControl.tsx'));

        $this->assertStringNotContainsString('InAppNotificationChannelControl', $appSource);
        $this->assertStringContainsString('createPortal', $controlSource);
        $this->assertStringContainsString('Automações da Comunicação', $controlSource);
        $this->assertStringContainsString("querySelector<HTMLElement>('.grid')", $controlSource);
        $this->assertStringNotContainsString('fixed bottom-5 right-5', $controlSource);
        $this->assertStringContainsString("route('configuracoes.notificacoes.update')", $controlSource);
        $this->assertStringContainsString('alertas_aplicacao: enabled', $controlSource);
        $this->assertStringContainsString('email_notificacoes: Boolean', $controlSource);
        $this->assertStringContainsString('automacoes_alertas_operacionais: Boolean', $controlSource);
    }

    /** @return array<string, bool> */
    private function payload(bool $inAppEnabled): array
    {
        return [
            'email_notificacoes' => true,
            'alertas_aplicacao' => $inAppEnabled,
            'alertas_pagamento' => true,
            'alertas_atividade' => true,
            'automacoes_financeiro' => true,
            'automacoes_eventos' => true,
            'automacoes_logistica' => true,
            'automacoes_faturas_financeiras' => true,
            'automacoes_movimentos_financeiros' => true,
            'automacoes_convocatorias_eventos' => true,
            'automacoes_requisicoes_logistica' => true,
            'automacoes_alertas_operacionais' => true,
        ];
    }
}

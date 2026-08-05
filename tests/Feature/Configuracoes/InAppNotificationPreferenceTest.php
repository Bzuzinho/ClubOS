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

    public function test_configuration_surface_mounts_global_in_app_channel_control(): void
    {
        $appSource = file_get_contents(resource_path('js/app.tsx'));
        $controlSource = file_get_contents(resource_path('js/Components/Configuracoes/InAppNotificationChannelControl.tsx'));

        $this->assertStringContainsString('InAppNotificationChannelControl', $appSource);
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

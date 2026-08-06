<?php

namespace Tests\Feature\Communication;

use App\Models\InAppAlert;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\Communication\InAppAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InAppAlertServicePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicitly_disabled_global_channel_blocks_every_service_alert(): void
    {
        $user = User::factory()->create();
        NotificationPreference::query()->create([
            'alertas_aplicacao' => false,
        ]);

        $created = app(InAppAlertService::class)->createAlerts([
            'title' => 'Alerta bloqueado',
            'message' => 'Este alerta não deve ser criado.',
        ], collect([
            ['user_id' => $user->id],
        ]));

        $this->assertSame(0, $created);
        $this->assertSame(0, InAppAlert::query()->count());
    }

    public function test_enabled_global_channel_allows_service_alert(): void
    {
        $user = User::factory()->create();
        NotificationPreference::query()->create([
            'alertas_aplicacao' => true,
        ]);

        $created = app(InAppAlertService::class)->createAlerts([
            'title' => 'Alerta permitido',
            'message' => 'Este alerta deve ser criado.',
            'type' => 'warning',
        ], collect([
            ['user_id' => $user->id],
        ]));

        $this->assertSame(1, $created);
        $this->assertDatabaseHas('in_app_alerts', [
            'user_id' => $user->id,
            'title' => 'Alerta permitido',
            'type' => 'warning',
        ]);
    }

    public function test_missing_preference_row_preserves_legacy_alert_behavior(): void
    {
        $user = User::factory()->create();

        $created = app(InAppAlertService::class)->createAlerts([
            'title' => 'Compatibilidade',
            'message' => 'Sem configuração explícita mantém o comportamento atual.',
        ], collect([
            ['user_id' => $user->id],
        ]));

        $this->assertSame(1, $created);
        $this->assertSame(1, InAppAlert::query()->count());
    }
}

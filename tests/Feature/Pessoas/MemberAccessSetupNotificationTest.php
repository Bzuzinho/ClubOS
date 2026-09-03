<?php

declare(strict_types=1);

namespace Tests\Feature\Pessoas;

use App\Models\DadosConfiguracao;
use App\Models\User;
use App\Notifications\MemberAccessSetupNotification;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberAccessSetupNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_setup_invite_grants_explicit_platform_access_and_records_sender(): void
    {
        $actor = User::factory()->create();
        $member = User::factory()->create([
            'email' => 'member-access@example.test',
            'email_utilizador' => 'member-access@example.test',
        ]);

        $this->actingAs($actor);

        (new MemberAccessSetupNotification('test-token'))->toMail($member);

        $configuration = DadosConfiguracao::query()
            ->where('user_id', $member->id)
            ->firstOrFail();

        $this->assertTrue((bool) $configuration->platform_access_enabled);
        $this->assertSame((string) $actor->id, (string) $configuration->platform_access_granted_by);
        $this->assertNotNull($configuration->platform_access_granted_at);
        $this->assertNotNull($configuration->ultimo_envio_acessos_at);
        $this->assertTrue(app(PlatformAccessService::class)->hasPlatformAccess($member));
    }

    public function test_resending_access_setup_invite_is_idempotent_for_platform_configuration(): void
    {
        $member = User::factory()->create([
            'email' => 'member-resend@example.test',
            'email_utilizador' => 'member-resend@example.test',
        ]);

        (new MemberAccessSetupNotification('first-token'))->toMail($member);
        (new MemberAccessSetupNotification('second-token'))->toMail($member);

        $this->assertSame(
            1,
            DadosConfiguracao::query()->where('user_id', $member->id)->count(),
        );
        $this->assertTrue(app(PlatformAccessService::class)->hasPlatformAccess($member));
    }
}

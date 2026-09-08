<?php

declare(strict_types=1);

namespace Tests\Feature\Pessoas;

use App\Models\User;
use App\Notifications\MemberAccessSetupNotification;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberAccessSetupNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_setup_email_uses_plain_language_and_does_not_mutate_access(): void
    {
        $member = User::factory()->create([
            'name' => 'Maria Ferreira',
            'email' => 'member-access@example.test',
            'email_utilizador' => 'member-access@example.test',
        ]);

        $mail = (new MemberAccessSetupNotification('test-token'))->toMail($member);

        $this->assertSame('O seu acesso ao BSCN está pronto', $mail->subject);
        $this->assertSame('Criar o meu acesso', $mail->actionText);
        $this->assertStringContainsString('/ativar-acesso/test-token', (string) $mail->actionUrl);
        $this->assertStringContainsString('browser', implode(' ', $mail->introLines));
        $this->assertDatabaseMissing('dados_configuracao', ['user_id' => $member->id]);
    }

    public function test_recording_access_setup_invite_is_idempotent_for_platform_configuration(): void
    {
        $actor = User::factory()->create();
        $member = User::factory()->create([
            'email' => 'member-resend@example.test',
            'email_utilizador' => 'member-resend@example.test',
        ]);

        $service = app(PlatformAccessService::class);
        $service->recordInvitationSent($member, $actor);
        $service->recordInvitationSent($member, $actor);

        $this->assertSame(
            1,
            \App\Models\DadosConfiguracao::query()->where('user_id', $member->id)->count(),
        );
        $this->assertFalse($service->hasPlatformAccess($member));
        $this->assertTrue($service->canActivatePlatformAccess($member));
        $this->assertSame('invited', $service->explainPlatformAccess($member)['state']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Pessoas;

use App\Models\User;
use App\Services\Pessoas\PlatformAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class MemberAccessOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invited_member_sets_password_is_logged_in_and_reaches_optional_install_step(): void
    {
        $member = User::factory()->unverified()->create([
            'email' => 'novo.acesso@example.test',
            'email_utilizador' => 'novo.acesso@example.test',
        ]);
        $access = app(PlatformAccessService::class);
        $access->recordInvitationSent($member);
        $token = Password::broker('member_access')->createToken($member);

        $this->assertFalse($access->hasPlatformAccess($member));
        $this->assertTrue($access->canActivatePlatformAccess($member));
        $this->assertTrue(Password::broker('member_access')->tokenExists($member, $token));
        $this->assertFalse(Password::broker()->tokenExists($member, $token));

        $this->post(route('login'), [
            'email' => $member->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->get(route('access.activate', [
            'token' => $token,
            'email' => $member->email,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ActivateAccess')
                ->where('invitationValid', true));

        $response = $this->post(route('access.activate.store'), [
            'token' => $token,
            'email' => $member->email,
            'password' => 'NovaSenha2026',
            'password_confirmation' => 'NovaSenha2026',
        ]);

        $response->assertRedirect(route('onboarding.welcome'));
        $this->assertAuthenticatedAs($member);
        $this->assertTrue(Hash::check('NovaSenha2026', $member->fresh()->password));
        $this->assertNotNull($member->fresh()->email_verified_at);
        $this->assertSame('active', $access->explainPlatformAccess($member->fresh())['state']);

        $this->get(route('onboarding.welcome'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Onboarding/InstallApp')
                ->where('firstAccess', true)
                ->has('continueUrl'));
    }

    public function test_revoked_invitation_cannot_be_used(): void
    {
        $member = User::factory()->create(['email' => 'revogado@example.test']);
        $access = app(PlatformAccessService::class);
        $access->recordInvitationSent($member);
        $token = Password::broker('member_access')->createToken($member);
        $access->revokePlatformAccess($member);

        $this->assertDatabaseMissing('member_access_tokens', ['email' => $member->email]);

        $this->post(route('access.activate.store'), [
            'token' => $token,
            'email' => $member->email,
            'password' => 'NovaSenha2026',
            'password_confirmation' => 'NovaSenha2026',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_invalid_invitation_opens_a_clear_recovery_state(): void
    {
        $member = User::factory()->create(['email' => 'convite-invalido@example.test']);

        $this->get(route('access.activate', [
            'token' => 'token-invalido',
            'email' => $member->email,
        ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/ActivateAccess')
                ->where('invitationValid', false));
    }

    public function test_installation_help_is_public_and_app_entry_preserves_browser_login(): void
    {
        $this->get(route('onboarding.install'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Onboarding/InstallApp')
                ->where('firstAccess', false)
                ->where('continueUrl', route('login')));

        $this->get(route('app.entry'))
            ->assertRedirect(route('login'));
    }
}

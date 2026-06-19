<?php

namespace Tests\Feature\Integration;

use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Performance\AuthenticatedModuleWarmupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class AuthenticatedModuleWarmupPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_with_request_restores_original_user_and_keeps_session_intact(): void
    {
        $originalUser = User::factory()->create();
        $warmupUser = User::factory()->create();

        $this->actingAs($originalUser);
        session()->put('warmup_probe', 'ok');

        $sessionId = session()->getId();
        $service = new AuthenticatedModuleWarmupService(
            Mockery::mock(UserTypeAccessControlService::class)
        );

        $this->invokeRunWithRequest($service, $warmupUser, function () use ($warmupUser): void {
            $this->assertSame($warmupUser->id, Auth::guard('web')->id());
        });

        $this->assertSame($originalUser->id, Auth::guard('web')->id());
        $this->assertSame($sessionId, session()->getId());
        $this->assertSame('ok', session()->get('warmup_probe'));
    }

    public function test_run_with_request_uses_forget_user_when_no_original_user_exists(): void
    {
        $warmupUser = User::factory()->create();
        $service = new AuthenticatedModuleWarmupService(
            Mockery::mock(UserTypeAccessControlService::class)
        );
        $guard = Mockery::mock();

        Auth::shouldReceive('guard')
            ->with('web')
            ->andReturn($guard);
        Auth::shouldReceive('shouldUse')
            ->once()
            ->with('web');

        $guard->shouldReceive('user')
            ->once()
            ->andReturnNull();
        $guard->shouldReceive('setUser')
            ->once()
            ->with($warmupUser);
        $guard->shouldReceive('forgetUser')
            ->once();
        $guard->shouldReceive('logout')
            ->never();

        $this->invokeRunWithRequest($service, $warmupUser, function (): void {
            $this->assertTrue(true);
        });
    }

    private function invokeRunWithRequest(
        AuthenticatedModuleWarmupService $service,
        User $user,
        callable $callback,
    ): void {
        $method = new ReflectionMethod($service, 'runWithRequest');
        $method->setAccessible(true);
        $method->invoke($service, $user, '/dashboard', $callback);
    }
}
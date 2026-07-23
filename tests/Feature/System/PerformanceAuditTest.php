<?php

namespace Tests\Feature\System;

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsurePermissionAccess;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PerformanceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_slow_request_middleware_does_not_log_when_disabled(): void
    {
        config(['clubos.performance.log_enabled' => false]);
        Log::spy();

        Route::middleware('web')->get('/_test/performance/disabled', fn () => response('ok'));

        $this->get('/_test/performance/disabled')->assertOk();

        Log::shouldNotHaveReceived('info');
    }

    public function test_slow_request_middleware_logs_when_enabled_and_masks_sensitive_bindings(): void
    {
        config([
            'clubos.performance.log_enabled' => true,
            'clubos.performance.slow_request_threshold_ms' => 0,
            'clubos.performance.slow_query_threshold_ms' => 0,
        ]);
        Log::spy();

        Route::middleware('web')->get('/_test/performance/enabled', function () {
            DB::select('select ? as email, ? as token', ['secret@example.test', str_repeat('a', 32)]);

            return response('ok');
        });

        $this->get('/_test/performance/enabled')->assertOk();

        Log::shouldHaveReceived('info')
            ->with('clubos.slow_request', \Mockery::on(function (array $context): bool {
                $encoded = json_encode($context);

                return ($context['query_count'] ?? 0) >= 1
                    && str_contains($encoded, '[email]')
                    && str_contains($encoded, '[masked]')
                    && ! str_contains($encoded, 'secret@example.test');
            }))
            ->once();
    }

    public function test_performance_audit_command_outputs_json_and_report_path(): void
    {
        Storage::fake('local');
        $reportPath = 'storage/app/audits/p1-performance-test.json';

        $exitCode = Artisan::call('system:audit-performance', [
            '--json' => true,
            '--report-path' => $reportPath,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('findings', $payload);
        $this->assertFileExists(base_path($reportPath));
    }

    public function test_shared_props_do_not_load_global_communication_members_outside_communication_pages(): void
    {
        $this->withoutMiddleware([EnsureModuleAccess::class, EnsurePermissionAccess::class]);

        $admin = User::factory()->create();
        User::factory()->count(120)->create();

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', (string) app(HandleInertiaRequests::class)->version(request()))
            ->get(route('dashboard'));

        $response->assertOk();
        $this->assertSame([], $response->json('props.communicationMembers'));
    }

    public function test_members_index_uses_paginated_member_payload(): void
    {
        $this->withoutMiddleware([EnsureModuleAccess::class, EnsurePermissionAccess::class]);

        Cache::flush();
        $admin = User::factory()->create();
        User::factory()->count(125)->create();

        $response = $this
            ->actingAs($admin)
            ->withHeader('X-Inertia', 'true')
            ->withHeader('X-Inertia-Version', (string) app(HandleInertiaRequests::class)->version(request()))
            ->get(route('membros.index', ['tab' => 'list']));

        $response->assertOk();

        $this->assertCount(50, $response->json('props.members'));
        $this->assertSame(126, $response->json('props.membersPagination.total'));
        $this->assertSame(50, $response->json('props.membersPagination.per_page'));
    }

    public function test_login_request_still_uses_platform_access_gate(): void
    {
        $this->assertStringContainsString('PlatformAccessService', file_get_contents(app_path('Http/Requests/Auth/LoginRequest.php')));
        $this->assertStringContainsString('hasPlatformAccess', file_get_contents(app_path('Http/Requests/Auth/LoginRequest.php')));
        $this->assertTrue(class_exists(LoginRequest::class));
    }
}

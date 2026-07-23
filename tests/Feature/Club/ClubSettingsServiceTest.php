<?php

namespace Tests\Feature\Club;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ClubSetting;
use App\Services\Club\ClubSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class ClubSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_club_settings_are_cached_for_shared_props(): void
    {
        $settings = ClubSetting::create([
            'nome_clube' => 'Clube Cache',
            'sigla' => 'CC',
        ]);

        $service = app(ClubSettingsService::class);

        $this->assertSame('Clube Cache', $service->get()['nome_clube']);

        $settings->forceFill(['nome_clube' => 'Clube Alterado'])->save();

        $this->assertSame('Clube Cache', $service->get()['nome_clube']);
    }

    public function test_club_settings_fallback_is_returned_and_logged_when_database_fails(): void
    {
        Log::spy();

        $service = new class extends ClubSettingsService {
            public function model(): ?ClubSetting
            {
                throw new RuntimeException('timeout expired password=secret username=clubos');
            }
        };

        $payload = $service->get();

        $this->assertSame('Clube', $payload['nome_clube']);
        $this->assertSame('CLUBE', $payload['sigla']);

        Log::shouldHaveReceived('warning')
            ->with('club_settings.fallback_used', \Mockery::on(function (array $context): bool {
                $encoded = json_encode($context);

                return str_contains($encoded, 'timeout expired')
                    && str_contains($encoded, 'password=[masked]')
                    && str_contains($encoded, 'user=[masked]')
                    && ! str_contains($encoded, 'secret')
                    && ! str_contains($encoded, 'clubos');
            }))
            ->once();
    }

    public function test_inertia_shared_club_settings_are_safe_when_database_fails(): void
    {
        $this->app->instance(ClubSettingsService::class, new class extends ClubSettingsService {
            public function model(): ?ClubSetting
            {
                throw new RuntimeException('connection timeout');
            }
        });

        $shared = app(HandleInertiaRequests::class)->share(Request::create('/dashboard'));

        $this->assertSame('Clube', $shared['clubSettings']['nome_clube']);
        $this->assertSame('CLUBE', $shared['clubSettings']['sigla']);
    }
}

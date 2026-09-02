<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Services\Financeiro\FiscalProvider\FiscalDocumentProviderAdapter;
use App\Services\Financeiro\FiscalProvider\FiscalDocumentProviderResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FiscalOperationalReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_wintouch_contract_is_ready_and_report_is_aggregate_read_only(): void
    {
        config([
            'fiscal.operation_mode' => 'manual_wintouch',
            'fiscal.provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
        ]);
        $path = storage_path('framework/testing/fiscal-operational-readiness.json');

        $exitCode = Artisan::call('finance:audit-fiscal-operational-readiness', [
            '--json' => true,
            '--report-path' => $path,
            '--fail-on-not-ready' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $report = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('h4-fiscal-operational-readiness-v1', $payload['version']);
        $this->assertTrue($payload['read_only']);
        $this->assertTrue($payload['contract']['manual_contract_configured']);
        $this->assertFalse($payload['contract']['automatic_issue_enabled']);
        $this->assertSame(0, $payload['contract']['automatic_provider_adapter_count']);
        $this->assertTrue($payload['schema_detected']['required_schema_present']);
        $this->assertTrue($payload['route_contract']['all_required_routes_present']);
        $this->assertTrue($payload['summary']['ready']);
        $this->assertSame($payload, $report);
        $this->assertArrayNotHasKey('items', $report);
        $this->assertArrayNotHasKey('findings', $report);
        $this->assertTrue($report['interpretation']['no_data_changed']);
    }

    public function test_manual_mode_fails_closed_when_automatic_adapter_is_registered(): void
    {
        config([
            'fiscal.operation_mode' => 'manual_wintouch',
            'fiscal.provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
        ]);
        $this->app->instance(FiscalDocumentProviderAdapter::class, new ReadinessFakeFiscalProviderAdapter());

        $exitCode = Artisan::call('finance:audit-fiscal-operational-readiness', [
            '--json' => true,
            '--fail-on-not-ready' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertSame(['wintouch'], $payload['contract']['automatic_provider_adapters']);
        $this->assertFalse($payload['summary']['ready']);
    }

    public function test_provider_api_mode_without_adapter_is_not_a_valid_productive_contract(): void
    {
        config([
            'fiscal.operation_mode' => 'provider_api',
            'fiscal.provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
        ]);

        $exitCode = Artisan::call('finance:audit-fiscal-operational-readiness', [
            '--json' => true,
            '--fail-on-not-ready' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['contract']['manual_contract_configured']);
        $this->assertFalse($payload['contract']['automatic_issue_enabled']);
        $this->assertFalse($payload['summary']['ready']);
    }
}

final class ReadinessFakeFiscalProviderAdapter implements FiscalDocumentProviderAdapter
{
    public function provider(): string
    {
        return FiscalDocumentRequest::PROVIDER_WINTOUCH;
    }

    public function issueReceipt(array $payload): FiscalDocumentProviderResult
    {
        return FiscalDocumentProviderResult::failure('not_called');
    }
}

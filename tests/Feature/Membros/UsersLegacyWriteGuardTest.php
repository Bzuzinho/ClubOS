<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Services\Members\UsersLegacyWriteGuardScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class UsersLegacyWriteGuardTest extends TestCase
{
    public function test_scanner_has_no_violations_in_current_application_code(): void
    {
        $scanner = app(UsersLegacyWriteGuardScanner::class);

        $result = $scanner->scan();

        $this->assertSame([], $result['violations']);
        $this->assertGreaterThan(0, $result['blocked_fields_count']);
        $this->assertGreaterThan(0, $result['scanned_files']);
    }

    public function test_scanner_detects_direct_assignment_to_legacy_users_field(): void
    {
        $scanner = app(UsersLegacyWriteGuardScanner::class);

        $filePath = base_path('storage/app/write-guard-test/Bad.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$user->nif = '123';\n");

        try {
            $result = $scanner->scan([
                'storage/app/write-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $this->assertNotEmpty($result['violations']);

        $fields = array_values(array_unique(array_column($result['violations'], 'field')));
        $this->assertContains('nif', $fields);
    }

    public function test_scanner_detects_user_update_payload_with_legacy_field(): void
    {
        $scanner = app(UsersLegacyWriteGuardScanner::class);

        $filePath = base_path('storage/app/write-guard-test/BadUpdate.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$user->update(['morada' => 'Rua']);\n");

        try {
            $result = $scanner->scan([
                'storage/app/write-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $this->assertNotEmpty($result['violations']);

        $fields = array_values(array_unique(array_column($result['violations'], 'field')));
        $this->assertContains('morada', $fields);
    }

    public function test_scanner_ignores_read_only_fallback_service_allowlist(): void
    {
        $scanner = app(UsersLegacyWriteGuardScanner::class);

        $result = $scanner->scan([
            'app/Services/Members/MemberDataReadService.php',
        ], $scanner->defaultAllowlist());

        $this->assertSame([], $result['violations']);
        $this->assertSame(0, $result['scanned_files']);
    }

    public function test_command_returns_success_when_no_violations(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-write-guard', [
            '--json' => true,
            '--fail-on-violation' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertTrue($payload['passed']);
        $this->assertSame([], $payload['violations']);
        $this->assertNull($payload['failure_reason']);
    }

    public function test_config_blocked_fields_include_known_personal_and_configuration_legacy_fields(): void
    {
        $scanner = app(UsersLegacyWriteGuardScanner::class);
        $blockedFields = $scanner->blockedFields();

        $this->assertContains('nif', $blockedFields);
        $this->assertContains('morada', $blockedFields);
        $this->assertContains('contacto', $blockedFields);
        $this->assertContains('rgpd', $blockedFields);
        $this->assertContains('consentimento', $blockedFields);
        $this->assertContains('afiliacao', $blockedFields);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeArtisanJsonOutput(string $output): array
    {
        $decoded = json_decode(trim($output), true);

        $this->assertIsArray($decoded, 'Command output should be valid JSON.');

        return $decoded;
    }
}
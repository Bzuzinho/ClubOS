<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class UsersLegacyReadGuardRailTest extends TestCase
{
    public function test_fail_on_finding_passes_with_current_repository_state(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--fail-on-finding' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertTrue((bool) ($payload['passed'] ?? false));
        $this->assertSame(0, (int) ($payload['summary']['findings_count'] ?? -1));
        $this->assertSame([], $payload['grouped_summary']['by_remediation_group'] ?? null);
    }

    public function test_fail_on_finding_blocks_artificial_legacy_read_regression_in_temp_fixture(): void
    {
        $fixtureRoot = base_path('storage/framework/testing/users-legacy-read-guard');
        $fixtureFile = $fixtureRoot . '/BadRead.php';

        File::ensureDirectoryExists($fixtureRoot);
        File::put($fixtureFile, <<<'PHP'
    <?php

    $name = $user->nome_completo;
    PHP);

        try {
            $exitCode = Artisan::call('members:audit-users-legacy-read', [
                '--json' => true,
                '--fail-on-finding' => true,
                '--path' => ['storage/framework/testing/users-legacy-read-guard'],
            ]);

            $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        } finally {
            File::delete($fixtureFile);
            File::deleteDirectory($fixtureRoot);
        }

        $this->assertSame(1, $exitCode);
        $this->assertFalse((bool) ($payload['passed'] ?? true));
        $this->assertSame(
            'Legacy users read audit failed. Existem leituras diretas de campos legacy bloqueados.',
            $payload['failure_reason'] ?? null,
        );

        $this->assertGreaterThan(0, (int) ($payload['summary']['findings_count'] ?? 0));
        $fields = array_values(array_unique(array_column($payload['findings'] ?? [], 'field')));
        $this->assertContains('nome_completo', $fields);
    }

    public function test_scanner_detects_artificial_legacy_read_regression_in_temp_fixture_path(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);
        $fixtureRoot = base_path('storage/framework/testing/users-legacy-read-guard');
        $fixtureFile = $fixtureRoot . '/BadRead.php';

        File::ensureDirectoryExists($fixtureRoot);
        File::put($fixtureFile, <<<'PHP'
    <?php

    $nif = $member->nif;
    PHP);

        try {
            $result = $scanner->scan([
                'storage/framework/testing/users-legacy-read-guard',
            ], []);
        } finally {
            File::delete($fixtureFile);
            File::deleteDirectory($fixtureRoot);
        }

        $this->assertGreaterThan(0, $result['summary']['findings_count']);
        $this->assertSame('nif', $result['findings'][0]['field']);
        $this->assertSame('$object->field', $result['findings'][0]['pattern']);
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class UsersLegacyReadAuditCommandTest extends TestCase
{
    public function test_scanner_detects_direct_property_read(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/BadRead.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$nif = \$user->nif;\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $this->assertSame(1, $result['summary']['findings_count']);
        $this->assertSame('nif', $result['findings'][0]['field']);
        $this->assertSame('$object->field', $result['findings'][0]['pattern']);
    }

    public function test_scanner_ignores_supplier_direct_property_reads(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/SupplierRead.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$nif = \$supplier->nif;\n\$morada = \$supplier->morada;\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $this->assertSame([], $result['findings']);
    }

    public function test_scanner_detects_query_select_of_legacy_field(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/BadSelect.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\nuse App\\Models\\User;\n\nUser::query()->select('id', 'nif')->get();\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $fields = array_values(array_unique(array_column($result['findings'], 'field')));

        $this->assertContains('nif', $fields);
    }

    public function test_scanner_detects_data_get_legacy_field(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/BadDataGet.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$morada = data_get(\$user, 'morada');\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $fields = array_values(array_unique(array_column($result['findings'], 'field')));

        $this->assertContains('morada', $fields);
    }

    public function test_scanner_ignores_generic_array_payload_access_even_with_member_context(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/GenericArrayPayload.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, <<<'PHP'
<?php

function mutate(\App\Models\User $member, array $data): void
{
    $data['declaracao_transporte'] = 'members/transport/file.pdf';
}
PHP);

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $this->assertSame([], $result['findings']);
    }

    public function test_scanner_detects_member_array_payload_access(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/MemberArrayPayload.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, <<<'PHP'
<?php

function inspect(\App\Models\User $member, array $memberData): string
{
    return (string) $memberData['arquivo_rgpd'];
}
PHP);

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $this->assertSame(1, $result['summary']['findings_count']);
        $this->assertSame('arquivo_rgpd', $result['findings'][0]['field']);
        $this->assertSame("\$row['field']", $result['findings'][0]['pattern']);
    }

    public function test_scanner_ignores_allowlisted_member_data_read_service(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $result = $scanner->scan([
            'app/Services/Members/MemberDataReadService.php',
        ], $scanner->defaultAllowlist());

        $this->assertSame([], $result['findings']);
        $this->assertSame(0, $result['summary']['scanned_files']);
    }

    public function test_default_allowlist_includes_member_document_data_resolver(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $this->assertContains(
            'app/Services/Members/MemberDocumentDataResolver.php',
            $scanner->defaultAllowlist(),
        );
    }

    public function test_default_allowlist_includes_member_identity_display_resolver(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $this->assertContains(
            'app/Services/Members/MemberIdentityDisplayResolver.php',
            $scanner->defaultAllowlist(),
        );
    }

    public function test_default_allowlist_does_not_include_portal_profile_controller(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $this->assertNotContains(
            'app/Http/Controllers/PortalProfileController.php',
            $scanner->defaultAllowlist(),
        );
    }

    public function test_command_reports_no_personal_profile_findings_for_portal_profile_controller_path(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Http/Controllers/PortalProfileController.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $personalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_personal_profile'
        );

        $this->assertCount(0, $personalFindings);
    }

    public function test_command_reports_no_identity_display_findings_for_member_identity_display_resolver_path(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Services/Members/MemberIdentityDisplayResolver.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $identityFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_identity_display'
        );

        $this->assertCount(0, $identityFindings);
    }

    public function test_command_path_for_backfill_financeiro_integracoes_passes_fail_on_finding(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--path' => ['app/Console/Commands/BackfillFinanceiroIntegracoes.php'],
            '--fail-on-finding' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_returns_json_with_summary(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('grouped_summary', $payload);
        $this->assertArrayHasKey('findings', $payload);
        $this->assertArrayHasKey('passed', $payload);
        $this->assertArrayHasKey('failure_reason', $payload);
        $this->assertArrayHasKey('by_remediation_group', $payload['grouped_summary']);
    }

    public function test_scanner_classifies_fiscal_finance_findings(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/app/Http/Controllers/FinanceiroController.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$nif = \$user->nif;\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(base_path('storage/app/read-guard-test'));
        }

        $this->assertSame('member_fiscal_finance', $result['findings'][0]['remediation_group']);
        $this->assertSame('P1', $result['findings'][0]['migration_priority']);
    }

    public function test_scanner_classifies_document_findings(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/app/Http/Controllers/PortalDocumentController.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$rgpd = \$user->rgpd;\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(base_path('storage/app/read-guard-test'));
        }

        $this->assertSame('member_documents_configuration', $result['findings'][0]['remediation_group']);
        $this->assertSame('P1', $result['findings'][0]['migration_priority']);
    }

    public function test_scanner_classifies_profile_findings(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/app/Http/Controllers/PortalProfileController.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$morada = \$member->morada;\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(base_path('storage/app/read-guard-test'));
        }

        $this->assertSame('member_personal_profile', $result['findings'][0]['remediation_group']);
        $this->assertSame('P2', $result['findings'][0]['migration_priority']);
    }

    public function test_scanner_classifies_display_name_findings(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);

        $filePath = base_path('storage/app/read-guard-test/app/Http/Controllers/GenericListController.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$nome = \$user->nome_completo;\n");

        try {
            $result = $scanner->scan([
                'storage/app/read-guard-test',
            ], []);
        } finally {
            File::delete($filePath);
            File::deleteDirectory(base_path('storage/app/read-guard-test'));
        }

        $this->assertSame('member_identity_display', $result['findings'][0]['remediation_group']);
    }

    public function test_command_summary_only_works(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--summary-only' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_group_by_group_works(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--group-by' => 'group',
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_command_fail_on_finding_behavior_can_fail_on_custom_path(): void
    {
        $filePath = base_path('storage/app/read-guard-test/BadForCommand.php');
        File::ensureDirectoryExists(dirname($filePath));
        File::put($filePath, "<?php\n\n\$nif = \$user->nif;\n");

        try {
            $exitCode = Artisan::call('members:audit-users-legacy-read', [
                '--json' => true,
                '--fail-on-finding' => true,
                '--path' => ['storage/app/read-guard-test'],
            ]);

            $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        } finally {
            File::delete($filePath);
            File::deleteDirectory(dirname($filePath));
        }

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['passed']);
        $this->assertNotNull($payload['failure_reason']);
        $this->assertGreaterThan(0, $payload['summary']['findings_count']);
    }

    public function test_command_fail_on_finding_passes_on_current_repository_state(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--fail-on-finding' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertTrue((bool) $payload['passed']);
        $this->assertSame(0, $payload['summary']['findings_count']);
        $this->assertSame([], $payload['grouped_summary']['by_remediation_group']);
    }

    public function test_config_blocked_fields_include_known_fields(): void
    {
        $scanner = app(UsersLegacyReadScanner::class);
        $blockedFields = $scanner->blockedFields();

        $this->assertContains('nif', $blockedFields);
        $this->assertContains('morada', $blockedFields);
        $this->assertContains('contacto', $blockedFields);
        $this->assertContains('rgpd', $blockedFields);
        $this->assertContains('consentimento', $blockedFields);
        $this->assertContains('afiliacao', $blockedFields);
        $this->assertContains('num_federacao', $blockedFields);
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

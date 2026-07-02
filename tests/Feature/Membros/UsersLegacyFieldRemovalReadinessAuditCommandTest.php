<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class UsersLegacyFieldRemovalReadinessAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_in_human_mode_with_exit_code_zero(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-field-removal-readiness');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Audit users legacy field removal readiness (read-only)',
            Artisan::output(),
        );
    }

    public function test_command_returns_valid_json_with_m411_version(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-field-removal-readiness', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertSame('M4.11', $payload['version'] ?? null);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('fields', $payload);
        $this->assertArrayHasKey('grouped_summary', $payload);
    }

    public function test_summary_includes_expected_keys(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-field-removal-readiness', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $summary = $this->decodeArtisanJsonOutput(Artisan::output())['summary'] ?? [];

        $this->assertArrayHasKey('total_configured_fields', $summary);
        $this->assertArrayHasKey('fields_existing_in_schema', $summary);
        $this->assertArrayHasKey('keep_operational_count', $summary);
        $this->assertArrayHasKey('needs_review_count', $summary);
        $this->assertArrayHasKey('passed', $summary);
    }

    public function test_payload_contains_expected_key_fields_and_classifications(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-field-removal-readiness', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $fields = $this->indexFieldsByName($payload['fields'] ?? []);

        foreach (['nome_completo', 'contacto', 'nif', 'rgpd', 'num_federacao', 'ativo_desportivo', 'encarregado_educacao'] as $requiredField) {
            $this->assertArrayHasKey($requiredField, $fields);
        }

        $this->assertTrue(
            array_key_exists('telefone', $fields) || array_key_exists('numero_cartao_cidadao', $fields),
            'Expected at least one unknown_review key field (telefone or numero_cartao_cidadao).',
        );

        $this->assertSame('keep_operational', $fields['name']['removal_status'] ?? null);
        $this->assertSame('keep_operational', $fields['ativo_desportivo']['removal_status'] ?? null);
        $this->assertSame('keep_operational', $fields['encarregado_educacao']['removal_status'] ?? null);
        $this->assertSame('candidate_after_legacy_write_cleanup', $fields['nome_completo']['removal_status'] ?? null);
        $this->assertSame('dados_pessoais', $fields['nome_completo']['canonical_area'] ?? null);
        $this->assertSame('candidate_after_legacy_write_cleanup', $fields['rgpd']['removal_status'] ?? null);
        $this->assertSame('dados_configuracao', $fields['rgpd']['canonical_area'] ?? null);
    }

    public function test_fail_on_active_legacy_read_passes_in_current_state(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-field-removal-readiness', [
            '--json' => true,
            '--fail-on-active-legacy-read' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertTrue((bool) ($payload['summary']['passed'] ?? false));
        $this->assertSame(0, (int) ($payload['summary']['active_legacy_read_fields_count'] ?? -1));
    }

    public function test_report_can_be_written_with_report_path_option(): void
    {
        $relativePath = 'storage/app/audits/test-users-legacy-field-removal-readiness.json';
        $absolutePath = base_path($relativePath);

        File::delete($absolutePath);

        try {
            $exitCode = Artisan::call('members:audit-users-legacy-field-removal-readiness', [
                '--report-path' => $relativePath,
                '--json' => true,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertTrue(File::exists($absolutePath));

            $decoded = json_decode((string) File::get($absolutePath), true);
            $this->assertIsArray($decoded);
            $this->assertSame('M4.11', $decoded['version'] ?? null);
        } finally {
            File::delete($absolutePath);
        }
    }

    public function test_command_does_not_write_to_database(): void
    {
        $beforeUsersCount = DB::table('users')->count();

        $exitCode = Artisan::call('members:audit-users-legacy-field-removal-readiness', [
            '--json' => true,
        ]);

        $afterUsersCount = DB::table('users')->count();

        $this->assertSame(0, $exitCode);
        $this->assertSame($beforeUsersCount, $afterUsersCount);
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    private function indexFieldsByName(array $fields): array
    {
        $indexed = [];

        foreach ($fields as $fieldRow) {
            if (!is_array($fieldRow)) {
                continue;
            }

            $field = is_string($fieldRow['field'] ?? null) ? trim((string) $fieldRow['field']) : '';
            if ($field === '') {
                continue;
            }

            $indexed[$field] = $fieldRow;
        }

        return $indexed;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeArtisanJsonOutput(string $output): array
    {
        $decoded = json_decode(trim($output), true);

        $this->assertIsArray($decoded, 'Command output should be valid JSON.');

        return $decoded;
    }
}

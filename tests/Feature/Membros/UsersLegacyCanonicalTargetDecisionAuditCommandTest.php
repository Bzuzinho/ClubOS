<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class UsersLegacyCanonicalTargetDecisionAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_in_human_mode_with_exit_code_zero(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-canonical-targets');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Audit users legacy canonical targets (read-only)',
            Artisan::output(),
        );
    }

    public function test_json_returns_m415_version_and_expected_summary_counts(): void
    {
        $payload = $this->runJsonCommand();

        $this->assertSame('M4.15', $payload['version'] ?? null);
        $this->assertSame(3, (int) ($payload['summary']['fields_analyzed'] ?? 0));
        $this->assertSame(3, (int) ($payload['summary']['explicit_decisions_count'] ?? 0));
        $this->assertSame(0, (int) ($payload['summary']['write_allowed_count'] ?? 0));
        $this->assertSame(3, (int) ($payload['summary']['blocked_write_count'] ?? 0));
        $this->assertSame(1, (int) ($payload['summary']['architecture_decision_required_count'] ?? 0));
        $this->assertSame(2, (int) ($payload['summary']['canonical_payload_key_pending_count'] ?? 0));
        $this->assertTrue((bool) ($payload['summary']['passed'] ?? false));
    }

    public function test_field_option_limits_analysis(): void
    {
        $payload = $this->runJsonCommand([
            '--field' => 'data_atestado_medico',
        ]);

        $this->assertSame(1, (int) ($payload['summary']['fields_analyzed'] ?? 0));
        $this->assertCount(1, $payload['fields'] ?? []);
        $this->assertSame('data_atestado_medico', $payload['fields'][0]['field'] ?? null);
    }

    public function test_invalid_field_fails(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-canonical-targets', [
            '--field' => 'invalid_field',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Campo invalido para auditoria canonical targets', Artisan::output());
    }

    public function test_report_path_writes_json_report(): void
    {
        $relativePath = 'storage/app/audits/test-users-legacy-canonical-targets.json';
        $absolutePath = base_path($relativePath);

        File::delete($absolutePath);

        try {
            $exitCode = Artisan::call('members:audit-users-legacy-canonical-targets', [
                '--json' => true,
                '--report-path' => $relativePath,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertTrue(File::exists($absolutePath));

            $decoded = json_decode((string) File::get($absolutePath), true);
            $this->assertIsArray($decoded);
            $this->assertSame('M4.15', $decoded['version'] ?? null);
        } finally {
            File::delete($absolutePath);
        }
    }

    public function test_fail_on_missing_decision_passes_with_current_config(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-canonical-targets', [
            '--fail-on-missing-decision' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_fail_on_write_allowed_passes_with_current_config(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-canonical-targets', [
            '--fail-on-write-allowed' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_fields_expose_expected_decisions_and_owners(): void
    {
        $payload = $this->runJsonCommand();
        $fields = $this->indexFieldsByName($payload['fields'] ?? []);

        $this->assertSame('route_to_sports_domain', $fields['data_atestado_medico']['decision'] ?? null);
        $this->assertSame('desportivo', $fields['data_atestado_medico']['owner_area'] ?? null);
        $this->assertSame('add_to_personal_payload_contract', $fields['estado_civil']['decision'] ?? null);
        $this->assertSame('add_to_personal_payload_contract_or_discard_as_historical', $fields['numero_irmaos']['decision'] ?? null);
    }

    public function test_preflight_now_includes_decision_reason_and_next_action_and_stays_blocked(): void
    {
        $exitCode = Artisan::call('members:preflight-users-legacy-only-backfill', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $fields = $this->indexFieldsByName($payload['fields'] ?? []);

        $this->assertFalse((bool) ($payload['commit_allowed'] ?? true));
        $this->assertNotEmpty($fields['data_atestado_medico']['decision'] ?? null);
        $this->assertNotEmpty($fields['data_atestado_medico']['reason'] ?? null);
        $this->assertNotEmpty($fields['data_atestado_medico']['next_action'] ?? null);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function runJsonCommand(array $options = []): array
    {
        $exitCode = Artisan::call('members:audit-users-legacy-canonical-targets', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);

        return $this->decodeArtisanJsonOutput(Artisan::output());
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
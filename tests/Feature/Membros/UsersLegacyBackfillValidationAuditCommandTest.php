<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class UsersLegacyBackfillValidationAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_in_human_mode_with_exit_code_zero(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Audit users legacy backfill validation (read-only)',
            Artisan::output(),
        );
    }

    public function test_command_returns_valid_json_with_m413_version(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertSame('M4.13', $payload['version'] ?? null);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('fields', $payload);
        $this->assertArrayHasKey('grouped_summary', $payload);
    }

    public function test_summary_contains_expected_keys(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $summary = $this->decodeArtisanJsonOutput(Artisan::output())['summary'] ?? [];

        $this->assertArrayHasKey('fields_analyzed', $summary);
        $this->assertArrayHasKey('users_analyzed', $summary);
        $this->assertArrayHasKey('ready_for_cleanup_count', $summary);
        $this->assertArrayHasKey('needs_backfill_count', $summary);
        $this->assertArrayHasKey('needs_manual_review_count', $summary);
    }

    public function test_field_option_limits_analysis_to_requested_field(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
            '--json' => true,
            '--field' => 'arquivo_rgpd',
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertSame(1, (int) ($payload['summary']['fields_analyzed'] ?? 0));
        $this->assertCount(1, $payload['fields'] ?? []);
        $this->assertSame('arquivo_rgpd', $payload['fields'][0]['field'] ?? null);
    }

    public function test_report_path_writes_json_report(): void
    {
        $relativePath = 'storage/app/audits/test-users-legacy-backfill-validation.json';
        $absolutePath = base_path($relativePath);

        File::delete($absolutePath);

        try {
            $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
                '--json' => true,
                '--report-path' => $relativePath,
            ]);

            $this->assertSame(0, $exitCode);
            $this->assertTrue(File::exists($absolutePath));

            $decoded = json_decode((string) File::get($absolutePath), true);
            $this->assertIsArray($decoded);
            $this->assertSame('M4.13', $decoded['version'] ?? null);
        } finally {
            File::delete($absolutePath);
        }
    }

    public function test_declaracao_transporte_counts_empty_matching_and_legacy_only_and_marks_needs_backfill(): void
    {
        $emptyUser = User::factory()->create(['declaracao_transporte' => null]);
        DadosConfiguracao::create([
            'user_id' => $emptyUser->id,
            'declaracao_transporte' => null,
        ]);

        $matchingUser = User::factory()->create(['declaracao_transporte' => ' 1 ']);
        DadosConfiguracao::create([
            'user_id' => $matchingUser->id,
            'declaracao_transporte' => true,
        ]);

        User::factory()->create(['declaracao_transporte' => 'true']);

        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
            '--json' => true,
            '--field' => 'declaracao_transporte',
        ]);

        $this->assertSame(0, $exitCode);

        $field = $this->indexFieldsByName($this->decodeArtisanJsonOutput(Artisan::output())['fields'] ?? [])['declaracao_transporte'];

        $this->assertSame(1, (int) ($field['empty_both_count'] ?? 0));
        $this->assertSame(1, (int) ($field['matching_non_empty_count'] ?? 0));
        $this->assertSame(1, (int) ($field['legacy_only_count'] ?? 0));
        $this->assertSame(0, (int) ($field['divergent_count'] ?? 0));
        $this->assertSame('needs_backfill', $field['readiness_status'] ?? null);
    }

    public function test_declaracao_transporte_divergence_marks_needs_manual_review(): void
    {
        $divergentUser = User::factory()->create(['declaracao_transporte' => '1']);
        DadosConfiguracao::create([
            'user_id' => $divergentUser->id,
            'declaracao_transporte' => false,
        ]);

        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
            '--json' => true,
            '--field' => 'declaracao_transporte',
        ]);

        $this->assertSame(0, $exitCode);

        $field = $this->indexFieldsByName($this->decodeArtisanJsonOutput(Artisan::output())['fields'] ?? [])['declaracao_transporte'];

        $this->assertSame(1, (int) ($field['divergent_count'] ?? 0));
        $this->assertSame('needs_manual_review', $field['readiness_status'] ?? null);
    }

    public function test_fail_on_divergence_returns_failure_when_divergence_exists(): void
    {
        $user = User::factory()->create(['declaracao_transporte' => '1']);
        DadosConfiguracao::create([
            'user_id' => $user->id,
            'declaracao_transporte' => false,
        ]);

        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
            '--json' => true,
            '--field' => 'declaracao_transporte',
            '--fail-on-divergence' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertFalse((bool) ($payload['summary']['passed'] ?? true));
        $this->assertSame(1, (int) ($payload['summary']['total_divergent_count'] ?? 0));
    }

    public function test_fail_on_legacy_only_returns_failure_when_legacy_only_exists(): void
    {
        User::factory()->create(['declaracao_transporte' => '1']);

        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
            '--json' => true,
            '--field' => 'declaracao_transporte',
            '--fail-on-legacy-only' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertFalse((bool) ($payload['summary']['passed'] ?? true));
        $this->assertSame(1, (int) ($payload['summary']['total_legacy_only_count'] ?? 0));
    }

    public function test_command_does_not_write_to_database(): void
    {
        $before = [
            'users' => DB::table('users')->count(),
            'dados_pessoais' => DB::table('dados_pessoais')->count(),
            'dados_configuracao' => DB::table('dados_configuracao')->count(),
        ];

        $exitCode = Artisan::call('members:audit-users-legacy-backfill-validation', [
            '--json' => true,
        ]);

        $after = [
            'users' => DB::table('users')->count(),
            'dados_pessoais' => DB::table('dados_pessoais')->count(),
            'dados_configuracao' => DB::table('dados_configuracao')->count(),
        ];

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, $after);
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
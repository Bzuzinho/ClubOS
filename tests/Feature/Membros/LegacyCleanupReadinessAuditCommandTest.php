<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\LegacyCleanupReadinessAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyCleanupReadinessAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_estado_civil_passes_when_legacy_and_canonical_match(): void
    {
        $user = User::factory()->create();

        if (Schema::hasColumn('users', 'estado_civil')) {
            $user->forceFill(['estado_civil' => 'casado'])->save();
        }

        $this->ensurePersonalField($user->id, 'estado_civil', 'casado');

        $exitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'estado_civil',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $field = $this->indexFieldsByName($payload['fields'] ?? [])['estado_civil'] ?? null;

        $this->assertIsArray($field);
        $this->assertTrue((bool) ($field['ready_for_cleanup'] ?? false));
        $this->assertSame('ready_for_cleanup', $field['cleanup_status'] ?? null);
    }

    public function test_numero_irmaos_passes_when_legacy_and_canonical_match(): void
    {
        $user = User::factory()->create();

        if (Schema::hasColumn('users', 'numero_irmaos')) {
            $user->forceFill(['numero_irmaos' => 3])->save();
        }

        $this->ensurePersonalField($user->id, 'numero_irmaos', 3);

        $exitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'numero_irmaos',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $field = $this->indexFieldsByName($payload['fields'] ?? [])['numero_irmaos'] ?? null;

        $this->assertIsArray($field);
        $this->assertTrue((bool) ($field['ready_for_cleanup'] ?? false));
        $this->assertSame('ready_for_cleanup', $field['cleanup_status'] ?? null);
    }

    public function test_estado_civil_divergence_behavior_depends_on_legacy_column_presence(): void
    {
        $user = User::factory()->create();

        if (Schema::hasColumn('users', 'estado_civil')) {
            $user->forceFill(['estado_civil' => 'solteiro'])->save();
        }

        $this->ensurePersonalField($user->id, 'estado_civil', 'casado');

        $exitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'estado_civil',
            '--fail-on-not-ready' => true,
        ]);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $field = $this->indexFieldsByName($payload['fields'] ?? [])['estado_civil'] ?? null;
        $this->assertIsArray($field);

        if (Schema::hasColumn('users', 'estado_civil')) {
            $this->assertSame(1, $exitCode);
            $this->assertSame(1, (int) ($field['divergent_count'] ?? 0));
            $this->assertFalse((bool) ($field['ready_for_cleanup'] ?? true));

            return;
        }

        $this->assertSame(0, $exitCode);
        $this->assertSame(0, (int) ($field['divergent_count'] ?? 0));
        $this->assertTrue((bool) ($field['ready_for_cleanup'] ?? false));
    }

    public function test_audit_fails_when_forbidden_legacy_read_exists(): void
    {
        $testFile = base_path('storage/framework/testing/m5-forbidden-read.php');
        File::ensureDirectoryExists(dirname($testFile));
        File::put($testFile, "<?php\n\$value = \$user->estado_civil;\n");

        /** @var LegacyCleanupReadinessAuditor $auditor */
        $auditor = app(LegacyCleanupReadinessAuditor::class);
        $auditor->useScanPathsForTesting(['storage/framework/testing/m5-forbidden-read.php'], []);
        $this->app->instance(LegacyCleanupReadinessAuditor::class, $auditor);

        try {
            $exitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
                '--json' => true,
                '--field' => 'estado_civil',
                '--fail-on-not-ready' => true,
            ]);

            $payload = $this->decodeArtisanJsonOutput(Artisan::output());
            $field = $this->indexFieldsByName($payload['fields'] ?? [])['estado_civil'] ?? null;

            $this->assertIsArray($field);

            $forbiddenCount = (int) ($field['forbidden_legacy_read_count'] ?? 0);
            if ($forbiddenCount > 0) {
                $this->assertSame(1, $exitCode);
                $this->assertFalse((bool) ($field['checks']['no_forbidden_legacy_reads'] ?? true));

                return;
            }

            $this->assertSame(0, $exitCode);
            $this->assertTrue((bool) ($field['checks']['no_forbidden_legacy_reads'] ?? false));
        } finally {
            $auditor->useScanPathsForTesting(null, null);
            File::delete($testFile);
        }
    }

    public function test_audit_fails_when_forbidden_legacy_write_exists(): void
    {
        $testFile = base_path('storage/framework/testing/m5-forbidden-write.php');
        File::ensureDirectoryExists(dirname($testFile));
        File::put($testFile, "<?php\n\$user->estado_civil = 'casado';\n");

        /** @var LegacyCleanupReadinessAuditor $auditor */
        $auditor = app(LegacyCleanupReadinessAuditor::class);
        $auditor->useScanPathsForTesting([], ['storage/framework/testing/m5-forbidden-write.php']);
        $this->app->instance(LegacyCleanupReadinessAuditor::class, $auditor);

        try {
            $exitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
                '--json' => true,
                '--field' => 'estado_civil',
                '--fail-on-not-ready' => true,
            ]);

            $payload = $this->decodeArtisanJsonOutput(Artisan::output());
            $field = $this->indexFieldsByName($payload['fields'] ?? [])['estado_civil'] ?? null;

            $this->assertIsArray($field);

            $forbiddenCount = (int) ($field['forbidden_legacy_write_count'] ?? 0);
            if ($forbiddenCount > 0) {
                $this->assertSame(1, $exitCode);
                $this->assertFalse((bool) ($field['checks']['no_forbidden_legacy_writes'] ?? true));

                return;
            }

            $this->assertSame(0, $exitCode);
            $this->assertTrue((bool) ($field['checks']['no_forbidden_legacy_writes'] ?? false));
        } finally {
            $auditor->useScanPathsForTesting(null, null);
            File::delete($testFile);
        }
    }

    public function test_json_returns_valid_structure(): void
    {
        $exitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'estado_civil',
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertSame('M5', $payload['version'] ?? null);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('fields', $payload);
        $this->assertArrayHasKey('passed', $payload['summary'] ?? []);
        $this->assertArrayHasKey('failure_reason', $payload['summary'] ?? []);
    }

    public function test_fail_on_not_ready_returns_non_zero_when_applicable(): void
    {
        $user = User::factory()->create();

        if (Schema::hasColumn('users', 'estado_civil')) {
            $user->forceFill(['estado_civil' => 'solteiro'])->save();
        }

        $this->ensurePersonalField($user->id, 'estado_civil', 'casado');

        $exitCode = Artisan::call('members:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'estado_civil',
            '--fail-on-not-ready' => true,
        ]);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        if (Schema::hasColumn('users', 'estado_civil')) {
            $this->assertSame(1, $exitCode);
            $this->assertFalse((bool) ($payload['summary']['passed'] ?? true));
            $this->assertSame(1, (int) ($payload['summary']['not_ready_count'] ?? 0));

            return;
        }

        $this->assertSame(0, $exitCode);
        $this->assertTrue((bool) ($payload['summary']['passed'] ?? false));
        $this->assertSame(0, (int) ($payload['summary']['not_ready_count'] ?? 0));
    }

    private function ensurePersonalField(string $userId, string $field, mixed $value): void
    {
        $row = DadosPessoais::query()->firstOrNew(['user_id' => $userId]);
        if (!$row->exists) {
            $row->id = (string) Str::uuid();
            $row->user_id = $userId;
        }

        $row->{$field} = $value;
        $row->save();
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

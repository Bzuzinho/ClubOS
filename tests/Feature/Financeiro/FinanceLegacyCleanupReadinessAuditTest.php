<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\FinanceLegacyCleanupReadinessAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinanceLegacyCleanupReadinessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_centro_custo_is_ready_when_fallback_and_divergence_are_zero(): void
    {
        $plan = $this->createPlan('Plano CC Ready');
        $user = $this->createActiveUser();
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $costCenter = $this->createCostCenter('CC-READY', 'Centro Custo Ready');
        $this->attachCostCenter($user, $costCenter->id, 1.0);

        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'centro_custo',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput();
        $field = $this->indexFieldsByName($payload['fields'] ?? [])['centro_custo'] ?? null;

        $this->assertIsArray($field);
        $this->assertTrue((bool) ($field['ready_for_cleanup'] ?? false));
        $this->assertSame(0, (int) (($field['metrics']['fallback_count'] ?? 1)));
        $this->assertSame(0, (int) (($field['metrics']['divergence_count'] ?? 1)));
        $this->assertSame(0, (int) (($field['metrics']['invalid_count'] ?? 1)));
    }

    public function test_tipo_mensalidade_is_ready_when_fallback_and_divergence_are_zero(): void
    {
        $plan = $this->createPlan('Plano Mensalidade Ready');
        $user = $this->createActiveUser();
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'tipo_mensalidade',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput();
        $field = $this->indexFieldsByName($payload['fields'] ?? [])['tipo_mensalidade'] ?? null;

        $this->assertIsArray($field);
        $this->assertTrue((bool) ($field['ready_for_cleanup'] ?? false));
    }

    public function test_conta_corrente_can_be_ready_without_matching_net_debt(): void
    {
        $user = $this->createActiveUser(['conta_corrente' => 0]);
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'conta_corrente_manual' => 15]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'mes' => 'Mensalidade FC1',
            'data_fatura' => now()->subDay()->toDateString(),
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 20,
            'valor_pago' => 0,
            'valor_em_aberto' => 20,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'conta_corrente',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput();
        $field = $this->indexFieldsByName($payload['fields'] ?? [])['conta_corrente'] ?? null;

        $this->assertIsArray($field);
        $this->assertTrue((bool) ($field['ready_for_cleanup'] ?? false));
        $this->assertSame(0, (int) (($field['metrics']['divergence_count'] ?? 1)));
    }

    public function test_fallback_blocks_readiness(): void
    {
        $plan = $this->createPlan('Plano Fallback Block');
        $user = $this->createActiveUser(['tipo_mensalidade' => $plan->id]);

        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'tipo_mensalidade',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeArtisanJsonOutput();
        $field = $this->indexFieldsByName($payload['fields'] ?? [])['tipo_mensalidade'] ?? null;
        $this->assertIsArray($field);
        $this->assertFalse((bool) ($field['ready_for_cleanup'] ?? true));
        $this->assertSame(1, (int) (($field['metrics']['fallback_count'] ?? 0)));
    }

    public function test_divergence_blocks_readiness(): void
    {
        $user = $this->createActiveUser(['conta_corrente' => 1]);
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'conta_corrente_manual' => 5]);

        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'conta_corrente',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeArtisanJsonOutput();
        $field = $this->indexFieldsByName($payload['fields'] ?? [])['conta_corrente'] ?? null;
        $this->assertIsArray($field);
        $this->assertFalse((bool) ($field['ready_for_cleanup'] ?? true));
        $this->assertSame(1, (int) (($field['metrics']['divergence_count'] ?? 0)));
    }

    public function test_prohibited_operational_read_blocks_readiness(): void
    {
        $plan = $this->createPlan('Plano Read Block');
        $user = $this->createActiveUser();
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $tempFile = 'app/Http/Controllers/FC1TempReadController.php';
        File::put(base_path($tempFile), "<?php\n\$value = \$user->tipo_mensalidade;\n");

        $this->bindAuditorForTesting([$tempFile]);

        try {
            $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
                '--json' => true,
                '--field' => 'tipo_mensalidade',
                '--fail-on-not-ready' => true,
            ]);

            $this->assertSame(1, $exitCode);

            $payload = $this->decodeArtisanJsonOutput();
            $summary = $payload['summary'] ?? [];
            $this->assertSame(1, (int) ($summary['prohibited_read_findings_count'] ?? 0));
        } finally {
            File::delete(base_path($tempFile));
        }
    }

    public function test_prohibited_operational_write_blocks_readiness(): void
    {
        $plan = $this->createPlan('Plano Write Block');
        $user = $this->createActiveUser();
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $tempFile = 'app/Http/Controllers/FC1TempWriteController.php';
        File::put(base_path($tempFile), "<?php\n\$user->tipo_mensalidade = 'legacy';\n");

        $this->bindAuditorForTesting([$tempFile]);

        try {
            $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
                '--json' => true,
                '--field' => 'tipo_mensalidade',
                '--fail-on-not-ready' => true,
            ]);

            $this->assertSame(1, $exitCode);

            $payload = $this->decodeArtisanJsonOutput();
            $summary = $payload['summary'] ?? [];
            $this->assertSame(1, (int) ($summary['prohibited_write_findings_count'] ?? 0));
        } finally {
            File::delete(base_path($tempFile));
        }
    }

    public function test_unknown_finding_blocks_readiness(): void
    {
        $plan = $this->createPlan('Plano Unknown Block');
        $user = $this->createActiveUser();
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $tempFile = 'scripts/fc1-unknown-read.php';
        File::ensureDirectoryExists(dirname(base_path($tempFile)));
        File::put(base_path($tempFile), "<?php\n\$v = \$user->tipo_mensalidade;\n");

        $this->bindAuditorForTesting([$tempFile]);

        try {
            $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
                '--json' => true,
                '--field' => 'tipo_mensalidade',
                '--fail-on-not-ready' => true,
            ]);

            $this->assertSame(1, $exitCode);

            $payload = $this->decodeArtisanJsonOutput();
            $summary = $payload['summary'] ?? [];
            $this->assertSame(1, (int) ($summary['unknown_findings_count'] ?? 0));
        } finally {
            File::delete(base_path($tempFile));
        }
    }

    public function test_audit_or_migration_finding_does_not_block(): void
    {
        $plan = $this->createPlan('Plano Audit Path');
        $user = $this->createActiveUser();
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $tempFile = 'database/migrations/2099_01_01_000001_fc1_temp_read.php';
        File::put(base_path($tempFile), "<?php\n\$v = \$user->tipo_mensalidade;\n");

        $this->bindAuditorForTesting([$tempFile]);

        try {
            $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
                '--json' => true,
                '--field' => 'tipo_mensalidade',
                '--fail-on-not-ready' => true,
            ]);

            $this->assertSame(0, $exitCode);
        } finally {
            File::delete(base_path($tempFile));
        }
    }

    public function test_test_fixture_finding_does_not_block(): void
    {
        $plan = $this->createPlan('Plano Test Fixture Path');
        $user = $this->createActiveUser();
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $tempFile = 'tests/Feature/Financeiro/FC1TempFixtureRead.php';
        File::put(base_path($tempFile), "<?php\n\$v = \$user->tipo_mensalidade;\n");

        $this->bindAuditorForTesting([$tempFile]);

        try {
            $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
                '--json' => true,
                '--field' => 'tipo_mensalidade',
                '--fail-on-not-ready' => true,
            ]);

            $this->assertSame(0, $exitCode);
        } finally {
            File::delete(base_path($tempFile));
        }
    }

    public function test_field_option_limits_scope(): void
    {
        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'centro_custo',
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput();
        $this->assertSame(1, (int) ($payload['summary']['total_fields'] ?? 0));
        $this->assertCount(1, $payload['fields'] ?? []);
        $this->assertSame('centro_custo', $payload['fields'][0]['field'] ?? null);
    }

    public function test_json_payload_contract_is_valid(): void
    {
        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput();
        $this->assertSame('fc1-finance-legacy-cleanup-readiness-v1', $payload['version'] ?? null);
        $this->assertArrayHasKey('generated_at', $payload);
        $this->assertArrayHasKey('scope', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('fields', $payload);
        $this->assertArrayHasKey('code_findings', $payload);
    }

    public function test_report_path_writes_report_file(): void
    {
        $this->bindAuditorForTesting([]);

        $relativePath = 'storage/app/audits/fc1-command-test-report.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);

        @unlink($absolutePath);
    }

    public function test_fail_on_not_ready_returns_expected_exit_code(): void
    {
        $plan = $this->createPlan('Plano Exit Code');
        $this->createActiveUser(['tipo_mensalidade' => $plan->id]);

        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--field' => 'tipo_mensalidade',
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    private function bindAuditorForTesting(array $paths): void
    {
        /** @var FinanceLegacyCleanupReadinessAuditor $auditor */
        $auditor = app(FinanceLegacyCleanupReadinessAuditor::class);
        $auditor->useScanPathsForTesting($paths);
        $this->app->instance(FinanceLegacyCleanupReadinessAuditor::class, $auditor);
    }

    private function createPlan(string $name): MonthlyFee
    {
        return MonthlyFee::query()->create([
            'designacao' => $name,
            'valor' => 30,
            'ativo' => true,
        ]);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function createActiveUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
            'conta_corrente' => 0,
        ], $overrides));
    }

    private function createCostCenter(string $codigo, string $nome): CostCenter
    {
        return CostCenter::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'tipo' => 'departamento',
            'ativo' => true,
        ]);
    }

    private function attachCostCenter(User $user, string $centerId, float $peso): void
    {
        DB::table('centro_custo_user')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'centro_custo_id' => $centerId,
            'peso' => $peso,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeArtisanJsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<array<string,mixed>> $fields
     * @return array<string,array<string,mixed>>
     */
    private function indexFieldsByName(array $fields): array
    {
        $indexed = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = is_string($field['field'] ?? null) ? trim((string) $field['field']) : '';
            if ($name === '') {
                continue;
            }

            $indexed[$name] = $field;
        }

        return $indexed;
    }
}

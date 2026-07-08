<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Financeiro\FinanceLegacyCleanupReadinessAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class FinanceLegacyCleanupReadinessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_fc2_fields_are_ready_when_legacy_columns_are_missing(): void
    {
        $plan = $this->createPlan('Plano FC2 Ready');
        $user = User::factory()->create(['estado' => 'inativo']);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
            'conta_corrente_manual' => 15,
        ]);

        $this->bindAuditorForTesting([]);

        $exitCode = Artisan::call('finance:audit-legacy-cleanup-readiness', [
            '--json' => true,
            '--fail-on-not-ready' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput();
        $fields = $this->indexFieldsByName($payload['fields'] ?? []);

        foreach (['centro_custo', 'tipo_mensalidade', 'conta_corrente'] as $field) {
            $this->assertFalse((bool) ($fields[$field]['legacy_column_exists'] ?? true));
            $this->assertTrue((bool) ($fields[$field]['cleanup_completed'] ?? false));
            $this->assertTrue((bool) ($fields[$field]['ready_for_cleanup'] ?? false));
        }
    }

    public function test_prohibited_operational_read_still_blocks_readiness(): void
    {
        $plan = $this->createPlan('Plano Read Block');
        $user = User::factory()->create(['estado' => 'ativo']);

        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $tempFile = 'app/Http/Controllers/FC2TempReadController.php';
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

    public function test_prohibited_operational_write_still_blocks_readiness(): void
    {
        $plan = $this->createPlan('Plano Write Block');
        $user = User::factory()->create(['estado' => 'ativo']);

        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $plan->id]);

        $tempFile = 'app/Http/Controllers/FC2TempWriteController.php';
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

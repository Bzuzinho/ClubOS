<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class BackfillMemberMonthlyFeesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write(): void
    {
        $plan = $this->createPlan('Plano Dry Run');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        $this->assertNull(DadosFinanceiros::query()->where('user_id', $user->id)->value('mensalidade_id'));

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', ['--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertNull(DadosFinanceiros::query()->where('user_id', $user->id)->value('mensalidade_id'));
    }

    public function test_apply_writes_canonical_monthly_fee_id(): void
    {
        $plan = $this->createPlan('Plano Apply Canonico');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($plan->id, DadosFinanceiros::query()->where('user_id', $user->id)->value('mensalidade_id'));
    }

    public function test_apply_uses_sync_service_normalization_and_dual_write_behavior(): void
    {
        $plan = $this->createPlan('Plano Apply Sync Service');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => '  ' . $plan->id . '  ',
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($plan->id, DadosFinanceiros::query()->where('user_id', $user->id)->value('mensalidade_id'));
        $this->assertSame($plan->id, User::query()->findOrFail($user->id)->tipo_mensalidade);
    }

    public function test_apply_keeps_legacy_users_tipo_mensalidade(): void
    {
        $plan = $this->createPlan('Plano Legacy Preservado');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($plan->id, $user->fresh()->tipo_mensalidade);
    }

    public function test_apply_does_not_change_other_dados_financeiros_fields(): void
    {
        $plan = $this->createPlan('Plano Original');
        $replacement = $this->createPlan('Plano Novo');

        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $replacement->id,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => null,
            'discount_type' => 'fixed',
            'discount_value' => 7.25,
            'discount_reason' => 'Manter desconto',
            'conta_corrente_manual' => 13.40,
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $finance = DadosFinanceiros::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame($replacement->id, $finance->mensalidade_id);
        $this->assertSame('fixed', (string) $finance->discount_type);
        $this->assertSame('7.25', (string) $finance->discount_value);
        $this->assertSame('Manter desconto', (string) $finance->discount_reason);
        $this->assertSame('13.40', (string) $finance->conta_corrente_manual);

        $this->assertNotSame($plan->id, $finance->mensalidade_id);
    }

    public function test_divergent_blocks_apply(): void
    {
        $canonical = $this->createPlan('Plano Divergente Canonico');
        $legacy = $this->createPlan('Plano Divergente Legacy');

        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $legacy->id,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $canonical->id,
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeJsonOutput();
        $this->assertFalse((bool) ($payload['preflight']['can_apply'] ?? true));
        $this->assertSame(1, (int) ($payload['summary']['divergent_count'] ?? 0));
    }

    public function test_invalid_legacy_reference_blocks_apply(): void
    {
        User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => 'missing-plan-id',
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = $this->decodeJsonOutput();
        $this->assertFalse((bool) ($payload['preflight']['can_apply'] ?? true));
        $this->assertSame(1, (int) ($payload['summary']['invalid_legacy_reference_count'] ?? 0));
    }

    public function test_missing_required_is_not_changed_and_does_not_block_safe_apply(): void
    {
        $plan = $this->createPlan('Plano Seguro');

        $ready = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        $missingRequired = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($plan->id, DadosFinanceiros::query()->where('user_id', $ready->id)->value('mensalidade_id'));
        $this->assertNull(DadosFinanceiros::query()->where('user_id', $missingRequired->id)->value('mensalidade_id'));

        $payload = $this->decodeJsonOutput();
        $this->assertSame(1, (int) ($payload['summary']['missing_required_count'] ?? 0));
        $this->assertSame(1, (int) ($payload['migration']['migrated_count'] ?? 0));
    }

    public function test_second_apply_execution_is_idempotent(): void
    {
        $plan = $this->createPlan('Plano Idempotente');
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        $firstExit = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
        ]);
        $this->assertSame(0, $secondExit);

        $payload = $this->decodeJsonOutput();
        $this->assertSame(0, (int) ($payload['migration']['migrated_count'] ?? -1));
        $this->assertSame(1, (int) ($payload['summary']['already_canonical_count'] ?? 0));
        $this->assertSame($plan->id, DadosFinanceiros::query()->where('user_id', $user->id)->value('mensalidade_id'));
    }

    public function test_json_output_has_required_contract_keys(): void
    {
        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', ['--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeJsonOutput();
        $this->assertArrayHasKey('version', $payload);
        $this->assertArrayHasKey('generated_at', $payload);
        $this->assertArrayHasKey('mode', $payload);
        $this->assertArrayHasKey('scope', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('classifications', $payload);
        $this->assertArrayHasKey('cases', $payload);
        $this->assertArrayHasKey('preflight', $payload);
    }

    public function test_report_path_writes_file(): void
    {
        $relativePath = 'storage/app/audits/member-monthly-fees-backfill-command-test.json';
        $absolutePath = base_path($relativePath);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);

        @unlink($absolutePath);
    }

    public function test_user_option_limits_scope(): void
    {
        $planA = $this->createPlan('Plano Scope A');
        $planB = $this->createPlan('Plano Scope B');

        $target = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $planA->id,
        ]);

        $other = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $planB->id,
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
            '--user' => (string) $target->id,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($planA->id, DadosFinanceiros::query()->where('user_id', $target->id)->value('mensalidade_id'));
        $this->assertNull(DadosFinanceiros::query()->where('user_id', $other->id)->value('mensalidade_id'));

        $payload = $this->decodeJsonOutput();
        $this->assertSame((string) $target->id, (string) ($payload['scope']['user'] ?? ''));
        $this->assertSame(1, (int) ($payload['summary']['total'] ?? 0));
    }

    public function test_fail_on_skipped_option_returns_non_zero_when_apply_skips_members(): void
    {
        $plan = $this->createPlan('Plano Skip');
        User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => $plan->id,
        ]);

        // This member is intentionally outside backfill scope (missing_required),
        // so apply should migrate one and skip one.
        User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);

        $exitCode = Artisan::call('finance:backfill-member-monthly-fees', [
            '--apply' => true,
            '--json' => true,
            '--fail-on-skipped' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    private function createPlan(string $designacao): MonthlyFee
    {
        return MonthlyFee::query()->create([
            'designacao' => $designacao,
            'valor' => 30,
            'ativo' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

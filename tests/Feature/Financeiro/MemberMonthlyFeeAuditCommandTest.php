<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class MemberMonthlyFeeAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_covers_classifications_and_json_payload_contract(): void
    {
        $validPlanA = $this->createPlan('Plano Auditoria A');
        $validPlanB = $this->createPlan('Plano Auditoria B');

        $athleteType = $this->createUserType('atleta', 'Atleta');

        $canonicalOnly = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => null, 'ativo_desportivo' => true]);
        $canonicalOnly->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create(['user_id' => $canonicalOnly->id, 'mensalidade_id' => $validPlanA->id]);

        $matching = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => $validPlanA->id, 'ativo_desportivo' => true]);
        $matching->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create(['user_id' => $matching->id, 'mensalidade_id' => $validPlanA->id]);

        $legacyFallback = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => $validPlanA->id, 'ativo_desportivo' => true]);
        $legacyFallback->userTypes()->sync([$athleteType->id]);

        $divergent = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => $validPlanA->id, 'ativo_desportivo' => true]);
        $divergent->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create(['user_id' => $divergent->id, 'mensalidade_id' => $validPlanB->id]);

        $missingRequired = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => null, 'ativo_desportivo' => true]);
        $missingRequired->userTypes()->sync([$athleteType->id]);

        $notRequired = User::factory()->admin()->create(['estado' => 'ativo', 'tipo_mensalidade' => null, 'tipo_membro' => [], 'ativo_desportivo' => false]);
        $notRequired->userTypes()->sync([]);

        $invalidReference = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => 'missing-plan', 'ativo_desportivo' => true]);
        $invalidReference->userTypes()->sync([$athleteType->id]);

        $exitCode = Artisan::call('finance:audit-member-monthly-fees', ['--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('f2-member-monthly-fees-audit-v1', $payload['version'] ?? null);

        $summary = $payload['summary'] ?? [];
        $this->assertSame(7, (int) ($summary['total_users'] ?? 0));
        $this->assertSame(1, (int) ($summary['canonical_only_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['matching_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['legacy_fallback_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['divergent_count'] ?? 0));
        $this->assertSame(2, (int) ($summary['missing_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['missing_required_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['not_required_count'] ?? 0));
        $this->assertSame(1, (int) ($summary['invalid_reference_count'] ?? 0));

        $this->assertContains((string) $canonicalOnly->id, array_column($payload['classifications']['canonical_only'] ?? [], 'user_id'));
        $this->assertContains((string) $matching->id, array_column($payload['classifications']['matching'] ?? [], 'user_id'));
        $this->assertContains((string) $legacyFallback->id, array_column($payload['classifications']['legacy_fallback'] ?? [], 'user_id'));
        $this->assertContains((string) $divergent->id, array_column($payload['classifications']['divergent'] ?? [], 'user_id'));
        $this->assertContains((string) $missingRequired->id, array_column($payload['classifications']['missing_required'] ?? [], 'user_id'));
        $this->assertContains((string) $notRequired->id, array_column($payload['classifications']['not_required'] ?? [], 'user_id'));
        $this->assertContains((string) $invalidReference->id, array_column($payload['classifications']['invalid_reference'] ?? [], 'user_id'));

        $firstCase = $payload['cases'][0] ?? null;
        $this->assertIsArray($firstCase);
        $this->assertArrayHasKey('user_id', $firstCase);
        $this->assertArrayHasKey('classification', $firstCase);
        $this->assertArrayHasKey('canonical_monthly_fee_id', $firstCase);
        $this->assertArrayHasKey('legacy_monthly_fee_id', $firstCase);
        $this->assertArrayHasKey('resolved_monthly_fee_id', $firstCase);
        $this->assertArrayHasKey('uses_legacy_fallback', $firstCase);
        $this->assertArrayHasKey('has_divergence', $firstCase);
        $this->assertArrayHasKey('reference_valid', $firstCase);
        $this->assertArrayHasKey('reason', $firstCase);
        $this->assertArrayHasKey('eligibility', $firstCase);
        $this->assertArrayHasKey('reason_codes', $firstCase);
    }

    public function test_report_path_writes_payload_file(): void
    {
        $relativePath = 'storage/app/audits/member-monthly-fees-command-test-report.json';
        $absolutePath = base_path($relativePath);

        $this->createPlan('Plano Report');
        $athleteType = $this->createUserType('atleta', 'Atleta');
        $required = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => null, 'ativo_desportivo' => true]);
        $required->userTypes()->sync([$athleteType->id]);

        $exitCode = Artisan::call('finance:audit-member-monthly-fees', [
            '--json' => true,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);

        @unlink($absolutePath);
    }

    public function test_user_option_limits_scope(): void
    {
        $plan = $this->createPlan('Plano Scope');

        $athleteType = $this->createUserType('atleta', 'Atleta');

        $target = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => null, 'ativo_desportivo' => true]);
        $target->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create(['user_id' => $target->id, 'mensalidade_id' => $plan->id]);

        $extra = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => $plan->id, 'ativo_desportivo' => true]);
        $extra->userTypes()->sync([$athleteType->id]);

        $exitCode = Artisan::call('finance:audit-member-monthly-fees', [
            '--json' => true,
            '--user' => (string) $target->id,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, (int) ($payload['summary']['total_users'] ?? 0));
    }

    public function test_fail_on_divergence_returns_non_zero(): void
    {
        $planA = $this->createPlan('Plano Divergence A');
        $planB = $this->createPlan('Plano Divergence B');

        $athleteType = $this->createUserType('atleta', 'Atleta');

        $user = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => $planA->id, 'ativo_desportivo' => true]);
        $user->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create(['user_id' => $user->id, 'mensalidade_id' => $planB->id]);

        $exitCode = Artisan::call('finance:audit-member-monthly-fees', [
            '--fail-on-divergence' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_fail_on_fallback_returns_non_zero(): void
    {
        $plan = $this->createPlan('Plano Fallback');

        $athleteType = $this->createUserType('atleta', 'Atleta');
        $user = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => $plan->id, 'ativo_desportivo' => true]);
        $user->userTypes()->sync([$athleteType->id]);

        $exitCode = Artisan::call('finance:audit-member-monthly-fees', [
            '--fail-on-fallback' => true,
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

    private function createUserType(string $codigo, string $nome): UserType
    {
        return UserType::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'descricao' => $nome,
            'ativo' => true,
        ]);
    }
}

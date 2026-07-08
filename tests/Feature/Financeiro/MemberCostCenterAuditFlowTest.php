<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\DadosFinanceiros;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberCostCenterAuditFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_reports_canonical_and_missing_required_cases(): void
    {
        $plan = MonthlyFee::query()->create([
            'designacao' => 'Plano Centro Custo',
            'valor' => 30,
            'ativo' => true,
        ]);

        $athleteType = UserType::query()->create([
            'codigo' => 'atleta',
            'nome' => 'Atleta',
            'descricao' => 'Atleta',
            'ativo' => true,
        ]);

        $center = CostCenter::query()->create([
            'codigo' => 'CC-AUDIT-FC2',
            'nome' => 'Centro Auditoria',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $canonicalUser = User::factory()->create(['estado' => 'ativo', 'ativo_desportivo' => true]);
        $canonicalUser->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create(['user_id' => $canonicalUser->id, 'mensalidade_id' => $plan->id]);
        DB::table('centro_custo_user')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $canonicalUser->id,
            'centro_custo_id' => $center->id,
            'peso' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $missingRequiredUser = User::factory()->create(['estado' => 'ativo', 'ativo_desportivo' => true]);
        $missingRequiredUser->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create(['user_id' => $missingRequiredUser->id, 'mensalidade_id' => $plan->id]);

        $exitCode = Artisan::call('finance:audit-member-cost-centers', ['--json' => true]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(2, (int) ($payload['summary']['total_users_analyzed'] ?? 0));
        $this->assertSame(0, (int) ($payload['summary']['legacy_fallback_count'] ?? 0));
        $this->assertSame(0, (int) ($payload['summary']['divergent_count'] ?? 0));
        $this->assertSame(1, (int) ($payload['summary']['missing_required_count'] ?? 0));
    }

    public function test_fail_on_divergence_is_safe_after_fc2_cleanup(): void
    {
        $exitCode = Artisan::call('finance:audit-member-cost-centers', [
            '--fail-on-divergence' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_fail_on_fallback_is_safe_after_fc2_cleanup(): void
    {
        $exitCode = Artisan::call('finance:audit-member-cost-centers', [
            '--fail-on-fallback' => true,
        ]);

        $this->assertSame(0, $exitCode);
    }
}

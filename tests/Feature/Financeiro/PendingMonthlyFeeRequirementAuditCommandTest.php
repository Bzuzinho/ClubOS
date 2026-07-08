<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PendingMonthlyFeeRequirementAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_athlete_without_monthly_fee_is_genuinely_missing_monthly_fee(): void
    {
        $this->createUserType('atleta', 'Atleta');

        $user = User::factory()->athlete()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);
        $user->userTypes()->sync([$this->findUserTypeId('atleta')]);

        $payload = $this->runCommandJson(['--user' => (string) $user->id]);
        $case = $this->firstCase($payload);

        $this->assertSame('missing_required', $case['classification'] ?? null);
        $this->assertContains('active_sports_athlete', $case['reason_codes'] ?? []);
    }

    public function test_inactive_member_is_not_required(): void
    {
        $user = User::factory()->create([
            'estado' => 'inativo',
            'tipo_mensalidade' => null,
        ]);

        $payload = $this->runCommandJson(['--user' => (string) $user->id]);
        $case = $this->firstCase($payload);

        $this->assertSame('not_required', $case['classification'] ?? null);
        $this->assertContains('inactive_member', $case['reason_codes'] ?? []);
    }

    public function test_active_trainer_without_explicit_rule_is_not_required(): void
    {
        $trainerType = $this->createUserType('treinador', 'Treinador');

        $user = User::factory()->create([
            'estado' => 'ativo',
            'perfil' => 'treinador',
            'tipo_membro' => ['treinador'],
            'ativo_desportivo' => false,
            'tipo_mensalidade' => null,
        ]);
        $user->userTypes()->sync([$trainerType->id]);

        $payload = $this->runCommandJson(['--user' => (string) $user->id]);
        $case = $this->firstCase($payload);

        $this->assertSame('not_required', $case['classification'] ?? null);
        $this->assertContains('no_monthly_fee_eligible_member_type', $case['reason_codes'] ?? []);
    }

    public function test_missing_functional_data_is_not_required(): void
    {
        $user = User::factory()->create([
            'estado' => 'ativo',
            'perfil' => '',
            'tipo_membro' => [],
            'tipo_mensalidade' => null,
        ]);

        $payload = $this->runCommandJson(['--user' => (string) $user->id]);
        $case = $this->firstCase($payload);

        $this->assertSame('not_required', $case['classification'] ?? null);
        $this->assertContains('missing_operational_type', $case['reason_codes'] ?? []);
    }

    public function test_legacy_history_without_current_requirement_is_not_required(): void
    {
        $user = User::factory()->create([
            'estado' => 'inativo',
            'tipo_mensalidade' => null,
        ]);

        Invoice::query()->create([
            'user_id' => $user->id,
            'tipo' => 'mensalidade',
            'mes' => '2025-01',
            'data_fatura' => '2025-01-05',
            'data_emissao' => '2025-01-05',
            'data_vencimento' => '2025-01-15',
            'valor_total' => 30,
            'valor_pago' => 0,
            'valor_em_aberto' => 30,
            'estado_pagamento' => 'pendente',
        ]);

        $payload = $this->runCommandJson(['--user' => (string) $user->id]);
        $case = $this->firstCase($payload);

        $this->assertSame('not_required', $case['classification'] ?? null);
        $this->assertContains('not_required', $case['reason_codes'] ?? []);
    }

    public function test_user_with_resolved_monthly_fee_is_classified_as_resolved_present(): void
    {
        $plan = $this->createPlan('Plano Resolvido');
        $user = User::factory()->create(['estado' => 'ativo', 'tipo_mensalidade' => null]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $payload = $this->runCommandJson(['--user' => (string) $user->id]);
        $case = $this->firstCase($payload);

        $this->assertSame('resolved_monthly_fee_present', $case['classification'] ?? null);
        $this->assertContains('resolved_monthly_fee_present_outside_pending_scope', $case['reason_codes'] ?? []);
    }

    public function test_command_is_read_only(): void
    {
        $plan = $this->createPlan('Plano Read Only');
        $costCenter = $this->createCostCenter('CC-READONLY', 'Read Only');

        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);
        DB::table('centro_custo_user')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => (string) $user->id,
            'centro_custo_id' => (string) $costCenter->id,
            'peso' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $before = $this->tableSnapshot();

        $exitCode = Artisan::call('finance:audit-pending-monthly-fee-requirements', [
            '--json' => true,
            '--user' => (string) $user->id,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, $this->tableSnapshot());
    }

    public function test_json_payload_contract_is_valid(): void
    {
        $user = User::factory()->athlete()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);
        $user->userTypes()->sync([$this->createUserType('atleta', 'Atleta')->id]);

        $payload = $this->runCommandJson(['--user' => (string) $user->id]);

        $this->assertSame('f2-3-pending-monthly-fee-requirements-v1', $payload['version'] ?? null);
        $this->assertArrayHasKey('generated_at', $payload);
        $this->assertArrayHasKey('scope', $payload);
        $this->assertArrayHasKey('summary', $payload);
        $this->assertArrayHasKey('cases', $payload);

        $case = $this->firstCase($payload);
        $this->assertArrayHasKey('user_id', $case);
        $this->assertArrayHasKey('classification', $case);
        $this->assertArrayHasKey('reason_codes', $case);
        $this->assertArrayHasKey('operational_state', $case);
        $this->assertArrayHasKey('member_types', $case);
        $this->assertArrayHasKey('monthly_fee', $case);
        $this->assertArrayHasKey('cost_centers', $case);
        $this->assertArrayHasKey('eligibility', $case);
        $this->assertArrayHasKey('financial_history', $case);
    }

    public function test_report_path_writes_report_file(): void
    {
        $user = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);

        $relativePath = 'storage/app/audits/pending-monthly-fee-requirements-test.json';
        $absolutePath = base_path($relativePath);
        @unlink($absolutePath);

        $exitCode = Artisan::call('finance:audit-pending-monthly-fee-requirements', [
            '--json' => true,
            '--user' => (string) $user->id,
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($absolutePath);

        @unlink($absolutePath);
    }

    public function test_user_option_limits_scope(): void
    {
        $target = User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);

        User::factory()->create([
            'estado' => 'ativo',
            'tipo_mensalidade' => null,
        ]);

        $payload = $this->runCommandJson(['--user' => (string) $target->id]);
        $summary = $payload['summary'] ?? [];

        $this->assertSame(1, (int) ($summary['total_cases'] ?? 0));
        $this->assertSame((string) $target->id, (string) (($payload['cases'][0]['user_id'] ?? '')));
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function runCommandJson(array $options = []): array
    {
        $exitCode = Artisan::call('finance:audit-pending-monthly-fee-requirements', array_merge([
            '--json' => true,
        ], $options));

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function firstCase(array $payload): array
    {
        $case = $payload['cases'][0] ?? null;
        $this->assertIsArray($case);

        return $case;
    }

    /**
     * @return array<string,int>
     */
    private function tableSnapshot(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'dados_financeiros' => DB::table('dados_financeiros')->count(),
            'invoices' => DB::table('invoices')->count(),
            'invoice_items' => DB::table('invoice_items')->count(),
            'centro_custo_user' => DB::table('centro_custo_user')->count(),
        ];
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

    private function findUserTypeId(string $codigo): string
    {
        $userType = UserType::query()->where('codigo', $codigo)->firstOrFail();

        return (string) $userType->id;
    }

    private function createCostCenter(string $code, string $name): CostCenter
    {
        return CostCenter::query()->create([
            'codigo' => $code,
            'nome' => $name,
            'ativo' => true,
        ]);
    }
}

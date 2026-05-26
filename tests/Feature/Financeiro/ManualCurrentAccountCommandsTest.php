<?php

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ManualCurrentAccountCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_command_lists_members_with_non_zero_manual_current_account(): void
    {
        $member = User::factory()->create([
            'nome_completo' => 'Membro Com Legado',
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'conta_corrente_manual' => 42.50,
        ]);

        $this->assertSame(0, Artisan::call('finance:audit-manual-current-account'));

        $output = Artisan::output();

        $this->assertStringContainsString('Auditoria de conta_corrente_manual', $output);
        $this->assertStringContainsString($member->id, $output);
        $this->assertStringContainsString('Membro Com Legado', $output);
        $this->assertStringContainsString('42.50', $output);
    }

    public function test_audit_command_ignores_zero_manual_current_account_and_members_without_financial_data(): void
    {
        $zeroMember = User::factory()->create([
            'nome_completo' => 'Membro Zero',
        ]);

        User::factory()->create([
            'nome_completo' => 'Sem Dados Financeiros',
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $zeroMember->id,
            'conta_corrente_manual' => 0,
        ]);

        $this->artisan('finance:audit-manual-current-account')
            ->expectsOutputToContain('Nenhum membro com conta_corrente_manual diferente de zero.')
            ->doesntExpectOutputToContain('Membro Zero')
            ->doesntExpectOutputToContain('Sem Dados Financeiros')
            ->assertExitCode(0);
    }

    public function test_migration_dry_run_does_not_change_database(): void
    {
        $member = User::factory()->create([
            'nome_completo' => 'Membro Dry Run',
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'conta_corrente_manual' => 15,
        ]);

        $movementCountBefore = Movement::query()->count();

        $this->artisan('finance:migrate-manual-current-account')
            ->expectsOutputToContain('Dry-run concluido sem qualquer alteracao na base de dados.')
            ->assertExitCode(0);

        $this->assertSame($movementCountBefore, Movement::query()->count());
        $this->assertSame('15.00', DadosFinanceiros::query()->where('user_id', $member->id)->value('conta_corrente_manual'));
    }

    public function test_migration_dry_run_identifies_positive_and_negative_totals(): void
    {
        $positiveMember = User::factory()->create([
            'nome_completo' => 'Membro Positivo',
        ]);
        $negativeMember = User::factory()->create([
            'nome_completo' => 'Membro Negativo',
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $positiveMember->id,
            'conta_corrente_manual' => 30,
        ]);
        DadosFinanceiros::query()->create([
            'user_id' => $negativeMember->id,
            'conta_corrente_manual' => -12.25,
        ]);

        $this->artisan('finance:migrate-manual-current-account')
            ->expectsOutputToContain('30.00')
            ->expectsOutputToContain('-12.25')
            ->expectsOutputToContain('manual_decision_required_before_any_commit')
            ->assertExitCode(0);
    }
}
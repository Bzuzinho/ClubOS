<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\AthleteSportsData;
use App\Models\BankStatement;
use App\Models\ClubSetting;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\KeyValueStore;
use App\Models\MonthlyFee;
use App\Models\Movement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class FinancialGoLiveResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_removes_financial_facts_but_preserves_member_and_fee_configuration(): void
    {
        $member = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);

        AthleteSportsData::query()->create([
            'user_id' => $member->id,
        ]);

        $plan = MonthlyFee::query()->create([
            'designacao' => 'Mensalidade teste',
            'valor' => 37.50,
            'ativo' => true,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'mensalidade_id' => $plan->id,
            'discount_type' => 'percent',
            'discount_value' => 10,
            'discount_reason' => 'Irmãos',
            'conta_corrente_manual' => 22.50,
        ]);

        ClubSetting::query()->create([
            'nome_clube' => 'Clube Teste',
            'monthly_fee_generation_enabled' => true,
            'monthly_fee_auto_activate_due' => true,
        ]);

        Invoice::query()->create([
            'user_id' => $member->id,
            'data_fatura' => '2026-09-01',
            'mes' => '2026-09',
            'data_emissao' => '2026-09-01',
            'data_vencimento' => '2026-09-08',
            'valor_total' => 37.50,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee',
            'origem_id' => $plan->id,
        ]);

        Movement::query()->create([
            'user_id' => $member->id,
            'classificacao' => 'despesa',
            'data_emissao' => '2026-09-10',
            'data_vencimento' => '2026-09-10',
            'valor_total' => 15,
            'estado_pagamento' => 'pendente',
            'tipo' => 'manual',
        ]);

        BankStatement::query()->create([
            'conta' => 'PT50000000000000000000000',
            'data_movimento' => '2026-09-12',
            'descricao' => 'Transferência teste',
            'valor' => 37.50,
            'saldo' => 37.50,
            'conciliado' => false,
        ]);

        KeyValueStore::setValue('club-movimentos', [['legacy' => true]]);
        KeyValueStore::setValue('club-movimento-itens', [['legacy' => true]]);
        KeyValueStore::setValue('unrelated-key', ['keep' => true]);

        putenv('CLUBOS_FINANCIAL_RESET_20260928=APPLY');
        $_ENV['CLUBOS_FINANCIAL_RESET_20260928'] = 'APPLY';
        $_SERVER['CLUBOS_FINANCIAL_RESET_20260928'] = 'APPLY';

        try {
            $migration = require database_path('migrations/2026_09_28_130000_reset_financial_operational_data_for_go_live.php');
            $migration->up();
        } finally {
            putenv('CLUBOS_FINANCIAL_RESET_20260928');
            unset($_ENV['CLUBOS_FINANCIAL_RESET_20260928'], $_SERVER['CLUBOS_FINANCIAL_RESET_20260928']);
        }

        $this->assertDatabaseHas('users', ['id' => $member->id]);
        $this->assertDatabaseHas('athlete_sports_data', ['user_id' => $member->id]);
        $this->assertDatabaseHas('monthly_fees', ['id' => $plan->id]);

        $profile = DadosFinanceiros::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame($plan->id, $profile->mensalidade_id);
        $this->assertSame('percent', $profile->discount_type);
        $this->assertSame('10.00', (string) $profile->discount_value);
        $this->assertSame('Irmãos', $profile->discount_reason);
        $this->assertSame('0.00', (string) $profile->conta_corrente_manual);

        $settings = ClubSetting::query()->firstOrFail();
        $this->assertFalse((bool) $settings->monthly_fee_generation_enabled);
        $this->assertFalse((bool) $settings->monthly_fee_auto_activate_due);

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('movements', 0);
        $this->assertDatabaseCount('bank_statements', 0);
        $this->assertDatabaseMissing('key_value_store', ['key' => 'club-movimentos']);
        $this->assertDatabaseMissing('key_value_store', ['key' => 'club-movimento-itens']);
        $this->assertDatabaseHas('key_value_store', ['key' => 'unrelated-key']);

        $this->assertSame(0, Artisan::call('finance:audit-go-live-reset', [
            '--json' => true,
            '--fail-on-data' => true,
        ]));
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['summary']['ready']);
        $this->assertSame(0, $payload['summary']['transaction_row_count']);
        $this->assertSame(0, $payload['summary']['linked_reference_count']);
        $this->assertSame(0, $payload['summary']['monthly_fee_generation_enabled_count']);
        $this->assertSame(0, $payload['summary']['monthly_fee_auto_activate_enabled_count']);

        Invoice::query()->create([
            'user_id' => $member->id,
            'data_fatura' => '2026-10-01',
            'mes' => '2026-10',
            'data_emissao' => '2026-10-01',
            'data_vencimento' => '2026-10-08',
            'valor_total' => 37.50,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee',
            'origem_id' => $plan->id,
        ]);

        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_reset_does_not_apply_without_explicit_confirmation_token(): void
    {
        $member = User::factory()->create();

        Invoice::query()->create([
            'user_id' => $member->id,
            'data_fatura' => '2026-09-01',
            'mes' => '2026-09',
            'data_emissao' => '2026-09-01',
            'data_vencimento' => '2026-09-08',
            'valor_total' => 25,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        putenv('CLUBOS_FINANCIAL_RESET_20260928');
        unset($_ENV['CLUBOS_FINANCIAL_RESET_20260928'], $_SERVER['CLUBOS_FINANCIAL_RESET_20260928']);

        $migration = require database_path('migrations/2026_09_28_130000_reset_financial_operational_data_for_go_live.php');
        $migration->up();

        $this->assertDatabaseCount('invoices', 1);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_deploy_requires_backup_before_reset_and_audit_after_migration(): void
    {
        $script = file_get_contents(base_path('bin/remote-deploy-backend.sh'));

        $this->assertIsString($script);

        $backupPosition = strpos($script, 'backup-local-postgres.sh');
        $offsitePosition = strpos($script, 'backup-offsite.sh');
        $migratePosition = strpos($script, 'artisan" migrate --force');
        $auditPosition = strpos($script, 'finance:audit-go-live-reset --fail-on-data');

        $this->assertNotFalse($backupPosition);
        $this->assertNotFalse($offsitePosition);
        $this->assertNotFalse($migratePosition);
        $this->assertNotFalse($auditPosition);
        $this->assertLessThan($migratePosition, $backupPosition);
        $this->assertLessThan($migratePosition, $offsitePosition);
        $this->assertGreaterThan($migratePosition, $auditPosition);
    }
}

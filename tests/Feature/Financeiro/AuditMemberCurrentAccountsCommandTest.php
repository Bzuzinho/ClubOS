<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AuditMemberCurrentAccountsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_outputs_expected_summary_and_case_contract_in_json(): void
    {
        $canonicalOnly = User::factory()->create();
        DadosFinanceiros::query()->create([
            'user_id' => $canonicalOnly->id,
            'conta_corrente_manual' => 12.50,
        ]);

        $matching = User::factory()->create();
        DadosFinanceiros::query()->create([
            'user_id' => $matching->id,
            'conta_corrente_manual' => 9,
        ]);

        $none = User::factory()->create();

        $debtUser = User::factory()->create();
        Invoice::query()->create([
            'user_id' => $debtUser->id,
            'mes' => 'Mensalidade Auditoria',
            'data_fatura' => now()->subDay()->toDateString(),
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 20,
            'valor_pago' => 0,
            'valor_em_aberto' => 20,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        $exitCode = Artisan::call('finance:audit-member-current-accounts', [
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('f3-member-current-accounts-audit-v1', $payload['version'] ?? null);

        $summary = $payload['summary'] ?? [];
        $this->assertSame(4, (int) ($summary['total_users'] ?? 0));
        $this->assertSame(2, (int) ($summary['canonical_manual_only_count'] ?? 0));
        $this->assertSame(0, (int) ($summary['matching_manual_count'] ?? 0));
        $this->assertSame(0, (int) ($summary['legacy_fallback_count'] ?? 0));
        $this->assertSame(0, (int) ($summary['divergent_manual_count'] ?? 0));
        $this->assertSame(2, (int) ($summary['no_manual_adjustment_count'] ?? 0));

        $case = $payload['cases'][0] ?? null;
        $this->assertIsArray($case);
        $this->assertArrayHasKey('user_id', $case);
        $this->assertArrayHasKey('manual_source_classification', $case);
        $this->assertArrayHasKey('operational_balance_classification', $case);
        $this->assertArrayHasKey('canonical_manual_balance', $case);
        $this->assertArrayHasKey('legacy_account_balance', $case);
        $this->assertArrayHasKey('resolved_manual_balance', $case);
        $this->assertArrayHasKey('gross_debt', $case);
        $this->assertArrayHasKey('available_credit', $case);
        $this->assertArrayHasKey('net_debt', $case);
        $this->assertArrayHasKey('uses_legacy_fallback', $case);
        $this->assertArrayHasKey('has_divergence', $case);
        $this->assertArrayHasKey('reason_codes', $case);

        $cases = collect($payload['cases'] ?? []);
        $this->assertContains((string) $canonicalOnly->id, $cases->where('manual_source_classification', 'canonical_only')->pluck('user_id')->all());
        $this->assertContains((string) $debtUser->id, $cases->where('operational_balance_classification', 'debt')->pluck('user_id')->all());
        $this->assertContains((string) $none->id, $cases->where('operational_balance_classification', 'balanced')->pluck('user_id')->all());
    }

    public function test_fail_flags_and_scope_and_report_path_behaviour(): void
    {
        $user = User::factory()->create();

        $exitFallback = Artisan::call('finance:audit-member-current-accounts', [
            '--fail-on-fallback' => true,
        ]);
        $this->assertSame(0, $exitFallback);

        $exitDivergence = Artisan::call('finance:audit-member-current-accounts', [
            '--fail-on-divergence' => true,
        ]);
        $this->assertSame(0, $exitDivergence);

        $relativePath = 'storage/app/audits/member-current-accounts-command-test.json';
        $absolutePath = base_path($relativePath);

        $exitReport = Artisan::call('finance:audit-member-current-accounts', [
            '--report-path' => $relativePath,
        ]);

        $this->assertSame(0, $exitReport);
        $this->assertFileExists($absolutePath);

        $exitScoped = Artisan::call('finance:audit-member-current-accounts', [
            '--json' => true,
            '--user' => (string) $user->id,
        ]);

        $this->assertSame(0, $exitScoped);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame(1, (int) ($payload['summary']['total_users'] ?? 0));

        @unlink($absolutePath);
    }
}

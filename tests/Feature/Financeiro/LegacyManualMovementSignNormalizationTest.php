<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Financeiro\FinanceDashboardService;
use App\Services\Financeiro\FinanceReportService;
use App\Services\Financeiro\FinancialReportingFactService;
use App\Services\Financeiro\NormalizeLegacyManualMovementSignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LegacyManualMovementSignNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private const TARGET_MOVEMENT_ID = 'a1c55e47-bf5f-48b4-a115-e1655dbc7fb2';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
    }

    public function test_dry_run_reports_proposed_change_and_keeps_database_unchanged(): void
    {
        [$movement] = $this->seedKnownLifecycle();

        $beforeValue = (float) $movement->valor_total;

        $payload = app(NormalizeLegacyManualMovementSignService::class)->normalize(self::TARGET_MOVEMENT_ID, true);

        $this->assertTrue((bool) ($payload['guards_passed'] ?? false), json_encode($payload, JSON_PRETTY_PRINT));
        $this->assertSame(-1537.5, (float) ($payload['before']['movement']['valor_total'] ?? 0));
        $this->assertSame(1537.5, (float) ($payload['proposed_after']['movement']['valor_total'] ?? 0));

        $movement->refresh();
        $this->assertSame($beforeValue, (float) $movement->valor_total);
        $this->assertSame(0.0, (float) ($payload['financial_impact']['reporting_revenue_delta'] ?? 999));
        $this->assertSame(0.0, (float) ($payload['financial_impact']['reporting_expense_delta'] ?? 999));
        $this->assertSame(0.0, (float) ($payload['financial_impact']['reporting_balance_delta'] ?? 999));
        $this->assertSame(0.0, (float) ($payload['financial_impact']['current_account_delta'] ?? 999));
    }

    public function test_normalization_updates_only_legacy_negative_sign_and_preserves_relationships(): void
    {
        [$movement, $item, $entry, $payment, $allocation] = $this->seedKnownLifecycle();

        $beforeFacts = app(FinancialReportingFactService::class)->paidFacts();
        $beforeMovementFacts = $beforeFacts
            ->filter(fn (array $fact): bool => ($fact['source_kind'] ?? null) === 'financial_entry' && ($fact['source_id'] ?? null) === (string) $entry->id)
            ->values();
        $beforeReportSummary = app(FinanceReportService::class)->summary();
        $beforeDashboard = app(FinanceDashboardService::class)->build();
        $beforeCurrentAccount = app(CurrentAccountService::class)->summarize(['user_id' => (string) $movement->user_id]);

        $auditBeforeExit = Artisan::call('finance:audit-integrations', [
            '--json' => true,
            '--module' => 'reporting',
        ]);
        $this->assertSame(0, $auditBeforeExit);
        $auditBeforePayload = json_decode(trim(Artisan::output()), true);
        $auditBeforeFindings = collect($auditBeforePayload['findings'] ?? []);
        $this->assertTrue($auditBeforeFindings->contains(
            fn (array $finding): bool => $finding['code'] === 'negative_expense_movement_value' && $finding['source_id'] === (string) $movement->id
        ));

        $exitCode = Artisan::call('finance:normalize-legacy-manual-movement-sign', [
            'movement_id' => self::TARGET_MOVEMENT_ID,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
        $payload = json_decode(trim(Artisan::output()), true);

        $movement->refresh();
        $item->refresh();
        $entry->refresh();
        $payment->refresh();
        $allocation->refresh();

        $this->assertSame(self::TARGET_MOVEMENT_ID, (string) $movement->id);
        $this->assertSame(1537.5, (float) $movement->valor_total);
        $this->assertSame(1537.5, (float) $item->total_linha);
        $this->assertSame(1250.0, (float) $item->valor_unitario);
        $this->assertSame(1537.5, (float) $entry->valor);
        $this->assertSame(1537.5, (float) ($entry->valor_pago ?? 0));
        $this->assertSame('despesa', $movement->classificacao);
        $this->assertSame('pago', $movement->estado_pagamento);
        $this->assertSame('nao_conciliado', $movement->estado_conciliacao);

        $this->assertSame('confirmed', $payment->status);
        $this->assertSame(1537.5, (float) $payment->amount);
        $this->assertSame((string) $allocation->id, (string) data_get($payload, 'payment_allocations.0.id', (string) $allocation->id));
        $this->assertSame((string) $payment->id, (string) $allocation->payment_id);
        $this->assertSame((string) $entry->id, (string) $allocation->financial_entry_id);
        $this->assertSame('confirmed', $allocation->status);
        $this->assertSame(1537.5, (float) $allocation->amount);

        $afterFacts = app(FinancialReportingFactService::class)->paidFacts();
        $afterMovementFacts = $afterFacts
            ->filter(fn (array $fact): bool => ($fact['source_kind'] ?? null) === 'financial_entry' && ($fact['source_id'] ?? null) === (string) $entry->id)
            ->values();

        $this->assertCount(1, $beforeMovementFacts);
        $this->assertSame(1537.5, (float) ($beforeMovementFacts->first()['amount'] ?? 0));
        $this->assertSame('despesa', (string) ($beforeMovementFacts->first()['type'] ?? ''));
        $this->assertCount(1, $afterMovementFacts);
        $this->assertSame(1537.5, (float) ($afterMovementFacts->first()['amount'] ?? 0));
        $this->assertSame('despesa', (string) ($afterMovementFacts->first()['type'] ?? ''));

        $afterReportSummary = app(FinanceReportService::class)->summary();
        $afterDashboard = app(FinanceDashboardService::class)->build();
        $afterCurrentAccount = app(CurrentAccountService::class)->summarize(['user_id' => (string) $movement->user_id]);

        $this->assertSame((float) ($beforeReportSummary['totalReceitas'] ?? 0), (float) ($afterReportSummary['totalReceitas'] ?? 999));
        $this->assertSame((float) ($beforeReportSummary['totalDespesas'] ?? 0), (float) ($afterReportSummary['totalDespesas'] ?? 999));
        $this->assertSame((float) ($beforeReportSummary['saldoAtual'] ?? 0), (float) ($afterReportSummary['saldoAtual'] ?? 999));
        $this->assertSame((float) ($beforeDashboard['total_geral'] ?? 0), (float) ($afterDashboard['total_geral'] ?? 999));
        $this->assertSame((float) ($beforeDashboard['despesas_mes'] ?? 0), (float) ($afterDashboard['despesas_mes'] ?? 999));
        $this->assertSame((float) ($beforeCurrentAccount['net_debt'] ?? 0), (float) ($afterCurrentAccount['net_debt'] ?? 999));

        $auditAfterExit = Artisan::call('finance:audit-integrations', [
            '--json' => true,
            '--module' => 'reporting',
        ]);
        $this->assertSame(0, $auditAfterExit);
        $auditAfterPayload = json_decode(trim(Artisan::output()), true);
        $auditAfterFindings = collect($auditAfterPayload['findings'] ?? []);
        $this->assertFalse($auditAfterFindings->contains(
            fn (array $finding): bool => $finding['code'] === 'negative_expense_movement_value' && $finding['source_id'] === (string) $movement->id
        ));
    }

    public function test_command_rejects_non_target_or_non_manual_or_non_expense_or_already_positive_or_fiscal_issued(): void
    {
        [$movement, , $entry] = $this->seedKnownLifecycle();

        $otherMovement = Movement::query()->forceCreate([
            'id' => (string) Str::uuid(),
            'user_id' => $movement->user_id,
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => -100.00,
            'estado_pagamento' => 'pago',
            'estado_conciliacao' => 'nao_conciliado',
            'centro_custo_id' => $movement->centro_custo_id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
        ]);

        $this->assertSame(1, Artisan::call('finance:normalize-legacy-manual-movement-sign', [
            'movement_id' => (string) $otherMovement->id,
        ]));

        $movement->origem_tipo = 'stock';
        $movement->save();
        $this->assertSame(1, Artisan::call('finance:normalize-legacy-manual-movement-sign', [
            'movement_id' => self::TARGET_MOVEMENT_ID,
        ]));

        $movement->origem_tipo = 'manual';
        $movement->classificacao = 'receita';
        $movement->save();
        $this->assertSame(1, Artisan::call('finance:normalize-legacy-manual-movement-sign', [
            'movement_id' => self::TARGET_MOVEMENT_ID,
        ]));

        $movement->classificacao = 'despesa';
        $movement->valor_total = 1537.5;
        $movement->save();
        $this->assertSame(1, Artisan::call('finance:normalize-legacy-manual-movement-sign', [
            'movement_id' => self::TARGET_MOVEMENT_ID,
        ]));

        $movement->valor_total = -1537.5;
        $movement->save();

        FiscalDocumentRequest::query()->create([
            'financial_entry_id' => $entry->id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 1537.50,
        ]);

        $this->assertSame(1, Artisan::call('finance:normalize-legacy-manual-movement-sign', [
            'movement_id' => self::TARGET_MOVEMENT_ID,
        ]));
    }

    /**
     * @return array{Movement, MovementItem, FinancialEntry, Payment, PaymentAllocation}
     */
    private function seedKnownLifecycle(): array
    {
        $user = \App\Models\User::factory()->create();
        $costCenter = CostCenter::query()->first() ?: CostCenter::query()->create([
            'codigo' => 'CC-XFIN10-01',
            'nome' => 'Centro Custo XFIN10',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $movement = Movement::query()->forceCreate([
            'id' => self::TARGET_MOVEMENT_ID,
            'user_id' => $user->id,
            'classificacao' => 'despesa',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-13',
            'valor_total' => -1537.50,
            'estado_pagamento' => 'pago',
            'estado_conciliacao' => 'nao_conciliado',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'origem_id' => null,
        ]);

        $item = MovementItem::query()->create([
            'movimento_id' => $movement->id,
            'descricao' => 'Linha despesa legacy',
            'valor_unitario' => 1250.00,
            'quantidade' => 1,
            'total_linha' => 1537.50,
            'centro_custo_id' => $costCenter->id,
        ]);

        $payment = Payment::query()->create([
            'user_id' => $user->id,
            'amount' => 1537.50,
            'allocated_amount' => 1537.50,
            'unallocated_amount' => 0,
            'payment_date' => '2026-05-13',
            'method' => 'transferencia',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        $entry = FinancialEntry::query()->create([
            'data' => '2026-05-13',
            'tipo' => 'despesa',
            'categoria' => 'Servicos',
            'descricao' => 'Entry canonica movement',
            'valor' => 1537.50,
            'valor_pago' => 1537.50,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => '2026-05-13',
            'centro_custo_id' => $costCenter->id,
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'origem_tipo' => 'movement',
            'origem_modulo' => 'financeiro',
            'origem_id' => $movement->id,
        ]);

        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'financial_entry_id' => $entry->id,
            'amount' => 1537.50,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        return [$movement, $item, $entry, $payment, $allocation];
    }
}

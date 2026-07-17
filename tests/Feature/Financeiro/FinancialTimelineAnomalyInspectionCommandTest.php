<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\FinancialEntry;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class FinancialTimelineAnomalyInspectionCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_inspection_by_payment_finds_bank_payment_allocation_and_financial_entry(): void
    {
        [$bank, $payment, $allocation, $entry] = $this->invoiceLessTimeline();

        $payload = $this->jsonPayload(['--payment' => $payment->id]);

        $this->assertSame((string) $bank->id, $payload['bank_transaction_snapshot']['id']);
        $this->assertSame((string) $payment->id, $payload['payment_snapshot']['id']);
        $this->assertSame((string) $allocation->id, $payload['payment_allocation_snapshot']['id']);
        $this->assertSame((string) $entry->id, $payload['financial_entry_snapshot']['id']);
        $this->assertSame(['invoice_id' => null], $payload['invoice_snapshot']);
    }

    public function test_inspection_by_allocation_financial_entry_and_bank_transaction_returns_same_entity_links(): void
    {
        [$bank, $payment, $allocation, $entry] = $this->invoiceLessTimeline();

        $byAllocation = $this->jsonPayload(['--allocation' => $allocation->id]);
        $byEntry = $this->jsonPayload(['--financial-entry' => $entry->id]);
        $byBank = $this->jsonPayload(['--bank-transaction' => $bank->id]);

        foreach ([$byAllocation, $byEntry, $byBank] as $payload) {
            $this->assertSame((string) $bank->id, $payload['bank_transaction_snapshot']['id']);
            $this->assertSame((string) $payment->id, $payload['payment_snapshot']['id']);
            $this->assertSame((string) $allocation->id, $payload['payment_allocation_snapshot']['id']);
            $this->assertSame((string) $entry->id, $payload['financial_entry_snapshot']['id']);
        }
    }

    public function test_report_identifies_temporal_anomalies_missing_invoice_and_risk(): void
    {
        [, , $allocation] = $this->invoiceLessTimeline();

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertContains('financial_entry_date_before_bank_date', $payload['anomalies']);
        $this->assertContains('financial_entry_date_before_payment_date', $payload['anomalies']);
        $this->assertContains('financial_entry_date_before_allocation_date', $payload['anomalies']);
        $this->assertContains('missing_invoice_for_allocation', $payload['anomalies']);
        $this->assertContains('payment_without_invoice_allocation', $payload['anomalies']);
        $this->assertSame('medium', $payload['risk_level']);
        $this->assertFalse($payload['can_auto_classify_as_info']);
        $this->assertSame('keep_warning_pending_manual_review', $payload['recommended_next_action']);
    }

    public function test_non_invoice_movement_with_coherent_amounts_can_be_classified_as_info_candidate(): void
    {
        [, , $allocation] = $this->invoiceLessTimeline([
            'origem_tipo' => 'manual_movement',
            'origem_modulo' => 'tesouraria',
            'categoria' => 'Movimento de tesouraria',
            'descricao' => 'Receita avulsa de periodo economico',
        ]);

        $payload = $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertContains('movement_outside_invoice_domain', $payload['anomalies']);
        $this->assertSame('low', $payload['risk_level']);
        $this->assertTrue($payload['can_auto_classify_as_info']);
        $this->assertSame('classify_as_economic_date_if_source_is_non_invoice_movement', $payload['recommended_next_action']);
    }

    public function test_json_output_and_report_path_are_valid(): void
    {
        [, , $allocation] = $this->invoiceLessTimeline();
        $reportPath = 'storage/app/testing/financial-timeline-anomaly.json';
        File::delete(base_path($reportPath));

        $payload = $this->jsonPayload(['--allocation' => $allocation->id, '--report-path' => $reportPath]);

        $this->assertFileExists(base_path($reportPath));
        $reportPayload = json_decode((string) File::get(base_path($reportPath)), true);
        $this->assertIsArray($reportPayload);
        $this->assertSame($payload['payment_allocation_snapshot']['id'], $reportPayload['payment_allocation_snapshot']['id']);
    }

    public function test_fail_on_actionable_returns_exit_one_when_manual_review_is_required(): void
    {
        [, , $allocation] = $this->invoiceLessTimeline();

        $exitCode = Artisan::call('finance:inspect-financial-timeline-anomaly', [
            '--allocation' => $allocation->id,
            '--fail-on-actionable' => true,
        ]);

        $this->assertSame(1, $exitCode);
    }

    public function test_inspection_is_read_only(): void
    {
        [, , $allocation] = $this->invoiceLessTimeline();
        $before = $this->snapshot();

        $this->jsonPayload(['--allocation' => $allocation->id]);

        $this->assertSame($before, $this->snapshot());
    }

    /**
     * @param array<string,mixed> $entryOverrides
     * @return array{0:BankStatement,1:Payment,2:PaymentAllocation,3:FinancialEntry}
     */
    private function invoiceLessTimeline(array $entryOverrides = []): array
    {
        $bank = BankStatement::query()->create([
            'data_movimento' => '2026-05-08',
            'descricao' => 'Transferencia bancaria sem fatura',
            'referencia' => 'TRF-NOINVOICE',
            'valor' => 20,
            'saldo' => 20,
            'conciliado' => true,
            'valor_conciliado' => 20,
            'valor_por_conciliar' => 0,
            'conciliacao_status' => 'reconciled',
        ]);
        $payment = Payment::query()->create([
            'bank_statement_id' => $bank->id,
            'amount' => 20,
            'allocated_amount' => 20,
            'unallocated_amount' => 0,
            'payment_date' => '2026-05-13',
            'method' => 'transferencia',
            'source' => Payment::SOURCE_BANK_STATEMENT,
            'status' => Payment::STATUS_CONFIRMED,
        ]);
        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => null,
            'amount' => 20,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => '2026-05-13 12:00:00',
        ]);
        $allocation->forceFill([
            'created_at' => '2026-05-13 12:00:00',
            'updated_at' => '2026-05-13 12:00:00',
        ])->save();
        $entry = FinancialEntry::query()->create(array_merge([
            'data' => '2026-05-01',
            'tipo' => 'receita',
            'categoria' => 'Pagamento sem fatura',
            'descricao' => 'Lancamento sem fatura para inspecao',
            'valor' => 20,
            'valor_pago' => 20,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'payment_id' => $payment->id,
            'bank_statement_id' => $bank->id,
            'fatura_id' => null,
            'origem_tipo' => 'payment_allocation',
            'origem_id' => $allocation->id,
        ], $entryOverrides));
        $entry->forceFill([
            'created_at' => '2026-05-13 12:00:00',
            'updated_at' => '2026-05-13 12:00:00',
        ])->save();
        $allocation->forceFill(['financial_entry_id' => $entry->id])->save();
        MapaConciliacao::query()->create([
            'extrato_id' => $bank->id,
            'lancamento_id' => $entry->id,
            'payment_id' => $payment->id,
            'payment_allocation_id' => $allocation->id,
            'valor_conciliado' => 20,
            'status' => 'confirmado',
            'regra_usada' => 'test',
        ]);

        return [$bank->fresh(), $payment->fresh(), $allocation->fresh(), $entry->fresh()];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function jsonPayload(array $options): array
    {
        $outputBuffer = new BufferedOutput();
        $exitCode = Artisan::call('finance:inspect-financial-timeline-anomaly', array_merge([
            '--json' => true,
        ], $options), $outputBuffer);

        $output = trim($outputBuffer->fetch());
        $this->assertSame(0, $exitCode, $output);
        $jsonStart = strpos($output, '{');
        $this->assertNotFalse($jsonStart, $output);
        $payload = json_decode(substr($output, $jsonStart), true);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return array<string,int>
     */
    private function snapshot(): array
    {
        return [
            'bank_statements' => BankStatement::query()->count(),
            'payments' => Payment::withTrashed()->count(),
            'payment_allocations' => PaymentAllocation::withTrashed()->count(),
            'financial_entries' => FinancialEntry::query()->count(),
            'mapa_conciliacao' => MapaConciliacao::query()->count(),
            'bank_transaction_allocations' => BankTransactionAllocation::query()->count(),
        ];
    }
}

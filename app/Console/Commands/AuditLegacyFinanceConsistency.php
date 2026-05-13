<?php

namespace App\Console\Commands;

use App\Services\Financeiro\LegacyConsistencyService;
use Illuminate\Console\Command;

class AuditLegacyFinanceConsistency extends Command
{
    protected $signature = 'financeiro:audit-legacy-consistency';

    protected $description = 'Audita incoerencias legacy entre invoices, payment_allocations, payments e fiscal_document_requests';

    public function handle(LegacyConsistencyService $service): int
    {
        $report = $service->audit();

        $this->info('Auditoria de consistencia legacy do financeiro');
        $this->newLine();

        $this->renderSummary($report['summary']);
        $this->renderInvoiceMismatchSection($report['invoice_state_mismatches']);
        $this->renderPaidInvoicesWithoutFiscalRequestSection($report['paid_invoices_without_active_fiscal_request']);
        $this->renderSoftDeletedFiscalRequestsSection($report['soft_deleted_fiscal_requests_with_confirmed_allocations']);
        $this->renderOrphanReconciliationPaymentsSection($report['orphan_reconciliation_payments']);
        $this->renderOrphanManualPaymentsSection($report['orphan_manual_payments']);
        $this->renderAllocatedAmountMismatchSection($report['payments_with_allocated_amount_mismatch']);

        return self::SUCCESS;
    }

    private function renderSummary(array $summary): void
    {
        $this->table(
            ['Categoria', 'Total'],
            [
                ['Invoices com allocations confirmadas e estado/valores incoerentes', $summary['invoice_state_mismatches']],
                ['Invoices pagas por allocation sem fiscal request ativo', $summary['paid_invoices_without_active_fiscal_request']],
                ['Fiscal requests soft deleted com allocations confirmadas', $summary['soft_deleted_fiscal_requests_with_confirmed_allocations']],
                ['Payments de reconciliacao orfaos', $summary['orphan_reconciliation_payments']],
                ['Payments manuais orfaos', $summary['orphan_manual_payments']],
                ['Payments com allocated_amount/unallocated_amount incoerente', $summary['payments_with_allocated_amount_mismatch']],
            ]
        );
    }

    private function renderInvoiceMismatchSection(array $rows): void
    {
        $this->newLine();
        $this->line('Invoices com payment_allocations confirmadas mas estado/valores incoerentes');

        if ($rows === []) {
            $this->line('  Nenhuma incoerencia encontrada.');

            return;
        }

        $this->table([
            'Invoice', 'Atual', 'Esperado', 'Pago atual', 'Pago esperado', 'Aberto atual', 'Aberto esperado', 'Allocs',
        ], array_map(fn (array $row) => [
            $row['invoice_id'],
            $row['current_status'],
            $row['expected_status'],
            number_format($row['current_paid_amount'], 2, '.', ''),
            number_format($row['expected_paid_amount'], 2, '.', ''),
            number_format($row['current_outstanding_amount'], 2, '.', ''),
            number_format($row['expected_outstanding_amount'], 2, '.', ''),
            $row['allocation_count'],
        ], $rows));
    }

    private function renderPaidInvoicesWithoutFiscalRequestSection(array $rows): void
    {
        $this->newLine();
        $this->line('Invoices pagas por allocation mas sem fiscal request ativo');

        if ($rows === []) {
            $this->line('  Nenhuma invoice nesta situacao.');

            return;
        }

        $this->table([
            'Invoice', 'Pago', 'Data pagamento', 'Ultimo payment', 'Soft deleted',
        ], array_map(fn (array $row) => [
            $row['invoice_id'],
            number_format($row['paid_amount'], 2, '.', ''),
            $row['payment_date'],
            $row['latest_payment_id'],
            $row['soft_deleted_fiscal_requests_count'],
        ], $rows));
    }

    private function renderSoftDeletedFiscalRequestsSection(array $rows): void
    {
        $this->newLine();
        $this->line('Fiscal_document_requests soft deleted ligadas a invoices com payment_allocations confirmadas');

        if ($rows === []) {
            $this->line('  Nenhum pedido fiscal soft deleted relevante.');

            return;
        }

        $this->table([
            'Invoice', 'Estado esperado', 'Allocs', 'Ativo', 'Soft deleted',
        ], array_map(fn (array $row) => [
            $row['invoice_id'],
            $row['expected_status'],
            $row['allocation_count'],
            $row['active_fiscal_request_id'],
            $row['soft_deleted_fiscal_requests_count'],
        ], $rows));
    }

    private function renderOrphanReconciliationPaymentsSection(array $rows): void
    {
        $this->newLine();
        $this->line('Payments de reconciliacao orfaos');

        if ($rows === []) {
            $this->line('  Nenhum payment nesta situacao.');

            return;
        }

        $this->table([
            'Payment', 'Source', 'Amount', 'Allocated', 'Unallocated', 'BankStatement', 'Status extrato',
        ], array_map(fn (array $row) => [
            $row['payment_id'],
            $row['source'],
            number_format($row['amount'], 2, '.', ''),
            number_format($row['allocated_amount'], 2, '.', ''),
            number_format($row['unallocated_amount'], 2, '.', ''),
            $row['bank_statement_id'],
            $row['bank_statement_status'] ?? ($row['bank_statement_conciliado'] ? 'reconciled' : 'unreconciled'),
        ], $rows));
    }

    private function renderOrphanManualPaymentsSection(array $rows): void
    {
        $this->newLine();
        $this->line('Payments manuais orfaos');

        if ($rows === []) {
            $this->line('  Nenhum payment nesta situacao.');

            return;
        }

        $this->table([
            'Payment', 'Amount', 'Allocated', 'Unallocated', 'Data', 'Referencia',
        ], array_map(fn (array $row) => [
            $row['payment_id'],
            number_format($row['amount'], 2, '.', ''),
            number_format($row['allocated_amount'], 2, '.', ''),
            number_format($row['unallocated_amount'], 2, '.', ''),
            $row['payment_date'],
            $row['reference'],
        ], $rows));
    }

    private function renderAllocatedAmountMismatchSection(array $rows): void
    {
        $this->newLine();
        $this->line('Payments com allocated_amount/unallocated_amount incoerente');

        if ($rows === []) {
            $this->line('  Nenhum payment nesta situacao.');

            return;
        }

        $this->table([
            'Payment', 'Source', 'Allocated atual', 'Allocated esperado', 'Unallocated atual', 'Unallocated esperado',
        ], array_map(fn (array $row) => [
            $row['payment_id'],
            $row['source'],
            number_format($row['allocated_amount'], 2, '.', ''),
            number_format($row['expected_allocated_amount'], 2, '.', ''),
            number_format($row['unallocated_amount'], 2, '.', ''),
            number_format($row['expected_unallocated_amount'], 2, '.', ''),
        ], $rows));
    }
}
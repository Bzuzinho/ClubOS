<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Support\Collection;

final class LegacySaleAuditService
{
    public const VERSION = 'xfin8-legacy-sale-audit-v1';

    /**
     * Audit legacy Sale model for operational writes, parallel financial effects, and data integrity.
     *
     * @return array<string,mixed>
     */
    public function audit(): array
    {
        $findings = [];

        // Detect parallel Invoice + FinancialEntry
        $findings = array_merge($findings, $this->auditParallelFinancialRecords());

        // Detect orphan Invoices/Entries
        $findings = array_merge($findings, $this->auditOrphanFinancialRecords());

        // Detect paid/allocated Sales
        $findings = array_merge($findings, $this->auditPaidAllocatedSales());

        // Detect fiscal Sales
        $findings = array_merge($findings, $this->auditFiscalSales());

        $summary = $this->buildSummary($findings);

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditParallelFinancialRecords(): array
    {
        $findings = [];
        $sales = Sale::query()
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('invoices')
                    ->whereColumn('invoices.origem_id', 'sales.id')
                    ->where('invoices.origem_tipo', 'stock');
            })
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('financial_entries')
                    ->whereColumn('financial_entries.origem_id', 'sales.id')
                    ->where('financial_entries.origem_tipo', 'stock');
            })
            ->orderBy('created_at')
            ->get();

        foreach ($sales as $sale) {
            $invoices = Invoice::query()
                ->where('origem_tipo', 'stock')
                ->where('origem_id', $sale->id)
                ->get();

            $entries = FinancialEntry::query()
                ->where('origem_tipo', 'stock')
                ->where('origem_id', $sale->id)
                ->get();

            foreach ($entries as $entry) {
                $findings[] = $this->finding(
                    'warning',
                    'legacy_sale_parallel_invoice_and_entry',
                    'sale',
                    (string) $sale->id,
                    'financial_entry',
                    (string) $entry->id,
                    'Sale legacy tem Invoice e FinancialEntry paralela para a mesma origem.',
                    [
                        'sale_id' => (string) $sale->id,
                        'invoice_ids' => $invoices->pluck('id')->map(static fn ($id) => (string) $id)->all(),
                        'entry_ids' => $entries->pluck('id')->map(static fn ($id) => (string) $id)->all(),
                        'sale_total' => (float) $sale->total,
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditOrphanFinancialRecords(): array
    {
        $findings = [];

        // Orphan Invoices with origem_tipo='stock' but no Sale
        $orphanInvoices = Invoice::query()
            ->where('origem_tipo', 'stock')
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)
                    ->from('sales')
                    ->whereColumn('sales.id', 'invoices.origem_id');
            })
            ->orderBy('created_at')
            ->get();

        foreach ($orphanInvoices as $invoice) {
            $findings[] = $this->finding(
                'info',
                'legacy_sale_orphan_invoice_reference',
                'invoice',
                (string) $invoice->id,
                'sale',
                $invoice->origem_id ?? 'unknown',
                'Invoice com origem_tipo=stock mas Sale não existe ou foi deletada.',
                [
                    'invoice_id' => (string) $invoice->id,
                    'origem_id' => $invoice->origem_id,
                    'valor_total' => (float) $invoice->valor_total,
                    'estado_pagamento' => (string) $invoice->estado_pagamento,
                ],
            );
        }

        // Orphan FinancialEntries with origem_tipo='stock' but no Sale
        $orphanEntries = FinancialEntry::query()
            ->where('origem_tipo', 'stock')
            ->whereNotExists(function ($q) {
                $q->selectRaw(1)
                    ->from('sales')
                    ->whereColumn('sales.id', 'financial_entries.origem_id');
            })
            ->orderBy('created_at')
            ->get();

        foreach ($orphanEntries as $entry) {
            $findings[] = $this->finding(
                'info',
                'legacy_sale_orphan_financial_entry_reference',
                'financial_entry',
                (string) $entry->id,
                'sale',
                $entry->origem_id ?? 'unknown',
                'FinancialEntry com origem_tipo=stock mas Sale não existe ou foi deletada.',
                [
                    'entry_id' => (string) $entry->id,
                    'origem_id' => $entry->origem_id,
                    'valor' => (float) $entry->valor,
                    'tipo' => (string) $entry->tipo,
                ],
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditPaidAllocatedSales(): array
    {
        $findings = [];

        $sales = Sale::query()
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('invoices')
                    ->join('payment_allocations', 'payment_allocations.invoice_id', 'invoices.id')
                    ->whereColumn('invoices.origem_id', 'sales.id')
                    ->where('invoices.origem_tipo', 'stock')
                    ->where('payment_allocations.status', '!=', null);
            })
            ->orderBy('created_at')
            ->get();

        foreach ($sales as $sale) {
            $invoices = Invoice::query()
                ->where('origem_tipo', 'stock')
                ->where('origem_id', $sale->id)
                ->get();

            foreach ($invoices as $invoice) {
                $allocations = $invoice->allocations()->get();

                foreach ($allocations as $allocation) {
                    $findings[] = $this->finding(
                        'info',
                        'legacy_sale_allocated_financial_record',
                        'payment_allocation',
                        (string) $allocation->id,
                        'sale',
                        (string) $sale->id,
                        'Sale histórica tem alocação de pagamento confirmada. Dados legado em regime pago.',
                        [
                            'sale_id' => (string) $sale->id,
                            'invoice_id' => (string) $invoice->id,
                            'allocation_id' => (string) $allocation->id,
                            'allocation_status' => (string) $allocation->status,
                            'allocated_at' => (string) ($allocation->allocated_at ?? 'not allocated'),
                            'amount' => (float) $allocation->amount,
                        ],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditFiscalSales(): array
    {
        $findings = [];

        $sales = Sale::query()
            ->whereExists(function ($q) {
                $q->selectRaw(1)
                    ->from('invoices')
                    ->join('fiscal_document_requests', 'fiscal_document_requests.invoice_id', 'invoices.id')
                    ->whereColumn('invoices.origem_id', 'sales.id')
                    ->where('invoices.origem_tipo', 'stock')
                    ->where('fiscal_document_requests.id', '!=', null);
            })
            ->orderBy('created_at')
            ->get();

        foreach ($sales as $sale) {
            $invoices = Invoice::query()
                ->where('origem_tipo', 'stock')
                ->where('origem_id', $sale->id)
                ->get();

            foreach ($invoices as $invoice) {
                $fiscalRequests = $invoice->fiscalDocumentRequests()->get();

                foreach ($fiscalRequests as $fiscal) {
                    $findings[] = $this->finding(
                        'info',
                        'legacy_sale_fiscal_financial_record',
                        'fiscal_document_request',
                        (string) $fiscal->id,
                        'sale',
                        (string) $sale->id,
                        'Sale histórica tem pedido de documento fiscal emitido.',
                        [
                            'sale_id' => (string) $sale->id,
                            'invoice_id' => (string) $invoice->id,
                            'fiscal_request_id' => (string) $fiscal->id,
                            'fiscal_status' => (string) ($fiscal->status ?? 'unknown'),
                        ],
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * Build summary statistics from findings.
     *
     * @param list<array<string,mixed>> $findings
     * @return array<string,int>
     */
    private function buildSummary(array $findings): array
    {
        $countByCode = collect($findings)
            ->groupBy('code')
            ->map(static fn (Collection $items): int => $items->count())
            ->all();

        $countBySeverity = collect($findings)
            ->groupBy('severity')
            ->map(static fn (Collection $items): int => $items->count())
            ->all();

        return [
            'total_findings' => count($findings),
            'critical_count' => (int) ($countBySeverity['critical'] ?? 0),
            'warning_count' => (int) ($countBySeverity['warning'] ?? 0),
            'info_count' => (int) ($countBySeverity['info'] ?? 0),
            'findings_by_code' => $countByCode,
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function finding(
        string $severity,
        string $code,
        string $entityType,
        string $entityId,
        string $relatedType,
        string $relatedId,
        string $reason,
        array $metadata = [],
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'reason' => $reason,
            'metadata' => $metadata,
        ];
    }
}

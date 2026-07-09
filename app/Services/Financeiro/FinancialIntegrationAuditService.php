<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\CompetitionRegistration;
use App\Models\ConvocationGroup;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\LojaEncomenda;
use App\Models\MapaConciliacao;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\PaymentAllocation;
use App\Models\Prova;
use App\Models\Sale;
use App\Models\Sponsorship;
use App\Models\SponsorshipIntegration;
use App\Models\SponsorshipMoneyItem;
use App\Models\SupplierPurchase;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class FinancialIntegrationAuditService
{
    public const VERSION = 'xfin1-financial-integrations-audit-v1';

    /**
     * @var list<string>
     */
    public const MODULES = [
        'competition_registrations',
        'store',
        'supplier_purchases',
        'logistics_requests',
        'convocation_groups',
        'sponsorships',
        'reporting',
    ];

    /**
     * @param array{module?:string|null} $scope
     * @return array<string,mixed>
     */
    public function audit(array $scope = []): array
    {
        $selectedModules = $this->resolveModules($scope['module'] ?? null);
        $findings = [];

        foreach ($selectedModules as $module) {
            $findings = array_merge($findings, $this->auditModule($module));
        }

        $summary = $this->buildSummary($findings);

        return [
            'version' => self::VERSION,
            'generated_at' => now()->toIso8601String(),
            'scope' => [
                'module' => $scope['module'] ?? 'all',
                'modules' => $selectedModules,
            ],
            'summary' => $summary,
            'modules' => $this->buildModuleSummaries($selectedModules, $findings),
            'findings' => $findings,
        ];
    }

    /**
     * @return list<string>
     */
    public function supportedModules(): array
    {
        return array_merge(['all'], self::MODULES);
    }

    /**
     * @return list<string>
     */
    private function resolveModules(?string $module): array
    {
        $normalized = $module !== null ? trim($module) : 'all';

        if ($normalized === '' || $normalized === 'all') {
            return self::MODULES;
        }

        if (!in_array($normalized, self::MODULES, true)) {
            throw new \InvalidArgumentException('Unsupported module ['.$normalized.'].');
        }

        return [$normalized];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditModule(string $module): array
    {
        return match ($module) {
            'competition_registrations' => $this->auditCompetitionRegistrations(),
            'store' => $this->auditStore(),
            'supplier_purchases' => $this->auditSupplierPurchases(),
            'logistics_requests' => $this->auditLogisticsRequests(),
            'convocation_groups' => $this->auditConvocationGroups(),
            'sponsorships' => $this->auditSponsorships(),
            'reporting' => $this->auditReporting(),
            default => [],
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditCompetitionRegistrations(): array
    {
        $findings = [];

        $registrations = CompetitionRegistration::query()
            ->with(['prova.competition.evento'])
            ->orderBy('created_at')
            ->get();

        foreach ($registrations as $registration) {
            $invoice = $registration->fatura_id ? Invoice::query()->find($registration->fatura_id) : null;
            $effectiveValue = $this->registrationEffectiveValue($registration);

            if ($invoice && ($invoice->origem_tipo !== 'competition_registration' || (string) $invoice->origem_id !== (string) $registration->id)) {
                $findings[] = $this->finding(
                    'warning',
                    'competition_registration_non_specific_invoice_origin',
                    'competition_registrations',
                    'competition_registration',
                    (string) $registration->id,
                    'invoice',
                    (string) $invoice->id,
                    'CompetitionRegistration ligada a invoice com origem nao especifica da inscricao.',
                    [
                        'invoice_origin_type' => $invoice->origem_tipo,
                        'invoice_origin_id' => $invoice->origem_id,
                        'expected_origin_type' => 'competition_registration',
                    ],
                );
            }

            if ($invoice) {
                $parallelEntries = FinancialEntry::query()
                    ->where('fatura_id', $invoice->id)
                    ->where('origem_tipo', $invoice->origem_tipo)
                    ->where('origem_id', $invoice->origem_id)
                    ->get();

                foreach ($parallelEntries as $entry) {
                    $findings[] = $this->finding(
                        'critical',
                        'competition_registration_parallel_invoice_and_entry',
                        'competition_registrations',
                        'competition_registration',
                        (string) $registration->id,
                        'financial_entry',
                        (string) $entry->id,
                        'CompetitionRegistration tem invoice e financial entry paralela para a mesma origem funcional.',
                        [
                            'invoice_id' => (string) $invoice->id,
                            'invoice_origin_type' => $invoice->origem_tipo,
                            'invoice_origin_id' => $invoice->origem_id,
                        ],
                    );
                }
            }

            if ($effectiveValue <= 0.009) {
                $zeroValueInvoice = $invoice !== null;
                $zeroValueEntryExists = FinancialEntry::query()
                    ->where('user_id', $registration->user_id)
                    ->where(function ($query) use ($registration): void {
                        $query
                            ->where(function ($legacy) use ($registration): void {
                                $legacy->where('origem_tipo', 'evento')
                                    ->where('origem_id', (string) $registration->prova_id);
                            })
                            ->orWhere(function ($canonical) use ($registration): void {
                                $canonical->where('origem_tipo', 'competition_registration')
                                    ->where('origem_id', (string) $registration->id);
                            });
                    })
                    ->exists();

                if ($zeroValueInvoice || $zeroValueEntryExists) {
                    $findings[] = $this->finding(
                        'warning',
                        'zero_value_registration_financial_record',
                        'competition_registrations',
                        'competition_registration',
                        (string) $registration->id,
                        $zeroValueInvoice ? 'invoice' : 'financial_entry',
                        $zeroValueInvoice ? (string) $invoice->id : 'n/a',
                        'Inscricao com valor efetivo zero tem registo financeiro associado.',
                        [
                            'effective_value' => $effectiveValue,
                            'has_invoice' => $zeroValueInvoice,
                            'has_parallel_entry' => $zeroValueEntryExists,
                        ],
                    );
                }
            }

            if ($effectiveValue > 0.009 && !$invoice) {
                $findings[] = $this->finding(
                    'critical',
                    'paid_registration_source_without_invoice',
                    'competition_registrations',
                    'competition_registration',
                    (string) $registration->id,
                    'invoice',
                    null,
                    'Inscricao com valor efetivo superior a zero nao tem invoice ligada.',
                    [
                        'effective_value' => $effectiveValue,
                        'prova_id' => (string) $registration->prova_id,
                    ],
                );
            }
        }

        $proofIds = $registrations->pluck('prova_id')->filter()->map(static fn ($id) => (string) $id)->all();

        $registrationInvoices = Invoice::query()
            ->where('tipo', 'inscricao')
            ->where(function ($query) use ($proofIds): void {
                $query
                    ->where('origem_tipo', 'competition_registration')
                    ->orWhere(function ($nested) use ($proofIds): void {
                        $nested->where('origem_tipo', 'evento')
                            ->whereIn('origem_id', $proofIds);
                    });
            })
            ->get();

        foreach ($registrationInvoices as $invoice) {
            if ($invoice->origem_tipo === 'evento') {
                $findings[] = $this->finding(
                    'warning',
                    'registration_invoice_ambiguous_origin',
                    'competition_registrations',
                    'invoice',
                    (string) $invoice->id,
                    'invoice',
                    (string) $invoice->id,
                    'Invoice de inscricao aponta para prova/evento em vez de uma inscricao concreta.',
                    [
                        'invoice_origin_type' => $invoice->origem_tipo,
                        'invoice_origin_id' => $invoice->origem_id,
                    ],
                );
            }

            $hasConcreteRegistration = CompetitionRegistration::query()
                ->where('fatura_id', $invoice->id)
                ->exists();

            if (!$hasConcreteRegistration && $invoice->origem_tipo === 'competition_registration' && $invoice->origem_id !== null) {
                $hasConcreteRegistration = CompetitionRegistration::query()
                    ->whereKey($invoice->origem_id)
                    ->exists();
            }

            if (!$hasConcreteRegistration) {
                $findings[] = $this->finding(
                    'critical',
                    'registration_invoice_orphan',
                    'competition_registrations',
                    'invoice',
                    (string) $invoice->id,
                    'invoice',
                    (string) $invoice->id,
                    'Invoice de inscricao sem CompetitionRegistration identificavel.',
                    [
                        'invoice_origin_type' => $invoice->origem_tipo,
                        'invoice_origin_id' => $invoice->origem_id,
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditStore(): array
    {
        $findings = [];

        $orders = LojaEncomenda::query()
            ->with('user:id')
            ->orderBy('created_at')
            ->get();

        foreach ($orders as $order) {
            $movements = Movement::query()
                ->where('origem_tipo', 'stock')
                ->where('origem_id', $order->id)
                ->where('tipo', 'material')
                ->get();

            if ($order->estado === LojaEncomenda::ESTADO_ENTREGUE && (float) $order->total > 0 && $movements->isEmpty()) {
                $findings[] = $this->finding(
                    'warning',
                    'store_order_delivered_without_expected_movement',
                    'store',
                    'loja_encomenda',
                    (string) $order->id,
                    'movement',
                    null,
                    'Encomenda entregue sem movement de receita esperado.',
                    [
                        'order_total' => (float) $order->total,
                        'order_state' => $order->estado,
                    ],
                );
            }

            if ($movements->count() > 1) {
                foreach ($movements as $movement) {
                    $findings[] = $this->finding(
                        'warning',
                        'store_order_multiple_movements',
                        'store',
                        'loja_encomenda',
                        (string) $order->id,
                        'movement',
                        (string) $movement->id,
                        'Encomenda da loja com multiplos movements associados.',
                        [
                            'movement_count' => $movements->count(),
                        ],
                    );
                }
            }

            foreach ($movements as $movement) {
                if ((string) $movement->user_id !== (string) $order->user_id) {
                    $findings[] = $this->finding(
                        'warning',
                        'store_order_movement_wrong_user',
                        'store',
                        'loja_encomenda',
                        (string) $order->id,
                        'movement',
                        (string) $movement->id,
                        'Movement da encomenda usa user_id diferente do comprador.',
                        [
                            'order_user_id' => (string) $order->user_id,
                            'movement_user_id' => (string) $movement->user_id,
                        ],
                    );
                }

                if (!$this->amountsMatch((float) $movement->valor_total, (float) $order->total)) {
                    $findings[] = $this->finding(
                        'warning',
                        'store_order_movement_total_mismatch',
                        'store',
                        'loja_encomenda',
                        (string) $order->id,
                        'movement',
                        (string) $movement->id,
                        'Movement da encomenda tem total diferente do total da encomenda.',
                        [
                            'order_total' => (float) $order->total,
                            'movement_total' => (float) $movement->valor_total,
                        ],
                    );
                }
            }
        }

        $sales = Sale::query()->orderBy('created_at')->get();

        foreach ($sales as $sale) {
            $invoices = Invoice::query()
                ->where('origem_tipo', 'stock')
                ->where('origem_id', $sale->id)
                ->get();

            $entries = FinancialEntry::query()
                ->where('origem_tipo', 'stock')
                ->where('origem_id', $sale->id)
                ->get();

            if ($invoices->isNotEmpty() && $entries->isNotEmpty()) {
                foreach ($entries as $entry) {
                    $findings[] = $this->finding(
                        'critical',
                        'sale_parallel_invoice_and_entry',
                        'store',
                        'sale',
                        (string) $sale->id,
                        'financial_entry',
                        (string) $entry->id,
                        'Sale legacy tem invoice e financial entry paralela para a mesma origem.',
                        [
                            'invoice_ids' => $invoices->pluck('id')->map(static fn ($id) => (string) $id)->all(),
                        ],
                    );
                }
            }

            if ($invoices->isEmpty() && $entries->isEmpty()) {
                $findings[] = $this->finding(
                    'warning',
                    'sale_without_financial_link',
                    'store',
                    'sale',
                    (string) $sale->id,
                    'sale',
                    (string) $sale->id,
                    'Sale existente sem ligacao inequivoca a registo financeiro.',
                    [
                        'cliente_id' => (string) $sale->cliente_id,
                        'total' => (float) $sale->total,
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditSupplierPurchases(): array
    {
        $findings = [];

        $purchases = SupplierPurchase::query()->orderBy('created_at')->get();

        foreach ($purchases as $purchase) {
            $movement = $purchase->financial_movement_id ? Movement::query()->find($purchase->financial_movement_id) : null;
            $entry = $purchase->financial_entry_id ? FinancialEntry::query()->find($purchase->financial_entry_id) : null;

            if ($purchase->financial_movement_id && !$movement) {
                $findings[] = $this->finding(
                    'critical',
                    'supplier_purchase_orphan_financial_movement_reference',
                    'supplier_purchases',
                    'supplier_purchase',
                    (string) $purchase->id,
                    'movement',
                    (string) $purchase->financial_movement_id,
                    'SupplierPurchase referencia financial_movement_id orfao.',
                    [],
                );
            }

            if ($purchase->financial_entry_id && !$entry) {
                $findings[] = $this->finding(
                    'critical',
                    'supplier_purchase_orphan_financial_entry_reference',
                    'supplier_purchases',
                    'supplier_purchase',
                    (string) $purchase->id,
                    'financial_entry',
                    (string) $purchase->financial_entry_id,
                    'SupplierPurchase referencia financial_entry_id orfao.',
                    [],
                );
            }

            if ($movement && $entry) {
                $findings[] = $this->finding(
                    'critical',
                    'supplier_purchase_parallel_movement_and_entry',
                    'supplier_purchases',
                    'supplier_purchase',
                    (string) $purchase->id,
                    'financial_entry',
                    (string) $entry->id,
                    'SupplierPurchase tem movement e financial entry paralelos para a mesma compra.',
                    [
                        'movement_id' => (string) $movement->id,
                        'entry_id' => (string) $entry->id,
                    ],
                );
            }

            $sourceMovements = Movement::query()
                ->whereIn('origem_tipo', ['supplier_purchase', 'stock'])
                ->where('origem_id', $purchase->id)
                ->get();

            if ($sourceMovements->count() > 1) {
                foreach ($sourceMovements as $movementRow) {
                    $findings[] = $this->finding(
                        'warning',
                        'supplier_purchase_multiple_movements',
                        'supplier_purchases',
                        'supplier_purchase',
                        (string) $purchase->id,
                        'movement',
                        (string) $movementRow->id,
                        'SupplierPurchase com multiplos movements ligados a mesma origem funcional.',
                        [
                            'movement_count' => $sourceMovements->count(),
                        ],
                    );
                }
            }

            $sourceEntries = FinancialEntry::query()
                ->whereIn('origem_tipo', ['supplier_purchase', 'stock'])
                ->where('origem_id', $purchase->id)
                ->get();

            if ($sourceEntries->count() > 1) {
                foreach ($sourceEntries as $entryRow) {
                    $findings[] = $this->finding(
                        'warning',
                        'supplier_purchase_multiple_entries',
                        'supplier_purchases',
                        'supplier_purchase',
                        (string) $purchase->id,
                        'financial_entry',
                        (string) $entryRow->id,
                        'SupplierPurchase com multiplas financial entries para a mesma origem funcional.',
                        [
                            'entry_count' => $sourceEntries->count(),
                        ],
                    );
                }
            }

            if ($movement) {
                $movementEntries = FinancialEntry::query()
                    ->where('origem_tipo', 'movement')
                    ->where('origem_id', $movement->id)
                    ->count();

                $sourceKeyedEntries = FinancialEntry::query()
                    ->whereIn('origem_tipo', ['supplier_purchase', 'stock'])
                    ->where('origem_id', $purchase->id)
                    ->count();

                if ($sourceKeyedEntries > 0) {
                    $findings[] = $this->finding(
                        'warning',
                        'supplier_purchase_source_keyed_entry',
                        'supplier_purchases',
                        'supplier_purchase',
                        (string) $purchase->id,
                        'movement',
                        (string) $movement->id,
                        'Movement da compra nao tem financial entry origem=movement, mas existe entry keyed pela origem stock/purchase.',
                        [
                            'canonical_movement_entries_count' => $movementEntries,
                            'source_keyed_entries_count' => $sourceKeyedEntries,
                        ],
                    );
                }

                if ($movement->classificacao === 'despesa' && (float) $movement->valor_total < 0) {
                    $findings[] = $this->finding(
                        'warning',
                        'negative_expense_movement_value',
                        'supplier_purchases',
                        'supplier_purchase',
                        (string) $purchase->id,
                        'movement',
                        (string) $movement->id,
                        'Movement de despesa usa valor_total negativo.',
                        [
                            'movement_total' => (float) $movement->valor_total,
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
    private function auditLogisticsRequests(): array
    {
        $findings = [];

        $requests = LogisticsRequest::query()->orderBy('created_at')->get();

        foreach ($requests as $request) {
            $invoice = $request->financial_invoice_id ? Invoice::query()->find($request->financial_invoice_id) : null;

            if ($request->financial_invoice_id && !$invoice) {
                $findings[] = $this->finding(
                    'critical',
                    'logistics_request_orphan_invoice_reference',
                    'logistics_requests',
                    'logistics_request',
                    (string) $request->id,
                    'invoice',
                    (string) $request->financial_invoice_id,
                    'LogisticsRequest referencia financial_invoice_id inexistente.',
                    [],
                );
            }

            $sourceInvoices = Invoice::query()
                ->whereIn('origem_tipo', ['logistics_request', 'stock'])
                ->where('origem_id', $request->id)
                ->get();

            if ($sourceInvoices->count() > 1) {
                foreach ($sourceInvoices as $sourceInvoice) {
                    $findings[] = $this->finding(
                        'warning',
                        'logistics_request_multiple_invoices',
                        'logistics_requests',
                        'logistics_request',
                        (string) $request->id,
                        'invoice',
                        (string) $sourceInvoice->id,
                        'LogisticsRequest com multiplas invoices para a mesma request.',
                        [
                            'invoice_count' => $sourceInvoices->count(),
                        ],
                    );
                }
            }

            if (in_array($request->status, ['invoiced', 'delivered'], true) && !$invoice) {
                $findings[] = $this->finding(
                    'warning',
                    'logistics_request_missing_invoice',
                    'logistics_requests',
                    'logistics_request',
                    (string) $request->id,
                    'invoice',
                    null,
                    'LogisticsRequest faturada ou entregue sem invoice associada.',
                    [
                        'request_status' => $request->status,
                    ],
                );
            }

            if ($invoice) {
                if ($invoice->origem_tipo !== 'logistics_request' || (string) $invoice->origem_id !== (string) $request->id) {
                    $findings[] = $this->finding(
                        'warning',
                        'logistics_invoice_orphan_source',
                        'logistics_requests',
                        'logistics_request',
                        (string) $request->id,
                        'invoice',
                        (string) $invoice->id,
                        'Invoice ligada a request usa origem inexistente ou ambigua.',
                        [
                            'invoice_origin_type' => $invoice->origem_tipo,
                            'invoice_origin_id' => $invoice->origem_id,
                        ],
                    );
                }

                if (!$this->amountsMatch((float) $invoice->valor_total, (float) $request->total_amount)) {
                    $findings[] = $this->finding(
                        'warning',
                        'logistics_invoice_total_mismatch',
                        'logistics_requests',
                        'logistics_request',
                        (string) $request->id,
                        'invoice',
                        (string) $invoice->id,
                        'Invoice da request tem total diferente do total da request.',
                        [
                            'request_total' => (float) $request->total_amount,
                            'invoice_total' => (float) $invoice->valor_total,
                        ],
                    );
                }

                if ((string) $invoice->user_id !== (string) $request->requester_user_id) {
                    $findings[] = $this->finding(
                        'warning',
                        'logistics_invoice_user_mismatch',
                        'logistics_requests',
                        'logistics_request',
                        (string) $request->id,
                        'invoice',
                        (string) $invoice->id,
                        'Invoice da request usa user_id diferente do requester.',
                        [
                            'requester_user_id' => (string) $request->requester_user_id,
                            'invoice_user_id' => (string) $invoice->user_id,
                        ],
                    );
                }

                $confirmedAllocations = PaymentAllocation::query()
                    ->confirmed()
                    ->where('invoice_id', $invoice->id)
                    ->count();

                $issuedFiscal = FiscalDocumentRequest::query()
                    ->where('invoice_id', $invoice->id)
                    ->where('status', FiscalDocumentRequest::STATUS_ISSUED)
                    ->exists();

                $hasExternalDocument = FiscalDocumentRequest::query()
                    ->where('invoice_id', $invoice->id)
                    ->whereNotNull('external_document_number')
                    ->where('external_document_number', '!=', '')
                    ->exists();

                $hasReceiptData = filled($invoice->numero_recibo)
                    || $invoice->recibo_emitido_em !== null;

                if (in_array($request->status, ['draft', 'pending', 'approved', 'invoiced', 'delivered'], true)
                    && in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)) {
                    $findings[] = $this->finding(
                        'critical',
                        'logistics_paid_invoice_mutable_lifecycle',
                        'logistics_requests',
                        'logistics_request',
                        (string) $request->id,
                        'invoice',
                        (string) $invoice->id,
                        'Request continua mutavel pelo fluxo atual apesar de invoice paga/parcial.',
                        [
                            'request_status' => $request->status,
                            'invoice_payment_status' => $invoice->estado_pagamento,
                        ],
                    );
                }

                if (in_array($request->status, ['draft', 'pending', 'approved', 'invoiced', 'delivered'], true) && $issuedFiscal) {
                    $findings[] = $this->finding(
                        'critical',
                        'logistics_fiscal_invoice_mutable_lifecycle',
                        'logistics_requests',
                        'logistics_request',
                        (string) $request->id,
                        'invoice',
                        (string) $invoice->id,
                        'Request continua mutavel apesar de existir pedido fiscal emitido.',
                        [],
                    );
                }

                if (in_array($request->status, ['draft', 'pending', 'approved', 'invoiced', 'delivered'], true)
                    && ($hasExternalDocument || $hasReceiptData)) {
                    $findings[] = $this->finding(
                        'critical',
                        'logistics_fiscal_invoice_mutable_lifecycle',
                        'logistics_requests',
                        'logistics_request',
                        (string) $request->id,
                        'invoice',
                        (string) $invoice->id,
                        'Request continua mutavel apesar de existir documento fiscal/recibo associado.',
                        [
                            'has_external_document' => $hasExternalDocument,
                            'has_receipt_data' => $hasReceiptData,
                        ],
                    );
                }

                if (in_array($request->status, ['draft', 'pending', 'approved', 'invoiced', 'delivered'], true) && $confirmedAllocations > 0) {
                    $findings[] = $this->finding(
                        'critical',
                        'logistics_allocated_invoice_mutable_lifecycle',
                        'logistics_requests',
                        'logistics_request',
                        (string) $request->id,
                        'invoice',
                        (string) $invoice->id,
                        'Request continua mutavel apesar de existir PaymentAllocation confirmada.',
                        [
                            'confirmed_allocations' => $confirmedAllocations,
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
    private function auditConvocationGroups(): array
    {
        $findings = [];

        $groups = ConvocationGroup::query()->orderBy('created_at')->get();

        foreach ($groups as $group) {
            $movement = $group->movimento_id ? Movement::query()->find($group->movimento_id) : null;
            $calculatedCost = abs((float) ($group->valor_inscricao_calculado ?? 0));

            $canonicalMovements = Movement::query()
                ->where('origem_tipo', 'convocation_group')
                ->where('origem_id', $group->id)
                ->get();

            $eventOriginMovements = Movement::query()
                ->where('origem_tipo', 'evento')
                ->where('origem_id', $group->evento_id)
                ->get();

            if ($group->movimento_id && !$movement) {
                $findings[] = $this->finding(
                    'critical',
                    'convocation_group_orphan_movement_reference',
                    'convocation_groups',
                    'convocation_group',
                    (string) $group->id,
                    'movement',
                    (string) $group->movimento_id,
                    'ConvocationGroup referencia movimento_id inexistente.',
                    [],
                );
            }

            if ($calculatedCost > 0.009 && !$movement && $canonicalMovements->isEmpty()) {
                $findings[] = $this->finding(
                    'warning',
                    'convocation_group_missing_financial_movement',
                    'convocation_groups',
                    'convocation_group',
                    (string) $group->id,
                    'movement',
                    null,
                    'ConvocationGroup sem movement apesar de custo calculado positivo.',
                    [
                        'calculated_cost' => $calculatedCost,
                    ],
                );
            }

            if ($canonicalMovements->count() > 1) {
                foreach ($canonicalMovements as $candidateMovement) {
                    $findings[] = $this->finding(
                        'warning',
                        'convocation_group_multiple_movements',
                        'convocation_groups',
                        'convocation_group',
                        (string) $group->id,
                        'movement',
                        (string) $candidateMovement->id,
                        'ConvocationGroup com multiplos movements canónicos candidatos.',
                        [
                            'movement_count' => $canonicalMovements->count(),
                        ],
                    );
                }
            }

            if ($eventOriginMovements->count() > 1) {
                $findings[] = $this->finding(
                    'warning',
                    'convocation_group_ambiguous_event_origin',
                    'convocation_groups',
                    'convocation_group',
                    (string) $group->id,
                    'movement',
                    (string) $eventOriginMovements->first()->id,
                    'Multiplos movements com origem evento ligados ao mesmo evento da convocacao.',
                    [
                        'event_id' => (string) $group->evento_id,
                        'movement_count' => $eventOriginMovements->count(),
                    ],
                );
            }

            if ($movement && ((string) $movement->origem_tipo !== 'convocation_group' || (string) $movement->origem_id !== (string) $group->id)) {
                $findings[] = $this->finding(
                    'warning',
                    'convocation_group_non_specific_movement_origin',
                    'convocation_groups',
                    'convocation_group',
                    (string) $group->id,
                    'movement',
                    (string) $movement->id,
                    'Movement do grupo nao usa origem especifica convocation_group.',
                    [
                        'movement_origin_type' => $movement->origem_tipo,
                        'movement_origin_id' => $movement->origem_id,
                    ],
                );
            }

            $candidateMovements = collect([$movement])
                ->merge($canonicalMovements)
                ->filter()
                ->unique(fn (Movement $candidate) => (string) $candidate->id)
                ->values();

            foreach ($candidateMovements as $candidateMovement) {
                $movementEntries = FinancialEntry::query()
                    ->where('origem_tipo', 'movement')
                    ->where('origem_id', $candidateMovement->id)
                    ->get();

                $entryIds = $movementEntries->pluck('id')->filter()->values();

                $isSettled = in_array((string) $candidateMovement->estado_pagamento, ['pago', 'parcial', 'pago_parcial'], true)
                    || $movementEntries->contains(fn (FinancialEntry $entry): bool => in_array((string) $entry->estado, ['pago', 'parcial'], true)
                        || (float) ($entry->valor_pago ?? 0) > 0.009);

                if ($isSettled) {
                    $findings[] = $this->finding(
                        'critical',
                        'convocation_group_settled_movement_mutable_lifecycle',
                        'convocation_groups',
                        'convocation_group',
                        (string) $group->id,
                        'movement',
                        (string) $candidateMovement->id,
                        'Movement de convocacao em estado liquidado/parcial num lifecycle mutavel.',
                        [
                            'movement_payment_status' => $candidateMovement->estado_pagamento,
                        ],
                    );
                }

                $hasAllocation = $entryIds->isNotEmpty()
                    && PaymentAllocation::query()
                        ->confirmed()
                        ->whereIn('financial_entry_id', $entryIds)
                        ->whereNull('deleted_at')
                        ->exists();

                if ($hasAllocation) {
                    $findings[] = $this->finding(
                        'critical',
                        'convocation_group_allocated_movement_mutable_lifecycle',
                        'convocation_groups',
                        'convocation_group',
                        (string) $group->id,
                        'movement',
                        (string) $candidateMovement->id,
                        'Movement de convocacao com allocations confirmadas em lifecycle mutavel.',
                        [],
                    );
                }

                $isReconciled = (string) $candidateMovement->estado_conciliacao === 'conciliado'
                    || MapaConciliacao::query()
                        ->where('movimento_id', $candidateMovement->id)
                        ->where('status', 'confirmado')
                        ->exists()
                    || ($entryIds->isNotEmpty() && MapaConciliacao::query()
                        ->whereIn('lancamento_id', $entryIds)
                        ->where('status', 'confirmado')
                        ->exists());

                if ($isReconciled) {
                    $findings[] = $this->finding(
                        'critical',
                        'convocation_group_reconciled_movement_mutable_lifecycle',
                        'convocation_groups',
                        'convocation_group',
                        (string) $group->id,
                        'movement',
                        (string) $candidateMovement->id,
                        'Movement de convocacao conciliado em lifecycle mutavel.',
                        [
                            'movement_reconciliation_status' => $candidateMovement->estado_conciliacao,
                        ],
                    );
                }

                $hasFiscal = filled($candidateMovement->numero_recibo)
                    || filled($candidateMovement->external_document_number ?? null)
                    || ($entryIds->isNotEmpty() && FiscalDocumentRequest::query()
                        ->whereIn('financial_entry_id', $entryIds)
                        ->where(function ($query): void {
                            $query
                                ->where('status', FiscalDocumentRequest::STATUS_ISSUED)
                                ->orWhere(function ($nested): void {
                                    $nested->whereNotNull('external_document_number')
                                        ->where('external_document_number', '!=', '');
                                });
                        })
                        ->exists())
                    || MovementDocument::query()
                        ->where('movement_id', $candidateMovement->id)
                        ->whereIn('document_type', ['invoice', 'receipt', 'invoice_receipt'])
                        ->whereIn('status', ['issued', 'emitido', 'approved', 'validated'])
                        ->exists();

                if ($hasFiscal) {
                    $findings[] = $this->finding(
                        'critical',
                        'convocation_group_fiscal_movement_mutable_lifecycle',
                        'convocation_groups',
                        'convocation_group',
                        (string) $group->id,
                        'movement',
                        (string) $candidateMovement->id,
                        'Movement de convocacao com vinculo fiscal/documental em lifecycle mutavel.',
                        [],
                    );
                }
            }
        }

        $eventIds = $groups->pluck('evento_id')->filter()->map(static fn ($id) => (string) $id)->all();

        $orphanConvocationMovements = Movement::query()
            ->where('origem_tipo', 'convocation_group')
            ->get()
            ->filter(function (Movement $movement): bool {
                return !ConvocationGroup::query()->whereKey((string) $movement->origem_id)->exists();
            });

        foreach ($orphanConvocationMovements as $movement) {
            $findings[] = $this->finding(
                'warning',
                'convocation_movement_orphan_source',
                'convocation_groups',
                'movement',
                (string) $movement->id,
                'movement',
                (string) $movement->id,
                'Movement de convocacao com origem convocation_group sem source existente.',
                [
                    'movement_origin_type' => (string) $movement->origem_tipo,
                    'movement_origin_id' => (string) $movement->origem_id,
                ],
            );
        }

        $orphanMovements = Movement::query()
            ->where('origem_tipo', 'evento')
            ->whereIn('origem_id', $eventIds)
            ->get()
            ->filter(function (Movement $movement): bool {
                return !ConvocationGroup::query()->where('movimento_id', $movement->id)->exists();
            });

        foreach ($orphanMovements as $movement) {
            $findings[] = $this->finding(
                'warning',
                'convocation_movement_orphan_source',
                'convocation_groups',
                'movement',
                (string) $movement->id,
                'movement',
                (string) $movement->id,
                'Movement legacy de origem evento sem group identificavel.',
                [
                    'movement_origin_type' => (string) $movement->origem_tipo,
                    'event_id' => (string) $movement->origem_id,
                ],
            );
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditSponsorships(): array
    {
        $findings = [];

        $moneyItems = SponsorshipMoneyItem::query()->orderBy('created_at')->get();

        foreach ($moneyItems as $item) {
            $movement = $item->financial_movement_id ? Movement::query()->find($item->financial_movement_id) : null;

            if ($item->integration_status === 'generated' && !$item->financial_movement_id) {
                $findings[] = $this->finding(
                    'warning',
                    'sponsorship_money_item_generated_without_movement',
                    'sponsorships',
                    'sponsorship_money_item',
                    (string) $item->id,
                    'movement',
                    null,
                    'Money item marcada como generated sem financial_movement_id.',
                    [],
                );
            }

            if ($item->financial_movement_id && !$movement) {
                $findings[] = $this->finding(
                    'critical',
                    'sponsorship_money_item_orphan_movement_reference',
                    'sponsorships',
                    'sponsorship_money_item',
                    (string) $item->id,
                    'movement',
                    (string) $item->financial_movement_id,
                    'Money item referencia financial_movement_id inexistente.',
                    [],
                );
            }

            if ($movement) {
                $sameItemMovements = Movement::query()
                    ->where('tipo', 'patrocinio')
                    ->where('origem_tipo', 'patrocinio')
                    ->where('origem_id', $item->sponsorship_id)
                    ->get();

                if ($sameItemMovements->count() > 1) {
                    foreach ($sameItemMovements as $sameMovement) {
                        $findings[] = $this->finding(
                            'warning',
                            'sponsorship_origin_shared_across_money_items',
                            'sponsorships',
                            'sponsorship_money_item',
                            (string) $item->id,
                            'movement',
                            (string) $sameMovement->id,
                            'Varios money items partilham movement origin keyed ao sponsorship em vez do money item.',
                            [
                                'shared_origin_sponsorship_id' => (string) $item->sponsorship_id,
                                'movement_count' => $sameItemMovements->count(),
                            ],
                        );
                    }
                }

                if (in_array($movement->estado_pagamento, ['pago', 'parcial'], true)
                    || in_array((string) $movement->estado_conciliacao, ['conciliado', 'parcialmente_conciliado'], true)
                    || in_array((string) $movement->estado_documental, ['emitido', 'fiscal_emitido'], true)) {
                    $findings[] = $this->finding(
                        'critical',
                        'sponsorship_destructive_lifecycle_risk',
                        'sponsorships',
                        'sponsorship_money_item',
                        (string) $item->id,
                        'movement',
                        (string) $movement->id,
                        'Movement ligada a sponsorship continua ligada a fluxo potencialmente destrutivel.',
                        [
                            'movement_payment_status' => $movement->estado_pagamento,
                            'movement_reconciliation_status' => $movement->estado_conciliacao,
                            'movement_document_status' => $movement->estado_documental,
                        ],
                    );
                }
            }
        }

        $integrations = SponsorshipIntegration::query()
            ->where('integration_type', 'financial')
            ->orderBy('created_at')
            ->get();

        foreach ($integrations as $integration) {
            $movementExists = $integration->target_record_id
                ? Movement::query()->whereKey($integration->target_record_id)->exists()
                : false;

            if ($integration->status === 'generated' && !$movementExists) {
                $findings[] = $this->finding(
                    'warning',
                    'sponsorship_integration_generated_without_movement',
                    'sponsorships',
                    'sponsorship_integration',
                    (string) $integration->id,
                    'movement',
                    $integration->target_record_id ? (string) $integration->target_record_id : null,
                    'Integration generated sem movement resolvivel.',
                    [],
                );
            }

            if (in_array($integration->status, ['pending', 'failed'], true) && $movementExists) {
                $findings[] = $this->finding(
                    'warning',
                    'sponsorship_pending_integration_with_existing_movement',
                    'sponsorships',
                    'sponsorship_integration',
                    (string) $integration->id,
                    'movement',
                    (string) $integration->target_record_id,
                    'Integration pending/failed apesar de movement ja existir.',
                    [
                        'integration_status' => $integration->status,
                    ],
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function auditReporting(): array
    {
        $findings = [];

        $paidInvoiceIdsInReporting = app(FinancialReportingFactService::class)
            ->paidFacts()
            ->where('source_kind', 'invoice')
            ->pluck('source_id')
            ->map(static fn ($id) => (string) $id)
            ->all();

        $paidInvoices = Invoice::query()
            ->where('estado_pagamento', 'pago')
            ->whereNotIn('estado_pagamento', ['cancelada'])
            ->whereNotNull('data_pagamento')
            ->where('valor_pago', '>', 0)
            ->where(function ($query): void {
                $query->whereNull('oculta')->orWhere('oculta', false);
            })
            ->get();

        foreach ($paidInvoices as $invoice) {
            if (!in_array((string) $invoice->id, $paidInvoiceIdsInReporting, true)) {
                $findings[] = $this->finding(
                    'critical',
                    'paid_invoice_excluded_from_financial_reports',
                    'reporting',
                    'invoice',
                    (string) $invoice->id,
                    'invoice',
                    (string) $invoice->id,
                    'Invoice paga elegivel nao foi selecionada pelos factos canónicos de reporting financeiro.',
                    [
                        'invoice_type' => $invoice->tipo,
                    ],
                );
            }
        }

        $movements = Movement::query()->whereDoesntHave('financialEntries')->get();
        foreach ($movements as $movement) {
            $sourceKeyedEntries = FinancialEntry::query()
                ->where('origem_tipo', $movement->origem_tipo)
                ->where('origem_id', $movement->origem_id)
                ->where(function ($query) use ($movement): void {
                    $query->whereNull('fatura_id')
                        ->where(function ($nested) use ($movement): void {
                            $nested->whereNull('user_id')
                                ->orWhere('user_id', $movement->user_id);
                        });
                })
                ->count();

            if ($sourceKeyedEntries > 0) {
                $findings[] = $this->finding(
                    'critical',
                    'financial_report_double_count_risk',
                    'reporting',
                    'movement',
                    (string) $movement->id,
                    'movement',
                    (string) $movement->id,
                    'Movement legacy sem entry origem=movement pode coexistir com financial entry selecionavel no reporte.',
                    [
                        'source_keyed_entries_count' => $sourceKeyedEntries,
                        'movement_origin_type' => $movement->origem_tipo,
                        'movement_origin_id' => $movement->origem_id,
                    ],
                );
            }
        }

        $negativeExpenseMovements = Movement::query()
            ->where('classificacao', 'despesa')
            ->where('valor_total', '<', 0)
            ->get();

        foreach ($negativeExpenseMovements as $movement) {
            $findings[] = $this->finding(
                'warning',
                'negative_expense_movement_value',
                'reporting',
                'movement',
                (string) $movement->id,
                'movement',
                (string) $movement->id,
                'Movement de despesa com valor_total negativo.',
                [
                    'movement_total' => (float) $movement->valor_total,
                ],
            );
        }

        $negativeExpenseEntries = FinancialEntry::query()
            ->where('tipo', 'despesa')
            ->where('valor', '<', 0)
            ->get();

        foreach ($negativeExpenseEntries as $entry) {
            $findings[] = $this->finding(
                'warning',
                'negative_expense_financial_entry_value',
                'reporting',
                'financial_entry',
                (string) $entry->id,
                'financial_entry',
                (string) $entry->id,
                'Financial entry de despesa com valor negativo.',
                [
                    'entry_value' => (float) $entry->valor,
                ],
            );
        }

        $snapshotInvoices = Invoice::query()->get();
        foreach ($snapshotInvoices as $invoice) {
            if ($invoice->estado_pagamento === 'cancelada') {
                continue;
            }

            $hasSnapshots = $invoice->valor_pago !== null || $invoice->valor_em_aberto !== null;
            if (!$hasSnapshots) {
                continue;
            }

            $valorPago = (float) ($invoice->valor_pago ?? 0);
            $valorAberto = (float) ($invoice->valor_em_aberto ?? 0);
            $valorTotal = (float) ($invoice->valor_total ?? 0);
            $delta = abs(($valorPago + $valorAberto) - $valorTotal);

            if ($valorPago < 0 || $valorAberto < 0 || $valorPago > $valorTotal || $valorAberto > $valorTotal || $delta > 0.01) {
                $findings[] = $this->finding(
                    'warning',
                    'invoice_financial_snapshot_mismatch',
                    'reporting',
                    'invoice',
                    (string) $invoice->id,
                    'invoice',
                    (string) $invoice->id,
                    'Invoice com snapshot financeiro incoerente.',
                    [
                        'valor_total' => $valorTotal,
                        'valor_pago' => $valorPago,
                        'valor_em_aberto' => $valorAberto,
                        'delta' => round($delta, 2),
                    ],
                );
            }
        }

        return $findings;
    }

    private function registrationEffectiveValue(CompetitionRegistration $registration): float
    {
        $directValue = $registration->valor_inscricao !== null ? (float) $registration->valor_inscricao : null;
        if ($directValue !== null) {
            return round($directValue, 2);
        }

        $prova = $registration->relationLoaded('prova') ? $registration->prova : $registration->prova()->with('competition.evento')->first();
        $event = $prova?->competition?->evento;

        return round((float) ($event?->taxa_inscricao ?? 0), 2);
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function buildSummary(array $findings): array
    {
        $criticalModules = collect($findings)
            ->where('severity', 'critical')
            ->pluck('module')
            ->unique()
            ->values()
            ->all();

        $warningModules = collect($findings)
            ->where('severity', 'warning')
            ->pluck('module')
            ->unique()
            ->values()
            ->all();

        return [
            'total_findings' => count($findings),
            'critical_count' => collect($findings)->where('severity', 'critical')->count(),
            'warning_count' => collect($findings)->where('severity', 'warning')->count(),
            'info_count' => collect($findings)->where('severity', 'info')->count(),
            'modules_with_critical' => $criticalModules,
            'modules_with_warning' => $warningModules,
        ];
    }

    /**
     * @param list<string> $selectedModules
     * @param list<array<string,mixed>> $findings
     * @return list<array<string,mixed>>
     */
    private function buildModuleSummaries(array $selectedModules, array $findings): array
    {
        return collect($selectedModules)
            ->map(function (string $module) use ($findings): array {
                $moduleFindings = collect($findings)->where('module', $module)->values();

                return [
                    'module' => $module,
                    'total_findings' => $moduleFindings->count(),
                    'critical_count' => $moduleFindings->where('severity', 'critical')->count(),
                    'warning_count' => $moduleFindings->where('severity', 'warning')->count(),
                    'info_count' => $moduleFindings->where('severity', 'info')->count(),
                    'finding_codes' => $moduleFindings->pluck('code')->unique()->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function finding(
        string $severity,
        string $code,
        string $module,
        string $sourceType,
        ?string $sourceId,
        string $financialRecordType,
        ?string $financialRecordId,
        string $reason,
        array $metadata
    ): array {
        return [
            'severity' => $severity,
            'code' => $code,
            'module' => $module,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'financial_record_type' => $financialRecordType,
            'financial_record_id' => $financialRecordId,
            'reason' => $reason,
            'metadata' => $metadata,
        ];
    }

    private function amountsMatch(float $left, float $right): bool
    {
        return abs($left - $right) <= 0.01;
    }
}
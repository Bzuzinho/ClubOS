<?php

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualInvoiceService
{
    public function __construct(
        private readonly InvoiceFinancialGuardService $financialGuard,
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    public function create(array $data, ?User $actor = null): Invoice
    {
        $requestedStatus = $data['estado_pagamento'] ?? 'pendente';
        $this->ensureManualOpenStatus($requestedStatus, true);
        $this->ensureManualInvoiceType($data['tipo'] ?? null);

        $items = $this->normalizeAndValidateItems($data);
        $total = $this->validateInvoiceTotal($data, $items);

        return DB::transaction(function () use ($data, $items, $total, $requestedStatus, $actor): Invoice {
            $invoice = Invoice::query()->create([
                'user_id' => $data['user_id'],
                'data_fatura' => $data['data_fatura'] ?? $data['data_emissao'],
                'mes' => $data['mes'] ?? null,
                'data_emissao' => $data['data_emissao'],
                'data_vencimento' => $data['data_vencimento'],
                'valor_total' => $total,
                'valor_pago' => 0,
                'valor_em_aberto' => $requestedStatus === 'cancelado' ? 0 : $total,
                'oculta' => $data['oculta'] ?? false,
                'estado_pagamento' => $requestedStatus,
                'referencia_pagamento' => $data['referencia_pagamento'] ?? null,
                'centro_custo_id' => $data['centro_custo_id'] ?? null,
                'tipo' => $data['tipo'],
                'origem_tipo' => $data['origem_tipo'] ?? 'manual',
                'origem_id' => $data['origem_id'] ?? null,
                'observacoes' => $data['observacoes'] ?? null,
            ]);

            $createdItems = $this->createItems($invoice, $items);
            $this->registerInvoiceItemExits($createdItems, $actor, 'manual_invoice_create', 'Saída de stock por criação de fatura manual');

            return $invoice->fresh(['items']);
        });
    }

    public function update(Invoice $invoice, array $data, ?User $actor = null): Invoice
    {
        $requestedStatus = $data['estado_pagamento'] ?? $invoice->estado_pagamento;
        $this->ensureMonthlyStatusTransitionUsesCanonicalFlow($invoice, $data['tipo'] ?? null, $requestedStatus);
        $this->ensureManualPaymentReversalUsesCanonicalFlow($invoice, $requestedStatus);
        $this->ensureEditableInvoiceTypeForUpdate($invoice, $data['tipo'] ?? null);
        $this->ensureManualOpenStatus($requestedStatus);

        $items = $this->normalizeAndValidateItems($data);
        $total = $this->validateInvoiceTotal($data, $items);

        return DB::transaction(function () use ($invoice, $data, $items, $total, $requestedStatus, $actor): Invoice {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureEditableInvoiceTypeForUpdate($lockedInvoice, $data['tipo'] ?? null);
            $this->ensureEditable($lockedInvoice);

            $existingItems = $lockedInvoice->items()->lockForUpdate()->get();
            $this->registerInvoiceItemReturns($existingItems->all(), $actor, 'manual_invoice_update_reversal', 'Reposição de stock por atualização de fatura manual');

            $lockedInvoice->update([
                'user_id' => $data['user_id'],
                'data_fatura' => $data['data_fatura'] ?? $data['data_emissao'],
                'mes' => $data['mes'] ?? null,
                'data_emissao' => $data['data_emissao'],
                'data_vencimento' => $data['data_vencimento'],
                'valor_total' => $total,
                'valor_pago' => 0,
                'valor_em_aberto' => $requestedStatus === 'cancelado' ? 0 : $total,
                'oculta' => $data['oculta'] ?? $lockedInvoice->oculta,
                'estado_pagamento' => $requestedStatus,
                'referencia_pagamento' => $data['referencia_pagamento'] ?? $lockedInvoice->referencia_pagamento,
                'centro_custo_id' => $data['centro_custo_id'] ?? null,
                'tipo' => $data['tipo'],
                'origem_tipo' => $data['origem_tipo'] ?? $lockedInvoice->origem_tipo ?? 'manual',
                'origem_id' => $data['origem_id'] ?? $lockedInvoice->origem_id,
                'observacoes' => $data['observacoes'] ?? null,
            ]);

            $lockedInvoice->items()->delete();
            $createdItems = $this->createItems($lockedInvoice, $items);
            $this->registerInvoiceItemExits($createdItems, $actor, 'manual_invoice_update_exit', 'Saída de stock por atualização de fatura manual');

            return $lockedInvoice->fresh(['items']);
        });
    }

    public function delete(Invoice $invoice, ?User $actor = null): void
    {
        DB::transaction(function () use ($invoice, $actor): void {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureManualInvoiceType($lockedInvoice->tipo);
            $this->ensureEditable($lockedInvoice);

            $existingItems = $lockedInvoice->items()->lockForUpdate()->get();
            $this->registerInvoiceItemReturns($existingItems->all(), $actor, 'manual_invoice_delete', 'Reposição de stock por eliminação de fatura manual');

            $lockedInvoice->items()->delete();
            $lockedInvoice->delete();
        });
    }

    private function ensureEditable(Invoice $invoice): void
    {
        if ($this->financialGuard->hasFinancialOrFiscalTrail($invoice)) {
            throw ValidationException::withMessages([
                'invoice' => 'A fatura tem rasto financeiro ou fiscal. Deve ser cancelada/anulada, nao apagada nem alterada manualmente.',
            ]);
        }
    }

    private function ensureManualOpenStatus(string $status, bool $canonicalPaymentMessage = false): void
    {
        if (in_array($status, ['pago', 'parcial'], true)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => $canonicalPaymentMessage
                    ? 'A liquidacao da fatura tem de ser efetuada pelo fluxo canonico de pagamento.'
                    : 'A liquidacao da fatura tem de ser efetuada pelo fluxo de pagamento.',
            ]);
        }
    }

    private function ensureMonthlyStatusTransitionUsesCanonicalFlow(Invoice $invoice, ?string $requestedType, string $requestedStatus): void
    {
        $isMonthly = $invoice->tipo === 'mensalidade' || $requestedType === 'mensalidade';
        $isFinancialTransition = $isMonthly && (
            (
                in_array($requestedStatus, ['pago', 'parcial'], true)
                && ! in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
            )
            || (
                in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
                && ! in_array($requestedStatus, ['pago', 'parcial'], true)
            )
            || ($invoice->estado_pagamento === 'parcial' && $requestedStatus === 'pago')
        );

        if ($isFinancialTransition) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A alteracao de estado financeiro da mensalidade tem de ser efetuada pelo fluxo canonico da mensalidade.',
            ]);
        }
    }

    private function ensureManualPaymentReversalUsesCanonicalFlow(Invoice $invoice, string $requestedStatus): void
    {
        if (
            $invoice->tipo !== 'mensalidade'
            && in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
            && ! in_array($requestedStatus, ['pago', 'parcial'], true)
        ) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A reabertura da fatura tem de ser efetuada pelo endpoint canonico de reabertura.',
            ]);
        }
    }

    private function ensureEditableInvoiceTypeForUpdate(Invoice $invoice, ?string $requestedType): void
    {
        $targetType = $requestedType ?? $invoice->tipo;

        if ($invoice->tipo === 'mensalidade') {
            if ($targetType !== 'mensalidade') {
                throw ValidationException::withMessages([
                    'tipo' => 'Uma mensalidade existente deve manter o tipo mensalidade durante uma correção administrativa.',
                ]);
            }

            return;
        }

        if ($targetType === 'mensalidade') {
            throw ValidationException::withMessages([
                'tipo' => 'Uma fatura manual não pode ser convertida numa mensalidade. Use o motor canónico de mensalidades.',
            ]);
        }
    }

    private function ensureManualInvoiceType(?string $type): void
    {
        if ($type === 'mensalidade') {
            throw ValidationException::withMessages([
                'tipo' => 'Mensalidades devem ser criadas, canceladas e reconciliadas pelo lifecycle/motor canonico de mensalidades.',
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeAndValidateItems(array $data): array
    {
        $items = [];

        foreach ($data['items'] ?? [] as $index => $item) {
            $quantity = (int) $item['quantidade'];
            $unit = round((float) $item['valor_unitario'], 2);
            $tax = round((float) ($item['imposto_percentual'] ?? 0), 2);
            $expectedLineTotal = round($quantity * $unit * (1 + ($tax / 100)), 2);
            $providedLineTotal = round((float) $item['total_linha'], 2);

            if (abs($providedLineTotal - $expectedLineTotal) > 0.01) {
                throw ValidationException::withMessages([
                    "items.$index.total_linha" => 'O total da linha nao corresponde a quantidade, valor unitario e imposto.',
                ]);
            }

            $items[] = [
                'descricao' => $item['descricao'],
                'quantidade' => $quantity,
                'valor_unitario' => $unit,
                'imposto_percentual' => $tax,
                'total_linha' => $expectedLineTotal,
                'produto_id' => $item['produto_id'] ?? null,
                'centro_custo_id' => $item['centro_custo_id'] ?? $data['centro_custo_id'] ?? null,
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function validateInvoiceTotal(array $data, array $items): float
    {
        $expectedTotal = round(array_sum(array_map(
            fn (array $item): float => (float) $item['total_linha'],
            $items
        )), 2);
        $providedTotal = round((float) $data['valor_total'], 2);

        if (abs($providedTotal - $expectedTotal) > 0.01) {
            throw ValidationException::withMessages([
                'valor_total' => 'O total da fatura nao corresponde a soma das linhas.',
            ]);
        }

        return $expectedTotal;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function createItems(Invoice $invoice, array $items): array
    {
        $createdItems = [];

        foreach ($items as $item) {
            $createdItems[] = InvoiceItem::query()->create([
                'fatura_id' => $invoice->id,
                'descricao' => $item['descricao'],
                'quantidade' => $item['quantidade'],
                'valor_unitario' => $item['valor_unitario'],
                'imposto_percentual' => $item['imposto_percentual'],
                'total_linha' => $item['total_linha'],
                'produto_id' => $item['produto_id'],
                'centro_custo_id' => $item['centro_custo_id'],
            ]);
        }

        return $createdItems;
    }

    /**
     * @param array<int, InvoiceItem|array<string, mixed>> $items
     */
    private function registerInvoiceItemExits(array $items, ?User $actor, string $sourceType, string $notes): void
    {
        foreach ($items as $item) {
            if (!$item instanceof InvoiceItem || empty($item->produto_id)) {
                continue;
            }

            $product = Product::query()->whereKey($item->produto_id)->lockForUpdate()->firstOrFail();
            $this->stockLedger->registerExit($product, (int) $item->quantidade, [
                'source_type' => $sourceType,
                'source_id' => $item->id,
                'notes' => $notes,
                'created_by' => $actor?->id,
                'occurred_at' => $item->created_at ?? now(),
                'idempotency_key' => $sourceType.'-'.$item->id,
            ]);
        }
    }

    /**
     * @param array<int, InvoiceItem|array<string, mixed>> $items
     */
    private function registerInvoiceItemReturns(array $items, ?User $actor, string $sourceType, string $notes): void
    {
        foreach ($items as $item) {
            if (!$item instanceof InvoiceItem || empty($item->produto_id)) {
                continue;
            }

            $product = Product::query()->whereKey($item->produto_id)->lockForUpdate()->firstOrFail();
            $this->stockLedger->registerReturn($product, (int) $item->quantidade, [
                'source_type' => $sourceType,
                'source_id' => $item->id,
                'notes' => $notes,
                'created_by' => $actor?->id,
                'occurred_at' => now(),
                'idempotency_key' => $sourceType.'-'.$item->id,
            ]);
        }
    }
}

<?php

namespace App\Services\Financeiro;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualInvoiceService
{
    public function __construct(
        private readonly InvoiceFinancialGuardService $financialGuard,
    ) {
    }

    public function create(array $data, ?User $actor = null): Invoice
    {
        $requestedStatus = $data['estado_pagamento'] ?? 'pendente';
        $this->ensureManualOpenStatus($requestedStatus, true);
        $this->ensureManualInvoiceType($data['tipo'] ?? null);

        $items = $this->normalizeAndValidateItems($data);
        $total = $this->validateInvoiceTotal($data, $items);

        return DB::transaction(function () use ($data, $items, $total, $requestedStatus): Invoice {
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

            $this->createItems($invoice, $items);
            $this->applyProductDeltas($this->productQuantities([]), $this->productQuantities($items));

            return $invoice->fresh(['items']);
        });
    }

    public function update(Invoice $invoice, array $data, ?User $actor = null): Invoice
    {
        $requestedStatus = $data['estado_pagamento'] ?? $invoice->estado_pagamento;
        $this->ensureMonthlyStatusTransitionUsesCanonicalFlow($invoice, $data['tipo'] ?? null, $requestedStatus);
        $this->ensureManualPaymentReversalUsesCanonicalFlow($invoice, $requestedStatus);
        $this->ensureManualInvoiceType($data['tipo'] ?? null);
        $this->ensureManualOpenStatus($requestedStatus);

        $items = $this->normalizeAndValidateItems($data);
        $total = $this->validateInvoiceTotal($data, $items);

        return DB::transaction(function () use ($invoice, $data, $items, $total, $requestedStatus): Invoice {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureManualInvoiceType($lockedInvoice->tipo);
            $this->ensureEditable($lockedInvoice);

            $existingItems = $lockedInvoice->items()->lockForUpdate()->get();
            $existingQuantities = $this->productQuantities($existingItems->all());

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
            $this->createItems($lockedInvoice, $items);
            $this->applyProductDeltas($existingQuantities, $this->productQuantities($items));

            return $lockedInvoice->fresh(['items']);
        });
    }

    public function delete(Invoice $invoice, ?User $actor = null): void
    {
        DB::transaction(function () use ($invoice): void {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->ensureManualInvoiceType($lockedInvoice->tipo);
            $this->ensureEditable($lockedInvoice);

            $existingItems = $lockedInvoice->items()->lockForUpdate()->get();
            $this->applyProductDeltas($this->productQuantities($existingItems->all()), []);

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
    private function createItems(Invoice $invoice, array $items): void
    {
        foreach ($items as $item) {
            InvoiceItem::query()->create([
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
    }

    /**
     * @param array<int|string, int> $previous
     * @param array<int|string, int> $next
     */
    private function applyProductDeltas(array $previous, array $next): void
    {
        $productIds = array_unique(array_merge(array_keys($previous), array_keys($next)));

        foreach ($productIds as $productId) {
            $delta = ((int) ($next[$productId] ?? 0)) - ((int) ($previous[$productId] ?? 0));

            if ($delta === 0) {
                continue;
            }

            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

            if ($delta > 0) {
                $product->decrement('stock', $delta);
            } else {
                $product->increment('stock', abs($delta));
            }
        }
    }

    /**
     * @param array<int, InvoiceItem|array<string, mixed>> $items
     * @return array<string, int>
     */
    private function productQuantities(array $items): array
    {
        $quantities = [];

        foreach ($items as $item) {
            $productId = $item instanceof InvoiceItem ? $item->produto_id : ($item['produto_id'] ?? null);

            if (empty($productId)) {
                continue;
            }

            $quantity = $item instanceof InvoiceItem ? (int) $item->quantidade : (int) $item['quantidade'];
            $quantities[(string) $productId] = ($quantities[(string) $productId] ?? 0) + $quantity;
        }

        return $quantities;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\BankStatement;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\ReceiptImportItem;
use App\Models\User;
use App\Services\Members\MemberFiscalDataResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class FiscalDocumentIssuePreflightService
{
    private const VERSION = 'a6-3-fiscal-document-issue-preflight-v1';
    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly MemberFiscalDataResolver $memberFiscalDataResolver,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function preflight(array $options = []): array
    {
        $filters = $this->filters($options);
        $items = $this->candidateRequests($filters)
            ->map(fn (FiscalDocumentRequest $request): array => $this->buildItem($request))
            ->values()
            ->all();

        if ($filters['only_ready']) {
            $items = array_values(array_filter($items, static fn (array $item): bool => (bool) data_get($item, 'readiness.ready')));
        }

        $groups = $this->groups($items);
        $items = $this->hydrateItemGrouping($items, $groups);

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => $filters,
            'summary' => $this->summary($items, $groups),
            'groups' => $groups,
            'items' => $items,
            'read_only' => true,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function filters(array $options): array
    {
        return [
            'fiscal_request' => $this->stringOrNull($options['fiscal_request'] ?? null),
            'invoice' => $this->stringOrNull($options['invoice'] ?? null),
            'payment' => $this->stringOrNull($options['payment'] ?? null),
            'user' => $this->stringOrNull($options['user'] ?? null),
            'provider' => $this->stringOrNull($options['provider'] ?? null),
            'only_ready' => (bool) ($options['only_ready'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,FiscalDocumentRequest>
     */
    private function candidateRequests(array $filters): Collection
    {
        if (! Schema::hasTable('fiscal_document_requests')) {
            return collect();
        }

        $query = FiscalDocumentRequest::query()
            ->where('status', FiscalDocumentRequest::STATUS_PENDING)
            ->whereIn('document_type', [
                FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
                FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE_RECEIPT,
            ])
            ->whereNull('external_document_number')
            ->whereNull('external_document_id')
            ->whereNull('issued_at')
            ->whereNull('handled_at')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($filters['fiscal_request']) {
            $query->whereKey($filters['fiscal_request']);
        }

        if ($filters['invoice']) {
            $query->where('invoice_id', $filters['invoice']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['provider']) {
            $query->where('provider', $filters['provider']);
        }

        if ($filters['payment']) {
            $invoiceIds = PaymentAllocation::withTrashed()
                ->where('payment_id', $filters['payment'])
                ->pluck('invoice_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($invoiceIds === []) {
                return collect();
            }

            $query->whereIn('invoice_id', $invoiceIds);
        }

        return $query->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function buildItem(FiscalDocumentRequest $request): array
    {
        $invoice = $request->invoice_id ? Invoice::query()->find($request->invoice_id) : null;
        $user = $this->resolveUser($request, $invoice);
        $items = $this->invoiceItems($invoice);
        $allocations = $this->allocations($invoice);
        $payments = $this->payments($request, $allocations);
        $activeAllocations = $allocations->filter(fn (PaymentAllocation $allocation): bool => $this->activeAllocation($allocation))->values();
        $activePayments = $payments->filter(fn (Payment $payment): bool => $this->activePayment($payment))->values();
        $primaryAllocation = $activeAllocations->first();
        $primaryPayment = $activePayments->first();
        $receiptItems = $this->receiptImportItems($request, $invoice);
        $bankStatement = $this->bankStatement($request, $primaryPayment);
        $reconciliation = $this->reconciliation($request, $invoice, $primaryPayment, $primaryAllocation);
        $customer = $this->customerSnapshot($request, $user);
        $amountAnalysis = $this->amountAnalysis($request, $invoice, $activeAllocations);
        $grouping = $this->grouping($request, $invoice, $primaryPayment, $activeAllocations);
        $readiness = $this->readiness($request, $invoice, $items, $activeAllocations, $activePayments, $receiptItems, $customer, $amountAnalysis);

        return [
            'fiscal_request' => $this->fiscalRequestSnapshot($request),
            'invoice' => $this->invoiceSnapshot($invoice),
            'user' => $customer,
            'payment_allocation' => $this->paymentAllocationSnapshot($primaryPayment, $primaryAllocation, $bankStatement, $reconciliation),
            'provider_payload_preview' => $this->providerPayloadPreview($request, $invoice, $primaryPayment, $primaryAllocation, $customer, $items),
            'readiness' => $readiness,
            'grouping' => $grouping,
            'amount_analysis' => $amountAnalysis,
            'read_only' => true,
        ];
    }

    private function resolveUser(FiscalDocumentRequest $request, ?Invoice $invoice): ?User
    {
        $id = $request->user_id ?: $invoice?->user_id;

        return $id ? User::query()->find($id) : null;
    }

    /**
     * @return Collection<int,InvoiceItem>
     */
    private function invoiceItems(?Invoice $invoice): Collection
    {
        if (! $invoice instanceof Invoice || ! Schema::hasTable('invoice_items')) {
            return collect();
        }

        return InvoiceItem::query()
            ->where('fatura_id', $invoice->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int,PaymentAllocation>
     */
    private function allocations(?Invoice $invoice): Collection
    {
        if (! $invoice instanceof Invoice || ! Schema::hasTable('payment_allocations')) {
            return collect();
        }

        return PaymentAllocation::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param Collection<int,PaymentAllocation> $allocations
     * @return Collection<int,Payment>
     */
    private function payments(FiscalDocumentRequest $request, Collection $allocations): Collection
    {
        if (! Schema::hasTable('payments')) {
            return collect();
        }

        $ids = $allocations->pluck('payment_id')->filter()->unique()->values();
        if ($request->bank_statement_id) {
            Payment::withTrashed()
                ->where('bank_statement_id', $request->bank_statement_id)
                ->pluck('id')
                ->each(fn (mixed $id) => $ids->push($id));
        }

        $ids = $ids->filter()->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return Payment::withTrashed()
            ->whereIn('id', $ids)
            ->orderBy('payment_date')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int,ReceiptImportItem>
     */
    private function receiptImportItems(FiscalDocumentRequest $request, ?Invoice $invoice): Collection
    {
        if (! Schema::hasTable('receipt_import_items')) {
            return collect();
        }

        return ReceiptImportItem::query()
            ->where(function (Builder $query) use ($request, $invoice): void {
                $query->when($request->invoice_id, fn (Builder $q) => $q->orWhere('invoice_id', $request->invoice_id))
                    ->when($request->bank_statement_id, fn (Builder $q) => $q->orWhere('bank_statement_id', $request->bank_statement_id))
                    ->when($invoice?->receipt_import_item_id, fn (Builder $q) => $q->orWhereKey($invoice->receipt_import_item_id));
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    private function bankStatement(FiscalDocumentRequest $request, ?Payment $payment): ?BankStatement
    {
        $id = $request->bank_statement_id ?: $payment?->bank_statement_id;

        return $id && Schema::hasTable('bank_statements') ? BankStatement::query()->find($id) : null;
    }

    private function reconciliation(FiscalDocumentRequest $request, ?Invoice $invoice, ?Payment $payment, ?PaymentAllocation $allocation): ?MapaConciliacao
    {
        if (! Schema::hasTable('mapa_conciliacao')) {
            return null;
        }

        return MapaConciliacao::query()
            ->where(function (Builder $query) use ($request, $invoice, $payment, $allocation): void {
                $query->when($request->mapa_conciliacao_id, fn (Builder $q) => $q->orWhereKey($request->mapa_conciliacao_id))
                    ->when($invoice?->id, fn (Builder $q) => $q->orWhere('fatura_id', $invoice->id))
                    ->when($payment?->id, fn (Builder $q) => $q->orWhere('payment_id', $payment->id))
                    ->when($allocation?->id, fn (Builder $q) => $q->orWhere('payment_allocation_id', $allocation->id));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function customerSnapshot(FiscalDocumentRequest $request, ?User $user): array
    {
        $fiscalData = $user instanceof User ? $this->memberFiscalDataResolver->resolve($user) : [
            'nome' => null,
            'nif' => null,
            'morada' => null,
            'codigo_postal' => null,
            'localidade' => null,
            'email_secundario' => null,
            'contacto' => null,
        ];

        return [
            'user_id' => $user?->id,
            'nome' => $this->firstFilled($request->customer_name, $fiscalData['nome'] ?? null, $user?->name),
            'nif' => $this->firstFilled($request->customer_tax_number, $fiscalData['nif'] ?? null),
            'email' => $this->firstFilled($request->customer_email, $user?->email, $fiscalData['email_secundario'] ?? null),
            'morada' => $this->firstFilled($request->customer_address, $fiscalData['morada'] ?? null),
            'codigo_postal' => $fiscalData['codigo_postal'] ?? null,
            'localidade' => $fiscalData['localidade'] ?? null,
            'required_fiscal_fields_detected' => [
                'customer_name',
                'customer_tax_number',
                'provider',
                'document_type',
                'document_date',
                'payment_date',
                'line_items',
            ],
            'source' => [
                'request_customer_snapshot' => filled($request->customer_name) || filled($request->customer_tax_number),
                'member_fiscal_data_resolver' => $user instanceof User,
            ],
        ];
    }

    /**
     * @param Collection<int,PaymentAllocation> $activeAllocations
     * @return array<string,mixed>
     */
    private function amountAnalysis(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $activeAllocations): array
    {
        $requestAmount = $this->money($request->amount);
        $invoiceAmount = $invoice instanceof Invoice ? $this->money($invoice->valor_total) : null;
        $allocationAmount = $activeAllocations->sum(fn (PaymentAllocation $allocation): float => (float) $allocation->amount);
        $allocationAmount = $allocationAmount > 0 ? $this->money($allocationAmount) : null;

        $matchesInvoice = $invoiceAmount !== null && abs($requestAmount - $invoiceAmount) <= self::TOLERANCE;
        $matchesAllocation = $allocationAmount !== null && abs($requestAmount - $allocationAmount) <= self::TOLERANCE;

        return [
            'fiscal_request_amount' => $requestAmount,
            'invoice_amount' => $invoiceAmount,
            'allocation_amount' => $allocationAmount,
            'amount_matches_invoice' => $matchesInvoice,
            'amount_matches_allocation' => $matchesAllocation,
            'amount_matches_invoice_or_allocation' => $matchesInvoice || $matchesAllocation,
            'amount_mismatch' => ! ($matchesInvoice || $matchesAllocation),
        ];
    }

    /**
     * @param Collection<int,PaymentAllocation> $activeAllocations
     * @return array<string,mixed>
     */
    private function grouping(FiscalDocumentRequest $request, ?Invoice $invoice, ?Payment $payment, Collection $activeAllocations): array
    {
        $provider = (string) ($request->provider ?: 'missing_provider');
        $documentType = (string) ($request->document_type ?: 'missing_document_type');
        $keyPrefix = $payment instanceof Payment ? 'payment:' . $payment->id : 'invoice:' . ($invoice?->id ?: $request->invoice_id ?: $request->id);
        $allPaymentAllocations = $payment instanceof Payment
            ? PaymentAllocation::query()->where('payment_id', $payment->id)->where('status', PaymentAllocation::STATUS_CONFIRMED)->count()
            : 0;

        return [
            'group_key' => implode('|', [$keyPrefix, $provider, $documentType]),
            'payment_is_multi_allocation' => $allPaymentAllocations > 1,
            'group_total' => $this->money($request->amount),
            'item_count_in_group' => 1,
        ];
    }

    /**
     * @param Collection<int,InvoiceItem> $items
     * @param Collection<int,PaymentAllocation> $activeAllocations
     * @param Collection<int,Payment> $activePayments
     * @param Collection<int,ReceiptImportItem> $receiptItems
     * @param array<string,mixed> $customer
     * @param array<string,mixed> $amountAnalysis
     * @return array<string,mixed>
     */
    private function readiness(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $items, Collection $activeAllocations, Collection $activePayments, Collection $receiptItems, array $customer, array $amountAnalysis): array
    {
        $blocked = [];
        $warnings = [];
        $missing = [];

        if (blank($request->provider) || ! in_array((string) $request->provider, [FiscalDocumentRequest::PROVIDER_WINTOUCH], true)) {
            $blocked[] = 'missing_or_unknown_provider';
            $missing[] = 'provider';
        }

        if (blank($request->document_type)) {
            $blocked[] = 'missing_document_type';
            $missing[] = 'document_type';
        }

        if (! $invoice instanceof Invoice || (string) $invoice->estado_pagamento !== 'pago' || $this->money($invoice->valor_pago) < $this->money($invoice->valor_total)) {
            $blocked[] = 'invoice_not_paid';
        }

        if ($activePayments->isEmpty() || $activeAllocations->isEmpty()) {
            $blocked[] = 'missing_confirmed_payment_or_allocation';
        }

        if ((bool) $amountAnalysis['amount_mismatch']) {
            $blocked[] = 'amount_mismatch';
        }

        if (blank($customer['nif'] ?? null)) {
            $blocked[] = 'missing_customer_fiscal_identity';
            $missing[] = 'customer_tax_number';
        }

        if ($items->isEmpty()) {
            $blocked[] = 'missing_line_items';
            $missing[] = 'line_items';
        }

        if ($this->hasFiscalDocumentSignal($request, $invoice, $receiptItems)) {
            $blocked[] = 'already_has_fiscal_document_signal';
        }

        if (! $this->documentDate($request, $invoice)) {
            $blocked[] = 'missing_document_date';
            $missing[] = 'document_date';
        }

        if (! $this->paymentDate($request, $invoice, $activePayments->first())) {
            $blocked[] = 'missing_payment_date';
            $missing[] = 'payment_date';
        }

        if (($customer['morada'] ?? null) === null) {
            $warnings[] = 'missing_customer_address';
        }

        return [
            'ready' => $blocked === [],
            'safe_to_issue' => $blocked === [],
            'blocked_reasons' => array_values(array_unique($blocked)),
            'warnings' => array_values(array_unique($warnings)),
            'required_fields_missing' => array_values(array_unique($missing)),
        ];
    }

    /**
     * @param Collection<int,ReceiptImportItem> $receiptItems
     */
    private function hasFiscalDocumentSignal(FiscalDocumentRequest $request, ?Invoice $invoice, Collection $receiptItems): bool
    {
        return filled($request->external_document_number)
            || filled($request->external_document_id)
            || filled($request->issued_at)
            || filled($request->handled_at)
            || ($invoice instanceof Invoice && (
                filled($invoice->numero_recibo)
                || filled($invoice->recibo_emitido_em)
                || filled($invoice->recibo_pdf_path)
                || filled($invoice->receipt_import_item_id)
            ))
            || $receiptItems->isNotEmpty();
    }

    private function fiscalRequestSnapshot(FiscalDocumentRequest $request): array
    {
        return [
            'id' => (string) $request->id,
            'provider' => $request->provider,
            'document_type' => $request->document_type,
            'status' => $request->status,
            'amount' => $this->money($request->amount),
            'paid_at' => $this->dateString($request->paid_at),
            'due_at' => $this->dateString($request->due_at),
            'created_at' => $this->dateTimeString($request->created_at),
            'metadata' => $request->metadata,
        ];
    }

    private function invoiceSnapshot(?Invoice $invoice): ?array
    {
        if (! $invoice instanceof Invoice) {
            return null;
        }

        return [
            'id' => (string) $invoice->id,
            'tipo' => $invoice->tipo,
            'origem_tipo' => $invoice->origem_tipo,
            'estado_pagamento' => $invoice->estado_pagamento,
            'valor_total' => $this->money($invoice->valor_total),
            'valor_pago' => $this->money($invoice->valor_pago),
            'data_emissao' => $this->dateString($invoice->data_emissao),
            'data_pagamento' => $this->dateString($invoice->data_pagamento),
            'numero_recibo' => $invoice->numero_recibo,
            'recibo_emitido_em' => $this->dateString($invoice->recibo_emitido_em),
        ];
    }

    private function paymentAllocationSnapshot(?Payment $payment, ?PaymentAllocation $allocation, ?BankStatement $bankStatement, ?MapaConciliacao $reconciliation): array
    {
        return [
            'payment_id' => $payment?->id,
            'payment_date' => $this->dateString($payment?->payment_date),
            'payment_amount' => $payment instanceof Payment ? $this->money($payment->amount) : null,
            'payment_status' => $payment?->status,
            'allocation_id' => $allocation?->id,
            'allocation_amount' => $allocation instanceof PaymentAllocation ? $this->money($allocation->amount) : null,
            'allocation_status' => $allocation?->status,
            'bank_statement_id' => $bankStatement?->id,
            'reconciliation_id' => $reconciliation?->id,
        ];
    }

    /**
     * @param Collection<int,InvoiceItem> $items
     * @return array<string,mixed>
     */
    private function providerPayloadPreview(FiscalDocumentRequest $request, ?Invoice $invoice, ?Payment $payment, ?PaymentAllocation $allocation, array $customer, Collection $items): array
    {
        return [
            'provider' => $request->provider,
            'document_type' => $request->document_type,
            'customer' => [
                'name' => $customer['nome'] ?? null,
                'tax_number' => $customer['nif'] ?? null,
                'email' => $customer['email'] ?? null,
                'address' => $customer['morada'] ?? null,
                'postal_code' => $customer['codigo_postal'] ?? null,
                'city' => $customer['localidade'] ?? null,
            ],
            'document_date' => $this->documentDate($request, $invoice),
            'payment_date' => $this->paymentDate($request, $invoice, $payment),
            'amount' => $this->money($request->amount),
            'line_items' => $items->map(fn (InvoiceItem $item): array => [
                'id' => (string) $item->id,
                'description' => $item->descricao,
                'quantity' => (int) $item->quantidade,
                'unit_price' => $this->money($item->valor_unitario),
                'tax_rate' => $this->money($item->imposto_percentual),
                'total' => $this->money($item->total_linha),
                'cost_center_id' => $item->centro_custo_id ? (string) $item->centro_custo_id : null,
            ])->values()->all(),
            'references' => [
                'fiscal_request_id' => (string) $request->id,
                'invoice_id' => $invoice?->id,
                'payment_id' => $payment?->id,
                'allocation_id' => $allocation?->id,
                'internal_reference' => $request->internal_reference,
            ],
            'preview_only' => true,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function groups(array $items): array
    {
        return collect($items)
            ->groupBy(fn (array $item): string => (string) data_get($item, 'grouping.group_key'))
            ->map(function (Collection $group, string $key): array {
                return [
                    'group_key' => $key,
                    'provider' => data_get($group->first(), 'fiscal_request.provider'),
                    'document_type' => data_get($group->first(), 'fiscal_request.document_type'),
                    'payment_is_multi_allocation' => $group->contains(fn (array $item): bool => (bool) data_get($item, 'grouping.payment_is_multi_allocation')),
                    'group_total' => $this->money($group->sum(fn (array $item): float => (float) data_get($item, 'fiscal_request.amount', 0))),
                    'item_count_in_group' => $group->count(),
                    'ready_count' => $group->filter(fn (array $item): bool => (bool) data_get($item, 'readiness.ready'))->count(),
                    'blocked_count' => $group->filter(fn (array $item): bool => ! (bool) data_get($item, 'readiness.ready'))->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $groups
     * @return list<array<string,mixed>>
     */
    private function hydrateItemGrouping(array $items, array $groups): array
    {
        $groupsByKey = collect($groups)->keyBy('group_key');

        return collect($items)
            ->map(function (array $item) use ($groupsByKey): array {
                $group = $groupsByKey->get((string) data_get($item, 'grouping.group_key'));
                if (is_array($group)) {
                    $item['grouping']['group_total'] = $group['group_total'];
                    $item['grouping']['item_count_in_group'] = $group['item_count_in_group'];
                    $item['grouping']['payment_is_multi_allocation'] = $group['payment_is_multi_allocation'];
                }

                return $item;
            })
            ->values()
            ->all();
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<array<string,mixed>> $groups
     * @return array<string,mixed>
     */
    private function summary(array $items, array $groups): array
    {
        $ready = array_filter($items, static fn (array $item): bool => (bool) data_get($item, 'readiness.ready'));
        $blocked = array_filter($items, static fn (array $item): bool => ! (bool) data_get($item, 'readiness.ready'));

        return [
            'total_candidates' => count($items),
            'ready_count' => count($ready),
            'blocked_count' => count($blocked),
            'warning_count' => collect($items)->sum(fn (array $item): int => count((array) data_get($item, 'readiness.warnings', []))),
            'providers_detected' => collect($items)->pluck('fiscal_request.provider')->filter()->unique()->values()->all(),
            'payment_groups_count' => count($groups),
            'total_amount_ready' => $this->money(collect($ready)->sum(fn (array $item): float => (float) data_get($item, 'fiscal_request.amount', 0))),
            'total_amount_blocked' => $this->money(collect($blocked)->sum(fn (array $item): float => (float) data_get($item, 'fiscal_request.amount', 0))),
        ];
    }

    private function activeAllocation(PaymentAllocation $allocation): bool
    {
        return (string) $allocation->status === PaymentAllocation::STATUS_CONFIRMED && $allocation->deleted_at === null;
    }

    private function activePayment(Payment $payment): bool
    {
        return (string) $payment->status === Payment::STATUS_CONFIRMED && $payment->deleted_at === null;
    }

    private function documentDate(FiscalDocumentRequest $request, ?Invoice $invoice): ?string
    {
        return $this->dateString($invoice?->data_emissao)
            ?: $this->dateString($request->created_at);
    }

    private function paymentDate(FiscalDocumentRequest $request, ?Invoice $invoice, ?Payment $payment): ?string
    {
        return $this->dateString($payment?->payment_date)
            ?: $this->dateString($invoice?->data_pagamento)
            ?: $this->dateString($request->paid_at);
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function dateString(mixed $value): ?string
    {
        return $this->dateValue($value)?->toDateString();
    }

    private function dateTimeString(mixed $value): ?string
    {
        return $this->dateValue($value)?->toDateTimeString();
    }

    private function dateValue(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    private function money(mixed $value): float
    {
        return round((float) ($value ?? 0), 2);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class LegacyMonthlyInvoiceClassificationService
{
    private const VERSION = 'a3-4-legacy-monthly-invoice-classification-v1';
    private const LEGACY_ORIGIN = 'monthly_fee_legacy';
    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly InvoiceFinancialGuardService $guardService,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function classify(array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $filters = $this->filters($options);
        $items = [];
        $appliedCount = 0;

        foreach ($this->candidateInvoices($filters) as $invoice) {
            $item = $this->classifyInvoice($invoice, $filters);

            if ($item === null) {
                continue;
            }

            if ($apply && $item['classification'] === 'safe_to_classify') {
                $item = $this->applyClassification($invoice, $item);
                $appliedCount++;
            }

            $items[] = $item;
        }

        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'mode' => $apply ? 'apply' : 'dry-run',
            'filters' => $filters,
            'summary' => $this->summary($items, $appliedCount),
            'items' => $items,
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function filters(array $options): array
    {
        return [
            'invoice' => $this->normalizeNullableString($options['invoice'] ?? null),
            'user' => $this->normalizeNullableString($options['user'] ?? null),
            'from_month' => $this->normalizeNullableString($options['from_month'] ?? null),
            'to_month' => $this->normalizeNullableString($options['to_month'] ?? null),
            'include_protected' => (bool) ($options['include_protected'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return Collection<int,Invoice>
     */
    private function candidateInvoices(array $filters): Collection
    {
        $query = Invoice::query()
            ->with(['items'])
            ->where('tipo', 'mensalidade')
            ->where('estado_pagamento', '!=', 'cancelado')
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('origem_tipo')
                    ->orWhere('origem_tipo', '')
                    ->orWhere('origem_tipo', 'manual')
                    ->orWhere('origem_tipo', self::LEGACY_ORIGIN)
                    ->orWhere('origem_tipo', '!=', 'monthly_fee');
            })
            ->orderBy('user_id')
            ->orderBy('mes')
            ->orderBy('id');

        if ($filters['invoice']) {
            $query->whereKey($filters['invoice']);
        }

        if ($filters['user']) {
            $query->where('user_id', $filters['user']);
        }

        if ($filters['from_month']) {
            $query->where('mes', '>=', $filters['from_month']);
        }

        if ($filters['to_month']) {
            $query->where('mes', '<=', $filters['to_month']);
        }

        return $query->get();
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>|null
     */
    private function classifyInvoice(Invoice $invoice, array $filters): ?array
    {
        $originBefore = $this->normalizeNullableString($invoice->origem_tipo);
        $diagnostic = $this->diagnostic($invoice);
        $findings = $diagnostic['findings'];
        $protectionReasons = $this->guardService->trailReasons($invoice);
        $classification = 'safe_to_classify';
        $recommendation = 'classify_legacy_monthly_invoice_origin_only';

        if ($originBefore === self::LEGACY_ORIGIN) {
            $classification = 'already_classified';
            $recommendation = 'no_action_needed';
        } elseif ($this->hasUnsafeFinding($findings)) {
            $classification = 'unsafe_needs_manual_review';
            $recommendation = $this->unsafeRecommendation($findings);
        } elseif ($protectionReasons !== []) {
            $classification = 'protected_legacy_monthly';
            $recommendation = 'review_protected_legacy_monthly_invoice_manually';
        } elseif (! in_array($originBefore, [null, 'manual'], true)) {
            $classification = 'unsafe_needs_manual_review';
            $recommendation = 'review_unknown_monthly_invoice_origin_before_classification';
            $findings[] = 'invoice_origin_unknown_for_safe_classification';
        } elseif (! $this->matchesSafeClassificationAmounts($diagnostic, $invoice)) {
            $classification = 'unsafe_needs_manual_review';
            $recommendation = 'review_legacy_monthly_invoice_manually_before_classification';
            $findings[] = 'safe_classification_criteria_not_met';
        }

        if ($classification === 'protected_legacy_monthly' && ! $filters['include_protected']) {
            return null;
        }

        return [
            'invoice_id' => (string) $invoice->id,
            'user_id' => $invoice->user_id ? (string) $invoice->user_id : null,
            'mes' => $invoice->mes ? (string) $invoice->mes : null,
            'estado_pagamento' => (string) $invoice->estado_pagamento,
            'valor_total' => $diagnostic['valor_total'],
            'valor_pago' => $diagnostic['valor_pago'],
            'valor_em_aberto' => $diagnostic['valor_em_aberto'],
            'origem_tipo_before' => $originBefore,
            'origem_tipo_after' => $classification === 'safe_to_classify' ? self::LEGACY_ORIGIN : $originBefore,
            'classification' => $classification,
            'protection_reasons' => $protectionReasons,
            'findings' => array_values(array_unique($findings)),
            'recommendation' => $recommendation,
            'applied' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function diagnostic(Invoice $invoice): array
    {
        $invoice->loadMissing('items');
        $valorTotal = $this->roundMoney((float) $invoice->valor_total);
        $valorPago = $this->roundMoney((float) ($invoice->valor_pago ?? 0));
        $valorEmAberto = $this->roundMoney((float) ($invoice->valor_em_aberto ?? 0));
        $itemSum = $this->roundMoney($invoice->items->sum(static fn ($item): float => (float) $item->total_linha));
        $findings = [];

        if (! $this->isValidMonth($invoice->mes)) {
            $findings[] = 'month_format_invalid';
        }

        if ($invoice->items->isEmpty()) {
            $findings[] = 'invoice_without_items';
        }

        if (abs($valorTotal - $itemSum) > self::TOLERANCE) {
            $findings[] = 'invoice_total_differs_from_items_sum';
        }

        if (abs($valorEmAberto - $this->roundMoney($valorTotal - $valorPago)) > self::TOLERANCE) {
            $findings[] = 'open_amount_inconsistent';
        }

        $fiscalRequests = FiscalDocumentRequest::withTrashed()->where('invoice_id', $invoice->id)->get();
        if ($fiscalRequests->contains(static fn (FiscalDocumentRequest $request): bool => $request->status === FiscalDocumentRequest::STATUS_PENDING)
            && $invoice->estado_pagamento !== 'pago') {
            $findings[] = 'fiscal_request_pending_for_unpaid_invoice';
        }

        return [
            'valor_total' => $valorTotal,
            'valor_pago' => $valorPago,
            'valor_em_aberto' => $valorEmAberto,
            'item_sum' => $itemSum,
            'findings' => $findings,
        ];
    }

    /**
     * @param array<string,mixed> $diagnostic
     */
    private function matchesSafeClassificationAmounts(array $diagnostic, Invoice $invoice): bool
    {
        return in_array((string) $invoice->estado_pagamento, ['pendente', 'vencido'], true)
            && abs((float) $diagnostic['valor_pago']) <= self::TOLERANCE
            && abs((float) $diagnostic['valor_em_aberto'] - (float) $diagnostic['valor_total']) <= self::TOLERANCE;
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    private function applyClassification(Invoice $invoice, array $item): array
    {
        return DB::transaction(function () use ($invoice, $item): array {
            $locked = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($this->normalizeNullableString($locked->origem_tipo) === self::LEGACY_ORIGIN) {
                $item['classification'] = 'already_classified';
                $item['origem_tipo_after'] = self::LEGACY_ORIGIN;
                $item['recommendation'] = 'no_action_needed';
                $item['applied'] = false;

                return $item;
            }

            $note = sprintf(
                '[A3.4] Classificada como mensalidade legacy canonica em %s; sem alteracao de valores/estado/pagamentos.',
                Carbon::today()->toDateString(),
            );
            $observations = trim((string) ($locked->observacoes ?? ''));

            if (! str_contains($observations, '[A3.4] Classificada como mensalidade legacy canonica')) {
                $observations = $observations === '' ? $note : $observations . PHP_EOL . $note;
            }

            $locked->forceFill([
                'origem_tipo' => self::LEGACY_ORIGIN,
                'origem_id' => null,
                'observacoes' => $observations,
            ])->save();

            $item['origem_tipo_after'] = self::LEGACY_ORIGIN;
            $item['applied'] = true;

            return $item;
        });
    }

    /**
     * @param list<string> $findings
     */
    private function hasUnsafeFinding(array $findings): bool
    {
        return $findings !== [];
    }

    /**
     * @param list<string> $findings
     */
    private function unsafeRecommendation(array $findings): string
    {
        if (in_array('fiscal_request_pending_for_unpaid_invoice', $findings, true)) {
            return 'review_pending_fiscal_request_before_deleting_or_reopening';
        }

        return 'review_legacy_monthly_invoice_manually_before_classification';
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,int>
     */
    private function summary(array $items, int $appliedCount): array
    {
        $collection = collect($items);

        return [
            'total_candidates' => $collection->count(),
            'safe_to_classify' => $collection->where('classification', 'safe_to_classify')->count(),
            'protected_legacy_monthly' => $collection->where('classification', 'protected_legacy_monthly')->count(),
            'unsafe_needs_manual_review' => $collection->where('classification', 'unsafe_needs_manual_review')->count(),
            'already_classified' => $collection->where('classification', 'already_classified')->count(),
            'applied_count' => $appliedCount,
            'skipped_count' => $collection->count() - $appliedCount,
            'warnings_count' => $collection
                ->filter(static fn (array $item): bool => in_array($item['classification'], ['protected_legacy_monthly', 'unsafe_needs_manual_review'], true))
                ->count(),
        ];
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }

    private function isValidMonth(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return false;
        }

        $month = (int) substr($value, 5, 2);

        return $month >= 1 && $month <= 12;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}

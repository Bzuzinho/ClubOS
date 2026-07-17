<?php

declare(strict_types=1);

namespace App\Services\Financeiro;

use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Services\Financeiro\FiscalProvider\FiscalDocumentProviderAdapter;
use App\Services\Financeiro\FiscalProvider\FiscalDocumentProviderResult;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

final class FiscalDocumentRequestProcessingService
{
    private const VERSION = 'a6-4-fiscal-document-request-processing-v1';

    public function __construct(
        private readonly FiscalDocumentIssuePreflightService $preflightService,
        private readonly FiscalDocumentRequestService $requestService,
        private readonly Container $container,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function process(array $options = []): array
    {
        $filters = $this->filters($options);
        $apply = (bool) ($options['apply'] ?? false);
        $confirmExternalIssue = (bool) ($options['confirm_external_issue'] ?? false);
        $dryRun = ! $apply;

        $preflight = $this->preflight($filters);
        $items = $this->initialItems($preflight['items'], $dryRun);
        $items = $this->appendExplicitSkippedIssuedItems($items, $filters, $dryRun);

        if (! $apply) {
            $exportPath = $this->exportPayloadsIfRequested($preflight['items'], $options['export_payload_path'] ?? null);

            return $this->payload($filters, $items, dryRun: true, apply: false, confirmExternalIssue: $confirmExternalIssue, exportPath: $exportPath);
        }

        if (! $confirmExternalIssue) {
            $items = $this->blockItems($items, 'missing_confirm_external_issue', 'blocked_missing_confirmation');

            return $this->payload($filters, $items, dryRun: false, apply: true, confirmExternalIssue: false, exportPath: null);
        }

        if ($this->hasBlockedItems($items)) {
            $items = $this->markReadyItemsAsSkipped($items, 'skipped_blocked_batch');

            return $this->payload($filters, $items, dryRun: false, apply: true, confirmExternalIssue: true, exportPath: null);
        }

        $adapterMap = $this->providerAdapters();
        $missingProvider = $this->firstMissingProvider($items, $adapterMap);
        if ($missingProvider !== null) {
            $items = $this->blockItems($items, 'provider_adapter_not_configured', 'blocked_provider_adapter_not_configured', $missingProvider);

            return $this->payload($filters, $items, dryRun: false, apply: true, confirmExternalIssue: true, exportPath: null);
        }

        $items = array_map(fn (array $item): array => $this->processItem($item, $adapterMap), $items);

        return $this->payload($filters, $items, dryRun: false, apply: true, confirmExternalIssue: true, exportPath: null);
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function filters(array $options): array
    {
        return [
            'fiscal_request' => $this->stringList($options['fiscal_request'] ?? []),
            'invoice' => $this->stringList($options['invoice'] ?? []),
            'payment' => $this->stringList($options['payment'] ?? []),
            'provider' => $this->stringOrNull($options['provider'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    private function preflight(array $filters): array
    {
        $payload = $this->preflightService->preflight([
            'provider' => $filters['provider'],
        ]);

        $items = collect($payload['items'] ?? [])
            ->filter(fn (array $item): bool => $this->matchesFilters($item, $filters))
            ->values()
            ->all();

        return array_merge($payload, [
            'items' => $items,
        ]);
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function matchesFilters(array $item, array $filters): bool
    {
        if ($filters['fiscal_request'] !== [] && ! in_array((string) data_get($item, 'fiscal_request.id'), $filters['fiscal_request'], true)) {
            return false;
        }

        if ($filters['invoice'] !== [] && ! in_array((string) data_get($item, 'invoice.id'), $filters['invoice'], true)) {
            return false;
        }

        if ($filters['payment'] !== [] && ! in_array((string) data_get($item, 'payment_allocation.payment_id'), $filters['payment'], true)) {
            return false;
        }

        return true;
    }

    /**
     * @param list<array<string,mixed>> $preflightItems
     * @return list<array<string,mixed>>
     */
    private function initialItems(array $preflightItems, bool $dryRun): array
    {
        return collect($preflightItems)
            ->map(fn (array $item): array => [
                'fiscal_request_id' => (string) data_get($item, 'fiscal_request.id'),
                'invoice_id' => data_get($item, 'invoice.id'),
                'user_id' => data_get($item, 'user.user_id'),
                'provider' => data_get($item, 'fiscal_request.provider'),
                'amount' => round((float) data_get($item, 'fiscal_request.amount', 0), 2),
                'ready' => (bool) data_get($item, 'readiness.ready'),
                'blocked_reasons' => (array) data_get($item, 'readiness.blocked_reasons', []),
                'action' => (bool) data_get($item, 'readiness.ready') ? ($dryRun ? 'dry_run_ready' : 'pending_process') : 'blocked_not_ready',
                'dry_run' => $dryRun,
                'processed' => false,
                'external_document_number' => null,
                'external_document_id' => null,
                'error' => null,
                'provider_payload_preview' => data_get($item, 'provider_payload_preview'),
            ])
            ->values()
            ->all();
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function appendExplicitSkippedIssuedItems(array $items, array $filters, bool $dryRun): array
    {
        $existingIds = collect($items)->pluck('fiscal_request_id')->filter()->all();
        $missingIds = array_values(array_diff($filters['fiscal_request'], $existingIds));
        if ($missingIds === []) {
            return $items;
        }

        $requests = FiscalDocumentRequest::withTrashed()
            ->whereIn('id', $missingIds)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($requests as $request) {
            $invoice = $request->invoice_id ? Invoice::query()->find($request->invoice_id) : null;
            $alreadyIssued = $request->status === FiscalDocumentRequest::STATUS_ISSUED
                || filled($request->external_document_number)
                || filled($request->external_document_id)
                || filled($request->issued_at)
                || ($invoice instanceof Invoice && (filled($invoice->numero_recibo) || filled($invoice->recibo_emitido_em)));

            if (! $alreadyIssued) {
                continue;
            }

            $items[] = [
                'fiscal_request_id' => (string) $request->id,
                'invoice_id' => $request->invoice_id ? (string) $request->invoice_id : null,
                'user_id' => $request->user_id ? (string) $request->user_id : null,
                'provider' => $request->provider,
                'amount' => round((float) $request->amount, 2),
                'ready' => false,
                'blocked_reasons' => [],
                'action' => 'skipped_already_issued',
                'dry_run' => $dryRun,
                'processed' => false,
                'external_document_number' => $request->external_document_number,
                'external_document_id' => $request->external_document_id,
                'error' => null,
            ];
        }

        return $items;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function blockItems(array $items, string $reason, string $action, ?string $provider = null): array
    {
        return array_map(static function (array $item) use ($reason, $action, $provider): array {
            if (($item['action'] ?? null) === 'skipped_already_issued') {
                return $item;
            }

            $item['ready'] = false;
            $item['blocked_reasons'] = array_values(array_unique(array_merge((array) ($item['blocked_reasons'] ?? []), [$reason])));
            $item['action'] = $action;
            $item['processed'] = false;
            $item['error'] = $provider ? sprintf('%s:%s', $reason, $provider) : $reason;

            return $item;
        }, $items);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return list<array<string,mixed>>
     */
    private function markReadyItemsAsSkipped(array $items, string $action): array
    {
        return array_map(static function (array $item) use ($action): array {
            if ((bool) ($item['ready'] ?? false)) {
                $item['action'] = $action;
                $item['processed'] = false;
            }

            return $item;
        }, $items);
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    private function hasBlockedItems(array $items): bool
    {
        return collect($items)->contains(fn (array $item): bool => ! (bool) ($item['ready'] ?? false) && ($item['action'] ?? null) !== 'skipped_already_issued');
    }

    /**
     * @param array<string,FiscalDocumentProviderAdapter> $adapterMap
     */
    private function firstMissingProvider(array $items, array $adapterMap): ?string
    {
        foreach ($items as $item) {
            if (! (bool) ($item['ready'] ?? false)) {
                continue;
            }

            $provider = (string) ($item['provider'] ?? '');
            if ($provider === '' || ! isset($adapterMap[$provider])) {
                return $provider !== '' ? $provider : 'missing_provider';
            }
        }

        return null;
    }

    /**
     * @param array<string,FiscalDocumentProviderAdapter> $adapterMap
     * @return array<string,mixed>
     */
    private function processItem(array $item, array $adapterMap): array
    {
        if (! (bool) ($item['ready'] ?? false)) {
            $item['action'] = $item['action'] ?? 'skipped_not_ready';

            return $item;
        }

        try {
            return DB::transaction(function () use ($item, $adapterMap): array {
                $request = FiscalDocumentRequest::query()
                    ->whereKey($item['fiscal_request_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($this->requestAlreadyIssued($request)) {
                    $item['ready'] = false;
                    $item['action'] = 'skipped_already_issued';
                    $item['processed'] = false;
                    $item['external_document_number'] = $request->external_document_number;
                    $item['external_document_id'] = $request->external_document_id;

                    return $item;
                }

                $provider = (string) $request->provider;
                $result = $adapterMap[$provider]->issueReceipt((array) ($item['provider_payload_preview'] ?? []));

                if (! $result->success) {
                    $item['action'] = 'provider_failed';
                    $item['processed'] = false;
                    $item['failed'] = true;
                    $item['error'] = $result->error ?: 'provider_failed';

                    return $item;
                }

                $this->markIssued($request, $result);

                $item['action'] = 'processed';
                $item['processed'] = true;
                $item['external_document_number'] = $result->externalDocumentNumber;
                $item['external_document_id'] = $result->externalDocumentId;
                $item['error'] = null;

                return $item;
            });
        } catch (Throwable $exception) {
            $item['action'] = 'failed';
            $item['processed'] = false;
            $item['failed'] = true;
            $item['error'] = $exception->getMessage();

            return $item;
        }
    }

    private function requestAlreadyIssued(FiscalDocumentRequest $request): bool
    {
        $invoice = $request->invoice_id ? Invoice::query()->find($request->invoice_id) : null;

        return $request->status === FiscalDocumentRequest::STATUS_ISSUED
            || filled($request->external_document_number)
            || filled($request->external_document_id)
            || filled($request->issued_at)
            || ($invoice instanceof Invoice && (filled($invoice->numero_recibo) || filled($invoice->recibo_emitido_em)));
    }

    private function markIssued(FiscalDocumentRequest $request, FiscalDocumentProviderResult $result): void
    {
        $metadata = array_merge((array) ($request->metadata ?? []), [
            'provider_issue_response' => $result->rawResponse,
            'processed_by' => self::VERSION,
            'processed_at' => Carbon::now()->toIso8601String(),
        ]);

        $request->forceFill([
            'metadata' => $metadata,
        ])->save();

        $this->requestService->markIssued($request, [
            'external_document_number' => $result->externalDocumentNumber,
            'external_document_id' => $result->externalDocumentId,
            'external_document_url' => $result->externalDocumentUrl,
            'external_series' => $result->externalSeries,
            'issued_at' => $result->issuedAt ?: Carbon::now()->toDateTimeString(),
            'notes' => $request->notes,
        ]);
    }

    /**
     * @return array<string,FiscalDocumentProviderAdapter>
     */
    private function providerAdapters(): array
    {
        $adapters = [];

        if ($this->container->bound(FiscalDocumentProviderAdapter::class)) {
            $adapter = $this->container->make(FiscalDocumentProviderAdapter::class);
            if ($adapter instanceof FiscalDocumentProviderAdapter) {
                $adapters[$adapter->provider()] = $adapter;
            }
        }

        foreach ($this->container->tagged('financeiro.fiscal_document_provider_adapters') as $adapter) {
            if ($adapter instanceof FiscalDocumentProviderAdapter) {
                $adapters[$adapter->provider()] = $adapter;
            }
        }

        return $adapters;
    }

    /**
     * @param list<array<string,mixed>> $preflightItems
     */
    private function exportPayloadsIfRequested(array $preflightItems, mixed $path): ?string
    {
        $path = $this->stringOrNull($path);
        if ($path === null) {
            return null;
        }

        $exportPath = str_starts_with($path, '/')
            ? $path
            : base_path($path);

        $payload = [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'payloads' => collect($preflightItems)
                ->filter(fn (array $item): bool => (bool) data_get($item, 'readiness.ready'))
                ->map(fn (array $item): array => (array) data_get($item, 'provider_payload_preview', []))
                ->values()
                ->all(),
            'read_only_export' => true,
        ];

        File::ensureDirectoryExists(dirname($exportPath));
        File::put($exportPath, $this->toJson($payload));

        return $exportPath;
    }

    /**
     * @param array<string,mixed> $filters
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function payload(array $filters, array $items, bool $dryRun, bool $apply, bool $confirmExternalIssue, ?string $exportPath): array
    {
        return [
            'version' => self::VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'dry_run' => $dryRun,
            'apply' => $apply,
            'confirm_external_issue' => $confirmExternalIssue,
            'filters' => $filters,
            'summary' => $this->summary($items, $exportPath),
            'items' => array_values(array_map(static function (array $item): array {
                unset($item['provider_payload_preview'], $item['failed']);

                return $item;
            }, $items)),
            'export_path' => $exportPath,
            'read_only_when_dry_run' => $dryRun,
        ];
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private function summary(array $items, ?string $exportPath): array
    {
        $ready = collect($items)->filter(fn (array $item): bool => (bool) ($item['ready'] ?? false));
        $blocked = collect($items)->filter(fn (array $item): bool => ! (bool) ($item['ready'] ?? false) && ! str_starts_with((string) ($item['action'] ?? ''), 'skipped'));
        $processed = collect($items)->filter(fn (array $item): bool => (bool) ($item['processed'] ?? false));

        return [
            'total_candidates' => count($items),
            'ready_count' => $ready->count(),
            'blocked_count' => $blocked->count(),
            'processed_count' => $processed->count(),
            'skipped_count' => collect($items)->filter(fn (array $item): bool => str_starts_with((string) ($item['action'] ?? ''), 'skipped'))->count(),
            'failed_count' => collect($items)->filter(fn (array $item): bool => (bool) ($item['failed'] ?? false))->count(),
            'exported_count' => $exportPath ? $ready->count() : 0,
            'total_amount_ready' => round((float) $ready->sum(fn (array $item): float => (float) ($item['amount'] ?? 0)), 2),
            'total_amount_processed' => round((float) $processed->sum(fn (array $item): float => (float) ($item['amount'] ?? 0)), 2),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [$value])
            ->filter(static fn (mixed $item): bool => is_string($item) && trim($item) !== '')
            ->map(static fn (string $item): string => trim($item))
            ->values()
            ->all();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function toJson(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Models\ReceiptImportBatch;
use App\Models\ReceiptImportItem;
use App\Services\Financeiro\ReceiptCommitService;
use App\Services\Financeiro\ReceiptImportService;
use App\Services\Financeiro\ReceiptMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ReceiptImportController extends Controller
{
    public function __construct(
        private readonly ReceiptImportService $receiptImportService,
        private readonly ReceiptMatchingService $receiptMatchingService,
        private readonly ReceiptCommitService $receiptCommitService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ReceiptImportBatch::class);

        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'batch_id' => ['nullable', 'uuid', 'exists:receipt_import_batches,id'],
        ]);

        $query = ReceiptImportBatch::query()
            ->with([
                'creator:id,nome_completo,name',
                'committer:id,nome_completo,name',
                'items.user:id,nome_completo,numero_socio,nif',
                'items.invoice:id,user_id,mes,tipo,valor_total,valor_em_aberto,estado_pagamento,numero_recibo',
                'items.bankStatement:id,data_movimento,descricao,valor,valor_conciliado,valor_por_conciliar,conciliacao_status,conta',
            ])
            ->when(!empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->orderByDesc('created_at');

        if (!empty($data['batch_id'])) {
            $query->whereKey($data['batch_id']);
        } else {
            $query->limit(10);
        }

        $batches = $query->get()->map(fn (ReceiptImportBatch $batch) => $this->serializeBatch($batch));

        return response()->json([
            'batches' => $batches,
            'latest_batch_id' => $batches->first()['id'] ?? null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ReceiptImportBatch::class);

        $data = $request->validate([
            'zip_file' => ['nullable', 'file', 'mimetypes:application/zip,application/x-zip-compressed'],
            'use_pending_directory' => ['nullable', 'boolean'],
            'pending_directory' => ['nullable', 'string', 'max:255'],
        ]);

        $actor = $request->user();
        $usePendingDirectory = (bool) ($data['use_pending_directory'] ?? false);
        $zipFile = $request->file('zip_file');

        if (! $usePendingDirectory && $zipFile === null) {
            throw ValidationException::withMessages([
                'zip_file' => 'Envie um ficheiro ZIP ou ative a importacao pela diretoria pendente.',
            ]);
        }

        if ($usePendingDirectory) {
            $batch = $this->receiptImportService->createBatchFromPendingDirectory(
                $data['pending_directory'] ?? null,
                $actor,
            );
        } else {
            $batch = $this->receiptImportService->createBatchFromZip(
                $zipFile,
                $actor,
            );
        }

        return response()->json([
            'batch' => $this->serializeBatch($batch),
        ], 201);
    }

    public function updateItem(Request $request, ReceiptImportItem $item): JsonResponse
    {
        $this->authorize('update', $item);

        $data = $request->validate([
            'user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'invoice_id' => ['nullable', 'uuid', 'exists:invoices,id'],
            'bank_statement_id' => ['nullable', 'uuid', 'exists:bank_statements,id'],
            'numero_recibo' => ['nullable', 'string', 'max:255'],
            'recibo_emitido_em' => ['nullable', 'date'],
            'rematch' => ['nullable', 'boolean'],
        ]);

        $item = !empty($data['rematch'])
            ? $this->receiptMatchingService->rematchItem($item, $data)
            : $this->receiptMatchingService->rematchItem($item, $data);

        return response()->json([
            'item' => $this->serializeItem($item->fresh(['user', 'invoice', 'bankStatement'])),
        ]);
    }

    public function commit(Request $request, ReceiptImportBatch $batch): JsonResponse
    {
        $this->authorize('commit', $batch);

        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['uuid', 'exists:receipt_import_items,id'],
        ]);

        $batch = $this->receiptCommitService->commitItems($batch, $data['item_ids'], $request->user());

        return response()->json([
            'batch' => $this->serializeBatch($batch),
        ]);
    }

    public function preview(Request $request, ReceiptImportItem $item)
    {
        $this->authorize('view', $item);

        abort_unless(Storage::disk('local')->exists($item->storage_path), 404);

        $absolutePath = storage_path('app/'.$item->storage_path);
        $headers = ['Content-Type' => 'application/pdf'];

        if ($request->boolean('download')) {
            return response()->download($absolutePath, $item->file_name, $headers);
        }

        return response()->file($absolutePath, $headers);
    }

    private function serializeBatch(ReceiptImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'source_type' => $batch->source_type,
            'source_name' => $batch->source_name,
            'source_path' => $batch->source_path,
            'status' => $batch->status,
            'items_count' => (int) $batch->items_count,
            'processed_count' => (int) $batch->processed_count,
            'imported_count' => (int) $batch->imported_count,
            'notes' => $batch->notes,
            'created_at' => optional($batch->created_at)?->toIso8601String(),
            'committed_at' => optional($batch->committed_at)?->toIso8601String(),
            'creator' => $batch->creator ? [
                'id' => $batch->creator->id,
                'nome_completo' => $batch->creator->nome_completo ?? $batch->creator->name,
            ] : null,
            'committer' => $batch->committer ? [
                'id' => $batch->committer->id,
                'nome_completo' => $batch->committer->nome_completo ?? $batch->committer->name,
            ] : null,
            'items' => $batch->items->map(fn (ReceiptImportItem $item) => $this->serializeItem($item))->values()->all(),
        ];
    }

    private function serializeItem(ReceiptImportItem $item): array
    {
        $ready = $item->user_id && $item->invoice_id && $item->bank_statement_id && $item->numero_recibo
            && !in_array($item->status, [ReceiptImportItem::STATUS_DUPLICATE, ReceiptImportItem::STATUS_FAILED, ReceiptImportItem::STATUS_IMPORTED], true);

        return [
            'id' => $item->id,
            'batch_id' => $item->batch_id,
            'user_id' => $item->user_id,
            'invoice_id' => $item->invoice_id,
            'bank_statement_id' => $item->bank_statement_id,
            'status' => $item->status,
            'display_status' => $item->status === ReceiptImportItem::STATUS_IMPORTED ? 'imported' : ($ready ? 'ready' : $item->status),
            'confidence_score' => (float) $item->confidence_score,
            'file_name' => $item->file_name,
            'storage_path' => $item->storage_path,
            'numero_recibo' => $item->numero_recibo,
            'recibo_emitido_em' => optional($item->recibo_emitido_em)?->toDateString(),
            'valor' => $item->valor !== null ? (float) $item->valor : null,
            'extracted_name' => $item->extracted_name,
            'extracted_nif' => $item->extracted_nif,
            'extracted_member_number' => $item->extracted_member_number,
            'extracted_email' => $item->extracted_email,
            'extracted_period_label' => $item->extracted_period_label,
            'match_candidates' => $item->match_candidates,
            'failure_reason' => $item->failure_reason,
            'is_ready' => $ready,
            'preview_url' => route('financeiro.receipt-imports.items.preview', $item),
            'user' => $item->user ? [
                'id' => $item->user->id,
                'nome_completo' => $item->user->nome_completo,
                'numero_socio' => $item->user->numero_socio,
                'nif' => $item->user->nif,
            ] : null,
            'invoice' => $item->invoice ? [
                'id' => $item->invoice->id,
                'tipo' => $item->invoice->tipo,
                'mes' => $item->invoice->mes,
                'valor_total' => (float) $item->invoice->valor_total,
                'valor_em_aberto' => (float) ($item->invoice->valor_em_aberto ?? $item->invoice->valor_total),
                'estado_pagamento' => $item->invoice->estado_pagamento,
                'numero_recibo' => $item->invoice->numero_recibo,
            ] : null,
            'bank_statement' => $item->bankStatement ? [
                'id' => $item->bankStatement->id,
                'data_movimento' => optional($item->bankStatement->data_movimento)?->toDateString(),
                'descricao' => $item->bankStatement->descricao,
                'valor' => (float) $item->bankStatement->valor,
                'conta' => $item->bankStatement->conta,
                'valor_conciliado' => (float) ($item->bankStatement->valor_conciliado ?? 0),
                'valor_por_conciliar' => (float) ($item->bankStatement->valor_por_conciliar ?? abs((float) $item->bankStatement->valor)),
                'conciliacao_status' => $item->bankStatement->conciliacao_status,
            ] : null,
        ];
    }
}
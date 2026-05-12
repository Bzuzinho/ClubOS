<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Services\Financeiro\FiscalDocumentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class FiscalDocumentRequestController extends Controller
{
    public function __construct(
        private readonly FiscalDocumentRequestService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(FiscalDocumentRequest::STATUSES)],
            'provider' => ['nullable', 'string', 'max:50'],
            'document_type' => ['nullable', Rule::in(FiscalDocumentRequest::DOCUMENT_TYPES)],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'overdue' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'string'],
            'invoice_id' => ['nullable', 'string'],
        ]);

        $query = FiscalDocumentRequest::query()
            ->with([
                'invoice:id,user_id,valor_total,estado_pagamento,numero_recibo,referencia_pagamento',
                'user:id,name,nome_completo,email,nif,morada,codigo_postal,localidade',
                'bankStatement:id,data_movimento,descricao,referencia',
                'mapaConciliacao:id,extrato_id,lancamento_id,fatura_id,movimento_id,valor_conciliado',
            ])
            ->latest('created_at');

        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (!empty($validated['provider'])) {
            $query->forProvider($validated['provider']);
        }

        if (!empty($validated['document_type'])) {
            $query->where('document_type', $validated['document_type']);
        }

        if (!empty($validated['overdue'])) {
            $query->overdue();
        }

        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (!empty($validated['invoice_id'])) {
            $query->where('invoice_id', $validated['invoice_id']);
        }

        if (!empty($validated['search'])) {
            $search = trim($validated['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

                $builder
                    ->where('customer_name', 'like', $like)
                    ->orWhere('customer_tax_number', 'like', $like)
                    ->orWhere('internal_reference', 'like', $like)
                    ->orWhere('external_document_number', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhereHas('user', function (Builder $userQuery) use ($like): void {
                        $userQuery
                            ->where('name', 'like', $like)
                            ->orWhere('nome_completo', 'like', $like)
                            ->orWhere('nif', 'like', $like)
                            ->orWhere('email', 'like', $like);
                    });
            });
        }

        $perPage = (int) ($validated['per_page'] ?? 50);
        $results = $query->paginate($perPage)->withQueryString();

        return response()->json($results);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->storeRules());
        $validated['created_by'] = $request->user()?->id;

        if (empty($validated['invoice_id'])) {
            return response()->json([
                'message' => 'A criacao manual direta de pedidos fiscais fora do fluxo canonico nao esta disponivel.',
            ], 422);
        }

        $fiscalRequest = $this->service->createFromInvoice(
            Invoice::query()->findOrFail($validated['invoice_id']),
            $validated,
        );

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'data' => $fiscalRequest->load(['invoice', 'user', 'bankStatement']),
        ], 201);
    }

    public function createFromInvoice(Request $request, Invoice $invoice): JsonResponse
    {
        if ($invoice->estado_pagamento !== 'pago') {
            return response()->json([
                'message' => 'So e possivel criar pedido fiscal para faturas pagas.',
            ], 422);
        }

        $existingRequest = $this->service->findActiveForInvoice($invoice);

        if ($existingRequest) {
            return response()->json([
                'message' => 'Ja existe pedido fiscal para esta fatura.',
                'data' => $existingRequest->load(['invoice', 'user', 'bankStatement']),
            ]);
        }

        $fiscalRequest = $this->service->createFromInvoice($invoice, [
            'created_by' => $request->user()?->id,
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'message' => 'Pedido fiscal criado com sucesso.',
            'data' => $fiscalRequest->load(['invoice', 'user', 'bankStatement']),
        ], 201);
    }

    public function update(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $validated = $request->validate($this->updateRules());
        $fiscalDocumentRequest->fill($validated);
        $fiscalDocumentRequest->save();

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'data' => $fiscalDocumentRequest->refresh()->load(['invoice', 'user', 'bankStatement']),
        ]);
    }

    public function markInProgress(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $updatedRequest = $this->service->markInProgress($fiscalDocumentRequest, $request->user()?->id);
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'data' => $updatedRequest,
        ]);
    }

    public function markIssued(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $validated = $request->validate([
            'external_document_number' => ['required', 'string', 'max:100'],
            'document_type' => ['nullable', Rule::in(FiscalDocumentRequest::DOCUMENT_TYPES)],
            'issued_at' => ['nullable', 'date'],
            'external_document_id' => ['nullable', 'string'],
            'external_document_url' => ['nullable', 'string'],
            'external_series' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $updatedRequest = $this->service->markIssued($fiscalDocumentRequest, $validated, $request->user()?->id);
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'data' => $updatedRequest,
        ]);
    }

    public function markCancelled(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $updatedRequest = $this->service->markCancelled($fiscalDocumentRequest, $validated['reason'], $request->user()?->id);
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'data' => $updatedRequest,
        ]);
    }

    public function markErrorData(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $validated = $request->validate([
            'last_error' => ['nullable', 'required_without:error', 'string'],
            'error' => ['nullable', 'required_without:last_error', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $error = $validated['last_error'] ?? $validated['error'];

        $updatedRequest = $this->service->markErrorData($fiscalDocumentRequest, $error, $validated['notes'] ?? null, $request->user()?->id);
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'data' => $updatedRequest,
        ]);
    }

    public function destroy(FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $this->service->deleteRequest($fiscalDocumentRequest);
        $this->invalidateFinanceiroCaches();

        return response()->json([], 204);
    }

    private function invalidateFinanceiroCaches(): void
    {
        Cache::forget('financeiro:index');
        Cache::forget('financeiro:fiscal_requests');
        Cache::forget('financeiro:faturas');
    }

    private function storeRules(): array
    {
        return [
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'bank_statement_id' => ['nullable', 'exists:bank_statements,id'],
            'mapa_conciliacao_id' => ['nullable', 'exists:mapa_conciliacao,id'],
            'financial_entry_id' => ['nullable', 'exists:financial_entries,id'],
            'provider' => ['required', 'string', 'max:50'],
            'document_type' => ['required', Rule::in(FiscalDocumentRequest::DOCUMENT_TYPES)],
            'status' => ['nullable', Rule::in(FiscalDocumentRequest::STATUSES)],
            'priority' => ['nullable', Rule::in(FiscalDocumentRequest::PRIORITIES)],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'paid_at' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_tax_number' => ['nullable', 'string', 'max:50'],
            'customer_email' => ['nullable', 'email'],
            'customer_address' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'internal_reference' => ['nullable', 'string', 'max:255'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'external_document_number' => ['nullable', 'string', 'max:100'],
            'external_document_id' => ['nullable', 'string'],
            'external_document_url' => ['nullable', 'string'],
            'external_series' => ['nullable', 'string', 'max:100'],
            'issued_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    private function updateRules(): array
    {
        return [
            'invoice_id' => ['sometimes', 'nullable', 'exists:invoices,id'],
            'user_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'bank_statement_id' => ['sometimes', 'nullable', 'exists:bank_statements,id'],
            'mapa_conciliacao_id' => ['sometimes', 'nullable', 'exists:mapa_conciliacao,id'],
            'financial_entry_id' => ['sometimes', 'nullable', 'exists:financial_entries,id'],
            'provider' => ['sometimes', 'required', 'string', 'max:50'],
            'document_type' => ['sometimes', 'required', Rule::in(FiscalDocumentRequest::DOCUMENT_TYPES)],
            'status' => ['sometimes', 'nullable', Rule::in(FiscalDocumentRequest::STATUSES)],
            'priority' => ['sometimes', 'nullable', Rule::in(FiscalDocumentRequest::PRIORITIES)],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'paid_at' => ['sometimes', 'nullable', 'date'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_tax_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer_email' => ['sometimes', 'nullable', 'email'],
            'customer_address' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'internal_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cost_center_id' => ['sometimes', 'nullable', 'exists:cost_centers,id'],
            'external_document_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'external_document_id' => ['sometimes', 'nullable', 'string'],
            'external_document_url' => ['sometimes', 'nullable', 'string'],
            'external_series' => ['sometimes', 'nullable', 'string', 'max:100'],
            'issued_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
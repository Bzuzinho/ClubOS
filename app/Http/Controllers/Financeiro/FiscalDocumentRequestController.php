<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Services\Financeiro\FiscalDocumentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FiscalDocumentRequestController extends Controller
{
    public function __construct(
        private readonly FiscalDocumentRequestService $service,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = FiscalDocumentRequest::query()
            ->with([
                'invoice:id,user_id,valor_total,estado_pagamento',
                'user:id,name,nome_completo,email,nif',
                'bankStatement:id,data_movimento,descricao,referencia',
            ])
            ->latest('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('provider')) {
            $query->forProvider($request->string('provider')->value());
        }

        if ($request->filled('document_type')) {
            $query->where('document_type', $request->string('document_type')->value());
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->string('user_id')->value());
        }

        if ($request->filled('invoice_id')) {
            $query->where('invoice_id', $request->string('invoice_id')->value());
        }

        return response()->json([
            'data' => $query->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->storeRules());
        $validated['created_by'] = $request->user()?->id;

        if (!empty($validated['invoice_id'])) {
            $fiscalRequest = $this->service->createFromInvoice(
                Invoice::query()->findOrFail($validated['invoice_id']),
                $validated,
            );
        } else {
            $fiscalRequest = FiscalDocumentRequest::create($validated);
        }

        return response()->json([
            'data' => $fiscalRequest->load(['invoice', 'user', 'bankStatement']),
        ], 201);
    }

    public function update(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $validated = $request->validate($this->updateRules());
        $fiscalDocumentRequest->fill($validated);
        $fiscalDocumentRequest->save();

        return response()->json([
            'data' => $fiscalDocumentRequest->refresh()->load(['invoice', 'user', 'bankStatement']),
        ]);
    }

    public function markInProgress(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        return response()->json([
            'data' => $this->service->markInProgress($fiscalDocumentRequest, $request->user()?->id),
        ]);
    }

    public function markIssued(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $validated = $request->validate([
            'external_document_number' => ['required', 'string', 'max:100'],
            'document_type' => ['required', Rule::in(FiscalDocumentRequest::DOCUMENT_TYPES)],
            'issued_at' => ['nullable', 'date'],
            'external_document_id' => ['nullable', 'string'],
            'external_document_url' => ['nullable', 'string'],
            'external_series' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json([
            'data' => $this->service->markIssued($fiscalDocumentRequest, $validated, $request->user()?->id),
        ]);
    }

    public function markCancelled(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        return response()->json([
            'data' => $this->service->markCancelled($fiscalDocumentRequest, $validated['reason'] ?? null, $request->user()?->id),
        ]);
    }

    public function markErrorData(Request $request, FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $validated = $request->validate([
            'error' => ['required', 'string'],
        ]);

        return response()->json([
            'data' => $this->service->markErrorData($fiscalDocumentRequest, $validated['error'], $request->user()?->id),
        ]);
    }

    public function destroy(FiscalDocumentRequest $fiscalDocumentRequest): JsonResponse
    {
        $fiscalDocumentRequest->delete();

        return response()->json([], 204);
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
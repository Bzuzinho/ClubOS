<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\BankReconciliationAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankReconciliationAuditController extends Controller
{
    public function __construct(
        private readonly BankReconciliationAuditService $auditService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'estado' => ['nullable', Rule::in([
                BankReconciliationAuditService::STATE_ALL,
                BankReconciliationAuditService::STATE_UNRECONCILED,
                BankReconciliationAuditService::STATE_PARTIAL,
                BankReconciliationAuditService::STATE_RECONCILED,
            ])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'exists:users,id'],
            'family_id' => ['nullable', 'exists:familias,id'],
            'metodo' => ['nullable', Rule::in([
                BankReconciliationAuditService::METHOD_AUTOMATIC_SUGGESTION,
                BankReconciliationAuditService::METHOD_ASSISTED_ALLOCATION,
                BankReconciliationAuditService::METHOD_EXPENSE_FROM_STATEMENT,
                BankReconciliationAuditService::METHOD_MANUAL_PAYMENT,
                BankReconciliationAuditService::METHOD_OTHER,
            ])],
            'has_credit' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:200'],
            'sort_by' => ['nullable', Rule::in(['data_movimento', 'valor'])],
            'sort_direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $result = $this->auditService->paginate($data);

        return response()->json([
            'rows' => $result['rows'],
            'data' => $result['rows'],
            'meta' => $result['meta'],
            'summary' => $result['summary'],
            'filters' => [
                'estado' => $data['estado'] ?? BankReconciliationAuditService::STATE_ALL,
                'date_from' => $data['date_from'] ?? null,
                'date_to' => $data['date_to'] ?? null,
                'search' => $data['search'] ?? '',
                'user_id' => $data['user_id'] ?? null,
                'family_id' => $data['family_id'] ?? null,
                'metodo' => $data['metodo'] ?? null,
                'has_credit' => $data['has_credit'] ?? null,
                'sort_by' => $data['sort_by'] ?? 'data_movimento',
                'sort_direction' => $data['sort_direction'] ?? 'desc',
            ],
        ]);
    }
}

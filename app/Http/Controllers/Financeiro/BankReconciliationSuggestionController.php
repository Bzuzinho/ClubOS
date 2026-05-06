<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Financeiro\BankReconciliationSuggestionService;
use App\Services\Financeiro\PaymentAllocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BankReconciliationSuggestionController extends Controller
{
    public function __construct(
        private readonly BankReconciliationSuggestionService $suggestionService,
        private readonly PaymentAllocationService $paymentAllocationService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_statement_id' => ['nullable', 'exists:bank_statements,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'family_id' => ['nullable', 'exists:familias,id'],
            'status' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 25);
        $search = trim((string) ($data['search'] ?? ''));
        $operator = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $paginator = BankReconciliationSuggestion::query()
            ->with([
                'bankStatement:id,data_movimento,descricao,referencia,valor,conta,conciliado,valor_conciliado,valor_por_conciliar,conciliacao_status',
                'user:id,nome_completo,name,numero_socio,nif',
                'family:id,nome',
                'confirmedBy:id,nome_completo',
                'rejectedBy:id,nome_completo',
            ])
            ->when(!empty($data['bank_statement_id']), fn ($query) => $query->where('bank_statement_id', $data['bank_statement_id']))
            ->when(!empty($data['user_id']), fn ($query) => $query->where('user_id', $data['user_id']))
            ->when(!empty($data['family_id']), fn ($query) => $query->where('family_id', $data['family_id']))
            ->when(!empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when($search !== '', function ($query) use ($search, $operator) {
                $query->where(function ($nestedQuery) use ($search): void {
                    $nestedQuery
                        ->where('explanation', $operator, "%{$search}%")
                        ->orWhereHas('bankStatement', function ($statementQuery) use ($search, $operator): void {
                            $statementQuery
                                ->where('descricao', $operator, "%{$search}%")
                                ->orWhere('referencia', $operator, "%{$search}%");
                        })
                        ->orWhereHas('user', function ($userQuery) use ($search, $operator): void {
                            $userQuery
                                ->where('nome_completo', $operator, "%{$search}%")
                                ->orWhere('name', $operator, "%{$search}%")
                                ->orWhere('numero_socio', $operator, "%{$search}%");
                        });
                });
            })
            ->orderByDesc('score')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json($paginator);
    }

    public function generateForBankStatement(BankStatement $bankStatement): JsonResponse
    {
        $suggestions = $this->suggestionService->generateForBankStatement($bankStatement);

        return response()->json([
            'suggestions' => $suggestions->values(),
            'generated_count' => $suggestions->count(),
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'account' => ['nullable', 'string', 'max:255'],
            'min_amount' => ['nullable', 'numeric'],
            'max_amount' => ['nullable', 'numeric'],
        ]);

        $generatedCount = $this->suggestionService->generateForUnreconciled($data);

        return response()->json([
            'generated_count' => $generatedCount,
        ]);
    }

    public function confirm(Request $request, BankReconciliationSuggestion $suggestion): JsonResponse
    {
        $data = $request->validate([
            'create_credit' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        $invoiceIds = collect((array) $suggestion->suggested_allocations)
            ->pluck('invoice_id')
            ->filter()
            ->unique()
            ->values();
        $fiscalRequestsBefore = DB::table('fiscal_document_requests')
            ->whereIn('invoice_id', $invoiceIds)
            ->count();

        $payment = $this->suggestionService->confirmSuggestion($suggestion, $request->user(), [
            'create_credit' => (bool) ($data['create_credit'] ?? false),
            'notes' => $data['notes'] ?? null,
        ]);

        return $this->paymentResponse($payment, $invoiceIds->all(), $fiscalRequestsBefore, [
            'suggestion_confirmed' => true,
        ]);
    }

    public function reject(Request $request, BankReconciliationSuggestion $suggestion): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string'],
        ]);

        $this->suggestionService->rejectSuggestion($suggestion, $request->user(), $data['reason'] ?? null);

        return response()->json([
            'suggestion' => $suggestion->fresh(['bankStatement', 'user', 'family']),
        ]);
    }

    public function allocate(Request $request, BankStatement $bankStatement): JsonResponse
    {
        $data = $request->validate([
            'create_credit' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.invoice_id' => ['required', 'exists:invoices,id'],
            'allocations.*.amount' => ['required', 'numeric', 'gt:0'],
            'allocations.*.notes' => ['nullable', 'string'],
        ]);

        $allocations = collect($data['allocations'])
            ->map(fn (array $allocation) => [
                'invoice_id' => $allocation['invoice_id'],
                'amount' => round(abs((float) $allocation['amount']), 2),
                'notes' => $allocation['notes'] ?? null,
            ])
            ->values()
            ->all();

        $invoiceIds = collect($allocations)->pluck('invoice_id')->unique()->values();
        $fiscalRequestsBefore = DB::table('fiscal_document_requests')
            ->whereIn('invoice_id', $invoiceIds)
            ->count();

        $payment = $this->paymentAllocationService->createFromBankStatement($bankStatement, $allocations, [
            'method' => 'transferencia',
            'reference' => $bankStatement->referencia,
            'description' => $bankStatement->descricao,
            'notes' => $data['notes'] ?? null,
            'create_credit' => (bool) ($data['create_credit'] ?? false),
            'created_by' => $request->user()?->id,
            'source' => Payment::SOURCE_RECONCILIATION,
            'map_rule' => 'manual_bank_allocation',
            'map_metadata' => [
                'manual_allocation' => true,
            ],
        ]);

        return $this->paymentResponse($payment, $invoiceIds->all(), $fiscalRequestsBefore, [
            'manual_allocation' => true,
        ]);
    }

    private function paymentResponse(Payment $payment, array $invoiceIds, int $fiscalRequestsBefore, array $summary = []): JsonResponse
    {
        $updatedInvoices = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->orderBy('data_emissao', 'desc')
            ->get();
        $updatedBankStatement = $payment->bankStatement?->fresh();
        $activeFiscalRequests = DB::table('fiscal_document_requests')
            ->whereIn('invoice_id', $invoiceIds)
            ->whereIn('status', ['pending', 'in_progress', 'issued', 'error_data', 'api_error'])
            ->count();

        return response()->json([
            'payment' => $payment->load(['allocations.invoice', 'credits', 'bankStatement']),
            'invoices' => $updatedInvoices,
            'bank_statement' => $updatedBankStatement,
            'summary' => array_merge([
                'all_paid' => $updatedInvoices->isNotEmpty() && $updatedInvoices->every(fn (Invoice $invoice) => $invoice->estado_pagamento === 'pago'),
                'has_partial_invoice' => $updatedInvoices->contains(fn (Invoice $invoice) => $invoice->estado_pagamento === 'parcial'),
                'created_credit' => $payment->credits()->exists(),
                'bank_statement_reconciled' => $updatedBankStatement?->conciliado ?? false,
                'bank_statement_partial' => ($updatedBankStatement?->conciliacao_status ?? null) === 'partial',
                'active_fiscal_requests' => $activeFiscalRequests,
                'new_fiscal_requests' => max($activeFiscalRequests - $fiscalRequestsBefore, 0),
                'affected_invoice_count' => $updatedInvoices->count(),
            ], $summary),
        ]);
    }
}
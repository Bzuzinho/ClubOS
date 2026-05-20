<?php

namespace App\Http\Controllers\Financeiro;

use App\Http\Controllers\Controller;
use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\Payment;
use App\Services\Financeiro\BankReconciliationSuggestionService;
use App\Services\Financeiro\FinancialSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankReconciliationSuggestionController extends Controller
{
    public function __construct(
        private readonly BankReconciliationSuggestionService $suggestionService,
        private readonly FinancialSettlementService $financialSettlementService,
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
        $operator = $this->searchOperator();

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
                $query->where(function ($nestedQuery) use ($search, $operator): void {
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

    private function searchOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    public function generateForBankStatement(Request $request, BankStatement $bankStatement): JsonResponse
    {
        $data = $request->validate([
            'force_regeneration' => ['nullable', 'boolean'],
        ]);

        $suggestions = $this->suggestionService->generateForBankStatement($bankStatement, [
            'force_regeneration' => (bool) ($data['force_regeneration'] ?? false),
        ]);

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

        $query = BankStatement::query()
            ->where(function ($nestedQuery) {
                $nestedQuery
                    ->where('conciliado', false)
                    ->orWhere('conciliacao_status', 'partial')
                    ->orWhereNull('conciliacao_status');
            })
            ->whereDoesntHave('suggestions', function ($suggestionQuery) {
                $suggestionQuery
                    ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
                    ->where('score', '>', 0);
            });

        if (!empty($data['date_from'])) {
            $query->whereDate('data_movimento', '>=', $data['date_from']);
        }

        if (!empty($data['date_to'])) {
            $query->whereDate('data_movimento', '<=', $data['date_to']);
        }

        if (!empty($data['account'])) {
            $query->where('conta', $data['account']);
        }

        if (isset($data['min_amount'])) {
            $query->whereRaw('ABS(valor) >= ?', [abs((float) $data['min_amount'])]);
        }

        if (isset($data['max_amount'])) {
            $query->whereRaw('ABS(valor) <= ?', [abs((float) $data['max_amount'])]);
        }

        $summary = [
            'analyzed_count' => 0,
            'suggestions_created' => 0,
            'high_confidence_count' => 0,
            'unmatched_count' => 0,
            'errors' => 0,
        ];

        $query->orderByDesc('data_movimento')
            ->chunkById(100, function ($statements) use (&$summary): void {
                foreach ($statements as $statement) {
                    $summary['analyzed_count']++;

                    try {
                        $suggestions = $this->suggestionService->generateForBankStatement($statement);
                        $summary['suggestions_created'] += $suggestions->count();
                        $summary['high_confidence_count'] += $suggestions
                            ->filter(fn (BankReconciliationSuggestion $suggestion) => in_array($suggestion->confidence_label, [
                                BankReconciliationSuggestion::CONFIDENCE_VERY_HIGH,
                                BankReconciliationSuggestion::CONFIDENCE_HIGH,
                            ], true))
                            ->count();

                        if ($suggestions->isEmpty()) {
                            $summary['unmatched_count']++;
                        }
                    } catch (\Throwable $exception) {
                        $summary['errors']++;
                    }
                }
            });

        return response()->json([
            'generated_count' => $summary['suggestions_created'],
            'summary' => $summary,
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
            'credit_user_id' => ['nullable', 'exists:users,id'],
            'credit_family_id' => ['nullable', 'exists:familias,id'],
            'invoices' => ['nullable', 'array'],
            'invoices.*.invoice_id' => ['required', 'exists:invoices,id'],
            'invoices.*.amount' => ['required', 'numeric', 'gt:0'],
            'invoices.*.notes' => ['nullable', 'string'],
            'movements' => ['nullable', 'array'],
            'movements.*.movement_id' => ['required', 'exists:movements,id'],
            'movements.*.amount' => ['required', 'numeric', 'gt:0'],
            'movements.*.centro_custo_id' => ['nullable', 'exists:cost_centers,id'],
            'movements.*.notes' => ['nullable', 'string'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoice_id' => ['required_with:allocations', 'exists:invoices,id'],
            'allocations.*.amount' => ['required_with:allocations', 'numeric', 'gt:0'],
            'allocations.*.notes' => ['nullable', 'string'],
        ]);

        $invoiceAllocations = collect($data['invoices'] ?? $data['allocations'] ?? [])
            ->map(fn (array $allocation) => [
                'invoice_id' => $allocation['invoice_id'],
                'amount' => round(abs((float) $allocation['amount']), 2),
                'notes' => $allocation['notes'] ?? null,
            ])
            ->values()
            ->all();

        $movementAllocations = collect($data['movements'] ?? [])
            ->map(fn (array $allocation) => [
                'movement_id' => $allocation['movement_id'],
                'amount' => round(abs((float) $allocation['amount']), 2),
                'centro_custo_id' => $allocation['centro_custo_id'] ?? null,
                'notes' => $allocation['notes'] ?? null,
            ])
            ->values()
            ->all();

        if ($invoiceAllocations === [] && $movementAllocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'Indique pelo menos uma alocacao de fatura ou movimento.',
            ]);
        }

        $statementAvailableAmount = round(max((float) ($bankStatement->valor_por_conciliar ?? abs((float) $bankStatement->valor)), 0), 2);
        $requestedTotal = round(
            collect($invoiceAllocations)->sum('amount') + collect($movementAllocations)->sum('amount'),
            2,
        );

        if ($requestedTotal - $statementAvailableAmount > 0.009) {
            throw ValidationException::withMessages([
                'allocations' => 'As alocacoes excedem o valor disponivel da linha bancaria.',
            ]);
        }

        $createCredit = (bool) ($data['create_credit'] ?? false);
        $creditUserId = $data['credit_user_id'] ?? null;
        $creditFamilyId = $data['credit_family_id'] ?? null;
        $creditTargetProvided = !empty($creditUserId) || !empty($creditFamilyId);

        if (!empty($creditUserId) && !empty($creditFamilyId)) {
            throw ValidationException::withMessages([
                'create_credit' => 'Escolha apenas um destino explicito para o credito: utilizador ou familia.',
            ]);
        }

        if ($createCredit && !$creditTargetProvided && round($statementAvailableAmount - $requestedTotal, 2) > 0.009) {
            throw ValidationException::withMessages([
                'create_credit' => 'O credito excedente exige user_id ou family_id explicito.',
            ]);
        }

        $invoiceIds = collect($invoiceAllocations)->pluck('invoice_id')->unique()->values();
        $fiscalRequestsBefore = DB::table('fiscal_document_requests')
            ->whereIn('invoice_id', $invoiceIds)
            ->count();
        $hasInvoices = $invoiceAllocations !== [];
        $hasMovements = $movementAllocations !== [];

        $payments = collect();

        if ($hasInvoices) {
            $payments->push($this->financialSettlementService->settleInvoices($invoiceAllocations, [
                'bank_statement_id' => $bankStatement->id,
                'method' => 'transferencia',
                'reference' => $bankStatement->referencia,
                'description' => $bankStatement->descricao,
                'notes' => $data['notes'] ?? null,
                'create_credit' => $createCredit && !$hasMovements,
                'created_by' => $request->user()?->id,
                'source' => Payment::SOURCE_RECONCILIATION,
                'user_id' => $creditUserId,
                'family_id' => $creditFamilyId,
                'map_rule' => 'manual_bank_allocation',
                'map_metadata' => [
                    'manual_allocation' => true,
                ],
            ]));
        }

        if ($hasMovements) {
            $entryAllocations = collect($movementAllocations)
                ->map(function (array $allocation) {
                    $movement = Movement::query()->with('user.centrosCusto')->findOrFail($allocation['movement_id']);
                    $resolvedCostCenterId = $allocation['centro_custo_id']
                        ?? $movement->centro_custo_id
                        ?? $movement->user?->centrosCusto->sortByDesc(fn ($center) => (float) ($center->pivot->peso ?? 1))->first()?->id;

                    if (!$resolvedCostCenterId) {
                        throw ValidationException::withMessages([
                            'movements' => 'Existe um movimento sem centro de custo. Indique centro de custo nessa linha.',
                        ]);
                    }

                    if ($resolvedCostCenterId !== $movement->centro_custo_id) {
                        $movement->forceFill(['centro_custo_id' => $resolvedCostCenterId])->save();
                    }

                    $entry = $this->financialSettlementService->findOrCreateFinancialEntryForMovement($movement->fresh(), [
                        'categoria' => 'Movimento Financeiro',
                        'method' => 'transferencia',
                    ]);

                    if (!$entry->centro_custo_id) {
                        $entry->forceFill(['centro_custo_id' => $resolvedCostCenterId])->save();
                        $entry = $entry->fresh();
                    }

                    return [
                        'financial_entry_id' => $entry->id,
                        'amount' => $allocation['amount'],
                        'notes' => $allocation['notes'] ?? null,
                        'metadata' => [
                            'movement_id' => $movement->id,
                        ],
                    ];
                })
                ->values()
                ->all();

            $payments->push($this->financialSettlementService->settleFinancialEntries($entryAllocations, [
                'bank_statement_id' => $bankStatement->id,
                'method' => 'transferencia',
                'reference' => $bankStatement->referencia,
                'description' => $bankStatement->descricao,
                'notes' => $data['notes'] ?? null,
                'create_credit' => $createCredit,
                'created_by' => $request->user()?->id,
                'source' => Payment::SOURCE_RECONCILIATION,
                'user_id' => $creditUserId,
                'family_id' => $creditFamilyId,
                'map_rule' => 'manual_bank_allocation',
                'map_metadata' => [
                    'manual_allocation' => true,
                ],
            ]));
        }

        $latestPayment = $payments->last();
        if (!$latestPayment instanceof Payment) {
            throw ValidationException::withMessages([
                'allocations' => 'Nao foi possivel registar a conciliacao manual.',
            ]);
        }

        return $this->paymentResponse($latestPayment->fresh(), $invoiceIds->all(), $fiscalRequestsBefore, [
            'manual_allocation' => true,
            'affected_payment_count' => $payments->count(),
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
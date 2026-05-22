<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\AgeGroup;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FiscalDocumentRequest;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\MonthlyFee;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementItem;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Club\ClubSettingsService;
use App\Services\Financeiro\BankReconciliationService;
use App\Services\Financeiro\FinanceDashboardService;
use App\Services\Financeiro\FinancialSettlementService;
use App\Services\Financeiro\FiscalDocumentRequestService;
use App\Services\Financeiro\ManualExpenseService;
use App\Services\Financeiro\MovementDocumentControlService;
use App\Services\Financeiro\MonthlyInvoiceStatusService;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use App\Services\Financeiro\PaymentAllocationService;
use App\Services\Financeiro\ReconciliationAliasService;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceiroController extends Controller
{
    private const MOVEMENT_ORIGIN_REFERENCE_PREFIX = '[ORIGEM_REF] ';

    public function __construct(
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly MonthlyFeeGenerationService $monthlyFeeGenerationService,
        private readonly FinancialSettlementService $financialSettlementService,
        private readonly BankReconciliationService $bankReconciliationService,
        private readonly FinanceDashboardService $financeDashboardService,
        private readonly MonthlyInvoiceStatusService $monthlyInvoiceStatusService,
        private readonly ManualExpenseService $manualExpenseService,
        private readonly MovementDocumentControlService $movementDocumentControlService,
    ) {
    }

    public function index(): Response
    {
        if ($this->shouldUseIndexCache(request())) {
            return Inertia::render('Financeiro/Index', Cache::remember(
                'financeiro:index',
                now()->addSeconds(60),
                fn () => $this->buildIndexPayload()
            ));
        }

        return Inertia::render('Financeiro/Index', $this->buildIndexPayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIndexPayload(): array
    {
        $this->monthlyFeeGenerationService->activateDueInvoices(null, [
            'respect_auto_activation_setting' => true,
        ]);

        try {
            $faturas = Cache::remember('financeiro:faturas', 60, fn () =>
                $this->invoiceFinancialSnapshotQuery()
                    ->withExists([
                        'fiscalDocumentRequests as has_fiscal_document_request',
                        'fiscalDocumentRequests as has_registered_fiscal_document' => function ($query): void {
                            $query
                                ->whereNotNull('external_document_number')
                                ->where('external_document_number', '!=', '');
                        },
                    ])
                    ->orderBy('data_emissao', 'desc')
                    ->limit(1000)
                    ->get()
                    ->map(function ($fatura) {
                        $fatura = $this->normalizeInvoiceFinancialAmounts($fatura);
                        $fatura->valor_total = (float) $fatura->valor_total;

                        return $fatura;
                    })
            );
        } catch (\Exception $e) {
            \Log::error('FinanceiroController::index - Faturas query failed: ' . $e->getMessage());
            $faturas = [];
        }

        $mensalidadesFaturas = collect($faturas)
            ->filter(fn ($fatura) => ($fatura->tipo ?? null) === 'mensalidade')
            ->values();

        try {
            $faturaItens = Cache::remember('financeiro:fatura_itens', 60, fn () =>
                InvoiceItem::orderBy('created_at', 'desc')->limit(3000)->get()
            );
        } catch (\Exception $e) {
            \Log::error('FinanceiroController::index - FaturaItens query failed: ' . $e->getMessage());
            $faturaItens = [];
        }

        try {
            $movimentos = Cache::remember('financeiro:movimentos', 60, fn () =>
                Movement::orderBy('data_emissao', 'desc')->limit(1000)->get()->map(function ($movimento) {
                    $movimento->valor_total = (float) $movimento->valor_total;

                    return $this->decorateMovementForResponse($movimento);
                })
            );
        } catch (\Exception $e) {
            \Log::error('FinanceiroController::index - Movimentos query failed: ' . $e->getMessage());
            $movimentos = [];
        }

        try {
            $movimentoItens = Cache::remember('financeiro:movimento_itens', 60, fn () =>
                MovementItem::orderBy('created_at', 'desc')->limit(3000)->get()
            );
        } catch (\Exception $e) {
            \Log::error('FinanceiroController::index - MovimentoItens query failed: ' . $e->getMessage());
            $movimentoItens = [];
        }

        try {
            $lancamentos = Cache::remember('financeiro:lancamentos', 60, fn () =>
                FinancialEntry::with('bankStatement:id,conciliado,conciliacao_status')
                    ->orderBy('data', 'desc')
                    ->limit(1000)
                    ->get()
                    ->map(function ($lancamento) {
                    $lancamento->valor = (float) $lancamento->valor;
                    return $lancamento;
                })
            );
        } catch (\Exception $e) {
            \Log::error('FinanceiroController::index - Lancamentos query failed: ' . $e->getMessage());
            $lancamentos = [];
        }

        $movimentosFinanceiros = $this->buildFinancialMovementsPayload(
            collect($movimentos),
            collect($lancamentos),
        );

        try {
            $extratos = Cache::remember('financeiro:extratos', 60, function () {
                $statements = BankStatement::query()
                    ->with(['financialEntry:id,origem_tipo,origem_id'])
                    ->orderBy('data_movimento', 'desc')
                    ->limit(1000)
                    ->get();

                $statementIds = $statements->pluck('id')->filter()->values();

                $movementIdsFromEntries = $statements
                    ->filter(fn (BankStatement $statement) => $statement->financialEntry?->origem_tipo === 'movement' && !empty($statement->financialEntry?->origem_id))
                    ->pluck('financialEntry.origem_id')
                    ->filter()
                    ->values();

                $movementIdsByStatement = MapaConciliacao::query()
                    ->whereIn('extrato_id', $statementIds)
                    ->whereNotNull('movimento_id')
                    ->orderByDesc('created_at')
                    ->get(['extrato_id', 'movimento_id'])
                    ->unique('extrato_id')
                    ->mapWithKeys(fn (MapaConciliacao $map) => [(string) $map->extrato_id => (string) $map->movimento_id]);

                $movementIds = $movementIdsFromEntries
                    ->merge($movementIdsByStatement->values())
                    ->filter()
                    ->unique()
                    ->values();

                $movementMap = Movement::query()
                    ->whereIn('id', $movementIds)
                    ->get(['id', 'estado_documental'])
                    ->keyBy(fn (Movement $movement) => (string) $movement->id);

                return $statements->map(function (BankStatement $extrato) use ($movementIdsByStatement, $movementMap) {
                    return $this->serializeBankStatementForResponse($extrato, $movementIdsByStatement, $movementMap);
                });
            });
        } catch (\Exception $e) {
            \Log::error('FinanceiroController::index - Extratos query failed: ' . $e->getMessage());
            $extratos = [];
        }

        try {
            $conciliacoes = Cache::remember('financeiro:conciliacoes', 60, function () {
                try {
                    // Try to select with all fields first, fallback if valor_conciliado doesn't exist
                    return MapaConciliacao::select(
                        'id',
                        'extrato_id',
                        'lancamento_id',
                        'fatura_id',
                        'movimento_id',
                        'estado_fatura_anterior',
                        'estado_movimento_anterior',
                        'valor_conciliado'
                    )->get();
                } catch (\Exception $e) {
                    \Log::warning('FinanceiroController::index - Conciliacoes with valor_conciliado failed, fallback: ' . $e->getMessage());

                    return MapaConciliacao::select(
                        'id',
                        'extrato_id',
                        'lancamento_id',
                        'fatura_id',
                        'movimento_id',
                        'estado_fatura_anterior',
                        'estado_movimento_anterior'
                    )->get();
                }
            });
        } catch (\Exception $fallbackError) {
            \Log::error('FinanceiroController::index - Conciliacoes fallback also failed: ' . $fallbackError->getMessage());
            $conciliacoes = [];
        }

        try {
            $fiscalRequests = Cache::remember('financeiro:fiscal_requests', 60, fn () =>
                $this->buildFiscalRequestsPayload()
            );
        } catch (\Exception $e) {
            \Log::error('FinanceiroController::index - Fiscal requests query failed: ' . $e->getMessage());
            $fiscalRequests = [];
        }

        return [
            'dashboardData' => $this->financeDashboardService->build(),
            'faturas' => $faturas,
            'mensalidadesFaturas' => $mensalidadesFaturas,
            'faturaItens' => $faturaItens,
            'movimentos' => $movimentos,
            'movimentosFinanceiros' => $movimentosFinanceiros,
            'movimentoItens' => $movimentoItens,
            'lancamentos' => $lancamentos,
            'extratos' => $extratos,
            'conciliacoes' => $conciliacoes,
            'fiscalRequests' => $fiscalRequests,
            'centrosCusto' => Cache::remember('financeiro:centros_custo', 300, function () {
                try {
                    return CostCenter::orderBy('nome')->get();
                } catch (\Exception $e) {
                    \Log::error('FinanceiroController::index - CostCenter query failed: ' . $e->getMessage());
                    return [];
                }
            }),
            'users' => Cache::remember('financeiro:users', 60, function () {
                try {
                    return User::select(
                        'id',
                        'nome_completo',
                        'numero_socio',
                        'data_inscricao',
                        'tipo_mensalidade',
                        'centro_custo',
                        'tipo_membro',
                        'escalao',
                        'nif',
                        'morada'
                    )
                        ->with(['dadosFinanceiros', 'centrosCusto'])
                        ->orderBy('nome_completo')
                        ->get()
                        ->map(function ($user) {
                            $user->tipo_mensalidade = $user->dadosFinanceiros?->mensalidade_id ?? $user->tipo_mensalidade;
                            $legacyCentros = collect($user->centro_custo ?? [])
                                ->map(function ($center) {
                                    if (is_array($center) && isset($center['id'])) {
                                        return $center['id'];
                                    }
                                    return $center;
                                })
                                ->filter()
                                ->values();

                            if ($user->centrosCusto->isNotEmpty()) {
                                $user->centro_custo = $user->centrosCusto->pluck('id')->values();
                                $user->centro_custo_pesos = $user->centrosCusto->map(function ($center) {
                                    return [
                                        'id' => $center->id,
                                        'peso' => (float) ($center->pivot->peso ?? 1),
                                    ];
                                })->values();
                            } else {
                                $user->centro_custo = $legacyCentros;
                                $user->centro_custo_pesos = $legacyCentros->map(function ($id) {
                                    return [
                                        'id' => $id,
                                        'peso' => 1.0,
                                    ];
                                })->values();
                            }
                            return $user;
                        });
                } catch (\Exception $e) {
                    \Log::error('FinanceiroController::index - Users query failed: ' . $e->getMessage());
                    return [];
                }
            }),
            'suppliers' => Cache::remember('financeiro:suppliers', 60, function () {
                try {
                    return Supplier::select('id', 'nome', 'nif', 'morada', 'email', 'telefone', 'categoria', 'ativo')
                        ->orderBy('nome')
                        ->get();
                } catch (\Exception $e) {
                    \Log::error('FinanceiroController::index - Suppliers query failed: ' . $e->getMessage());
                    return [];
                }
            }),
            'products' => Cache::remember('financeiro:products', 60, function () {
                try {
                    return Product::select('id', 'nome', 'preco', 'stock', 'stock_minimo', 'ativo')
                        ->orderBy('nome')
                        ->get()
                        ->map(function ($product) {
                            $product->preco = (float) $product->preco;
                            return $product;
                        });
                } catch (\Exception $e) {
                    \Log::error('FinanceiroController::index - Products query failed: ' . $e->getMessage());
                    return [];
                }
            }),
            'mensalidades' => Cache::remember('financeiro:mensalidades', 300, function () {
                try {
                    return MonthlyFee::select('id', 'designacao', 'valor', 'age_group_id')
                        ->get()
                        ->map(function ($mensalidade) {
                            $mensalidade->valor = (float) $mensalidade->valor;
                            return $mensalidade;
                        });
                } catch (\Exception $e) {
                    \Log::error('FinanceiroController::index - MonthlyFee query failed: ' . $e->getMessage());
                    return [];
                }
            }),
            'invoiceTypes' => Cache::remember('financeiro:invoice_types', 300, function () {
                try {
                    return InvoiceType::orderBy('nome')->get();
                } catch (\Exception $e) {
                    \Log::error('FinanceiroController::index - InvoiceType query failed: ' . $e->getMessage());
                    return [];
                }
            }),
            'paymentMethods' => Cache::remember('financeiro:payment_methods', 300, function () {
                try {
                    return PaymentMethod::query()->ativo()->ordenado()->get();
                } catch (\Exception $e) {
                    \Log::error('FinanceiroController::index - PaymentMethod query failed: ' . $e->getMessage());
                    return [];
                }
            }),
            'ageGroups' => Cache::remember('financeiro:age_groups', 300, function () {
                try {
                    return AgeGroup::select('id', 'nome')->orderBy('nome')->get();
                } catch (\Exception $e) {
                    \Log::error('FinanceiroController::index - AgeGroup query failed: ' . $e->getMessage());
                    return [];
                }
            }),
        ];
    }

    private function buildFiscalRequestsPayload(): Collection
    {
        return FiscalDocumentRequest::query()
            ->with([
                'invoice:id,user_id,valor_total,estado_pagamento,numero_recibo,referencia_pagamento,tipo',
                'user:id,name,nome_completo,email,nif,morada,codigo_postal,localidade',
                'bankStatement:id,data_movimento,descricao,referencia',
                'mapaConciliacao:id,extrato_id,lancamento_id,fatura_id,movimento_id,valor_conciliado',
            ])
            ->where(function ($query): void {
                $query
                    ->whereHas('invoice', function ($invoiceQuery): void {
                        $invoiceQuery->where('tipo', 'mensalidade');
                    })
                    ->orWhereHas('financialEntry', function ($entryQuery): void {
                        $entryQuery->where('tipo', 'receita');
                    });
            })
            ->latest('created_at')
            ->limit(1000)
            ->get();
    }

    private function shouldUseIndexCache(Request $request): bool
    {
        return $request->query->count() === 0
            && ! $request->session()->has('success')
            && ! $request->session()->has('error')
            && ! $request->session()->has('warning');
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        if (in_array($data['estado_pagamento'] ?? 'pendente', ['pago', 'parcial'], true)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A liquidacao da fatura tem de ser efetuada pelo fluxo canonico de pagamento.',
            ]);
        }

        $invoice = Invoice::create([
            'user_id' => $data['user_id'],
            'data_fatura' => $data['data_fatura'] ?? $data['data_emissao'],
            'mes' => $data['mes'] ?? null,
            'data_emissao' => $data['data_emissao'],
            'data_vencimento' => $data['data_vencimento'],
            'valor_total' => $data['valor_total'],
            'oculta' => $data['oculta'] ?? false,
            'estado_pagamento' => $data['estado_pagamento'] ?? 'pendente',
            'referencia_pagamento' => $data['referencia_pagamento'] ?? null,
            'centro_custo_id' => $data['centro_custo_id'] ?? null,
            'tipo' => $data['tipo'],
            'origem_tipo' => $data['origem_tipo'] ?? null,
            'origem_id' => $data['origem_id'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
        ]);

        if (isset($data['items'])) {
            foreach ($data['items'] as $item) {
                InvoiceItem::create([
                    'fatura_id' => $invoice->id,
                    'descricao' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'valor_unitario' => $item['valor_unitario'],
                    'imposto_percentual' => $item['imposto_percentual'] ?? 0,
                    'total_linha' => $item['total_linha'],
                    'produto_id' => $item['produto_id'] ?? null,
                    'centro_custo_id' => $item['centro_custo_id'] ?? $data['centro_custo_id'] ?? null,
                ]);

                if (!empty($item['produto_id'])) {
                    Product::where('id', $item['produto_id'])->decrement('stock', (int) $item['quantidade']);
                }
            }
        }

        $this->invalidateFinanceiroCaches();

        if ($request->expectsJson()) {
            return response()->json([
                'invoice' => $invoice->load('items'),
            ]);
        }

        return redirect()->route('financeiro.index')
            ->with('success', 'Fatura criada com sucesso!');
    }

    public function show(Invoice $financeiro): Response
    {
        return Inertia::render('Financeiro/Show', [
            'invoice' => $financeiro->load(['user', 'items']),
        ]);
    }

    public function showMovimento(Request $request, Movement $movimento): Response|JsonResponse
    {
        $movimento->load([
            'supplier:id,nome,nif,morada',
            'centroCusto:id,nome',
            'items.centroCusto:id,nome',
            'documents.supplier:id,nome',
            'documents.validator:id,name,nome_completo',
            'financialEntries.bankStatement:id,data_movimento,descricao,valor,referencia,conciliado,conciliacao_status,valor_conciliado,valor_por_conciliar,lancamento_id',
            'financialEntries.reconciliationMap:id,lancamento_id,extrato_id,descricao,created_at',
        ]);

        $payload = [
            'movement' => $this->serializeMovementDetail($movimento),
            'suppliers' => Supplier::query()->orderBy('nome')->get(['id', 'nome']),
            'availableBankStatements' => $this->serializeAvailableBankStatementsForMovement($movimento),
            'canManageDocuments' => $this->canManageMovementDocuments($request->user()),
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return Inertia::render('Financeiro/Show', $payload);
    }

    public function storeMovementDocument(Request $request, Movement $movimento): JsonResponse
    {
        $this->ensureCanManageMovementDocuments($request->user());

        $data = $request->validate([
            'document_type' => ['required', 'in:invoice,receipt,invoice_receipt,payment_proof,bank_statement_line,credit_note,other'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'amount' => ['nullable', 'numeric'],
            'vat_amount' => ['nullable', 'numeric'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'file' => ['nullable', 'file'],
            'notes' => ['nullable', 'string'],
        ]);

        $document = new MovementDocument([
            'supplier_id' => $data['supplier_id'] ?? $movimento->supplier_id,
            'document_type' => $data['document_type'],
            'source_type' => 'manual_upload',
            'document_number' => $data['document_number'] ?? null,
            'issue_date' => $data['issue_date'] ?? $movimento->data_emissao,
            'due_date' => $data['due_date'] ?? $movimento->data_vencimento,
            'amount' => $data['amount'] ?? abs((float) $movimento->valor_total),
            'vat_amount' => $data['vat_amount'] ?? null,
            'status' => 'pending_validation',
            'notes' => $data['notes'] ?? null,
        ]);

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $document->original_filename = $file->getClientOriginalName();
            $document->stored_path = $file->store('financeiro/movimentos/documentos', 'public');
            $document->mime_type = $file->getClientMimeType();
            $document->sha256_hash = hash_file('sha256', $file->getRealPath());
        }

        $this->movementDocumentControlService->attachDocumentToMovement($document, $movimento);
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'document' => $this->serializeMovementDocument($document->fresh(['supplier', 'validator'])),
            'movement' => $this->serializeMovementDetail($movimento->fresh([
                'supplier:id,nome,nif,morada',
                'centroCusto:id,nome',
                'items.centroCusto:id,nome',
                'documents.supplier:id,nome',
                'documents.validator:id,name,nome_completo',
                'financialEntries.bankStatement:id,data_movimento,descricao,valor,referencia,conciliado,conciliacao_status,valor_conciliado,valor_por_conciliar,lancamento_id',
                'financialEntries.reconciliationMap:id,lancamento_id,extrato_id,descricao,created_at',
            ])),
        ]);
    }

    public function validateMovementDocument(Request $request, Movement $movimento, MovementDocument $document): JsonResponse
    {
        $this->ensureCanManageMovementDocuments($request->user());
        $this->ensureDocumentBelongsToMovement($movimento, $document);

        $document->forceFill([
            'status' => 'valid',
            'validated_at' => now(),
            'validated_by' => $request->user()?->id,
            'notes' => $request->input('notes', $document->notes),
        ])->save();

        $this->movementDocumentControlService->refresh($movimento->fresh());
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'document' => $this->serializeMovementDocument($document->fresh(['supplier', 'validator'])),
            'movement' => $this->serializeMovementDetail($movimento->fresh([
                'supplier:id,nome,nif,morada',
                'centroCusto:id,nome',
                'items.centroCusto:id,nome',
                'documents.supplier:id,nome',
                'documents.validator:id,name,nome_completo',
                'financialEntries.bankStatement:id,data_movimento,descricao,valor,referencia,conciliado,conciliacao_status,valor_conciliado,valor_por_conciliar,lancamento_id',
                'financialEntries.reconciliationMap:id,lancamento_id,extrato_id,descricao,created_at',
            ])),
        ]);
    }

    public function rejectMovementDocument(Request $request, Movement $movimento, MovementDocument $document): JsonResponse
    {
        $this->ensureCanManageMovementDocuments($request->user());
        $this->ensureDocumentBelongsToMovement($movimento, $document);

        $document->forceFill([
            'status' => 'rejected',
            'validated_at' => now(),
            'validated_by' => $request->user()?->id,
            'notes' => $request->input('notes', $document->notes),
        ])->save();

        $this->movementDocumentControlService->refresh($movimento->fresh());
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'document' => $this->serializeMovementDocument($document->fresh(['supplier', 'validator'])),
            'movement' => $this->serializeMovementDetail($movimento->fresh([
                'supplier:id,nome,nif,morada',
                'centroCusto:id,nome',
                'items.centroCusto:id,nome',
                'documents.supplier:id,nome',
                'documents.validator:id,name,nome_completo',
                'financialEntries.bankStatement:id,data_movimento,descricao,valor,referencia,conciliado,conciliacao_status,valor_conciliado,valor_por_conciliar,lancamento_id',
                'financialEntries.reconciliationMap:id,lancamento_id,extrato_id,descricao,created_at',
            ])),
        ]);
    }

    public function markMovementDocumentDuplicate(Request $request, Movement $movimento, MovementDocument $document): JsonResponse
    {
        $this->ensureCanManageMovementDocuments($request->user());
        $this->ensureDocumentBelongsToMovement($movimento, $document);

        $document->forceFill([
            'status' => 'duplicate',
            'validated_at' => now(),
            'validated_by' => $request->user()?->id,
            'notes' => $request->input('notes', $document->notes),
        ])->save();

        $this->movementDocumentControlService->refresh($movimento->fresh());
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'document' => $this->serializeMovementDocument($document->fresh(['supplier', 'validator'])),
            'movement' => $this->serializeMovementDetail($movimento->fresh([
                'supplier:id,nome,nif,morada',
                'centroCusto:id,nome',
                'items.centroCusto:id,nome',
                'documents.supplier:id,nome',
                'documents.validator:id,name,nome_completo',
                'financialEntries.bankStatement:id,data_movimento,descricao,valor,referencia,conciliado,conciliacao_status,valor_conciliado,valor_por_conciliar,lancamento_id',
                'financialEntries.reconciliationMap:id,lancamento_id,extrato_id,descricao,created_at',
            ])),
        ]);
    }

    public function recalculateMovementDocumentStatus(Request $request, Movement $movimento): JsonResponse
    {
        $this->ensureCanManageMovementDocuments($request->user());

        $this->movementDocumentControlService->refresh($movimento->fresh());
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'movement' => $this->serializeMovementDetail($movimento->fresh([
                'supplier:id,nome,nif,morada',
                'centroCusto:id,nome',
                'items.centroCusto:id,nome',
                'documents.supplier:id,nome',
                'documents.validator:id,name,nome_completo',
                'financialEntries.bankStatement:id,data_movimento,descricao,valor,referencia,conciliado,conciliacao_status,valor_conciliado,valor_por_conciliar,lancamento_id',
                'financialEntries.reconciliationMap:id,lancamento_id,extrato_id,descricao,created_at',
            ])),
        ]);
    }

    public function markMovementConciliationDivergent(Request $request, Movement $movimento): JsonResponse
    {
        $this->ensureCanManageMovementDocuments($request->user());

        $movimento->forceFill([
            'estado_conciliacao' => 'divergente',
        ])->save();

        $this->movementDocumentControlService->refresh($movimento->fresh());
        $this->invalidateFinanceiroCaches();

        return response()->json([
            'movement' => $this->serializeMovementDetail($movimento->fresh([
                'supplier:id,nome,nif,morada',
                'centroCusto:id,nome',
                'items.centroCusto:id,nome',
                'documents.supplier:id,nome',
                'documents.validator:id,name,nome_completo',
                'financialEntries.bankStatement:id,data_movimento,descricao,valor,referencia,conciliado,conciliacao_status,valor_conciliado,valor_por_conciliar,lancamento_id',
                'financialEntries.reconciliationMap:id,lancamento_id,extrato_id,descricao,created_at',
            ])),
        ]);
    }

    public function updateMovementNotes(Request $request, Movement $movimento): JsonResponse
    {
        $this->ensureCanManageMovementDocuments($request->user());

        $data = $request->validate([
            'observacoes' => ['nullable', 'string'],
        ]);

        $movimento->forceFill([
            'observacoes' => $data['observacoes'] ?? null,
        ])->save();

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'movement' => $this->serializeMovementDetail($movimento->fresh([
                'supplier:id,nome,nif,morada',
                'centroCusto:id,nome',
                'items.centroCusto:id,nome',
                'documents.supplier:id,nome',
                'documents.validator:id,name,nome_completo',
                'financialEntries.bankStatement:id,data_movimento,descricao,valor,referencia,conciliado,conciliacao_status,valor_conciliado,valor_por_conciliar,lancamento_id',
                'financialEntries.reconciliationMap:id,lancamento_id,extrato_id,descricao,created_at',
            ])),
        ]);
    }

    public function edit(Invoice $financeiro): Response
    {
        return Inertia::render('Financeiro/Edit', [
            'invoice' => $financeiro->load(['items']),
            'users' => User::where('estado', 'ativo')->get(),
        ]);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $financeiro): RedirectResponse|JsonResponse
    {
        $data = $request->validated();

        $requestedStatus = $data['estado_pagamento'] ?? $financeiro->estado_pagamento;
        $isMonthlyFinancialTransition = $financeiro->tipo === 'mensalidade'
            && (
                (
                    in_array($requestedStatus, ['pago', 'parcial'], true)
                    && ! in_array($financeiro->estado_pagamento, ['pago', 'parcial'], true)
                )
                || (
                    in_array($financeiro->estado_pagamento, ['pago', 'parcial'], true)
                    && ! in_array($requestedStatus, ['pago', 'parcial'], true)
                )
                || ($financeiro->estado_pagamento === 'parcial' && $requestedStatus === 'pago')
            );

        if ($isMonthlyFinancialTransition) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A alteracao de estado financeiro da mensalidade tem de ser efetuada pelo fluxo canonico da mensalidade.',
            ]);
        }

        if (
            in_array($requestedStatus, ['pago', 'parcial'], true)
            && !in_array($financeiro->estado_pagamento, ['pago', 'parcial'], true)
        ) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A liquidacao da fatura tem de ser efetuada pelo fluxo de pagamento.',
            ]);
        }

        $isManualPaymentReversal = $financeiro->tipo !== 'mensalidade'
            && in_array($financeiro->estado_pagamento, ['pago', 'parcial'], true)
            && !in_array($requestedStatus, ['pago', 'parcial'], true);

        if ($isManualPaymentReversal) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A reabertura da fatura tem de ser efetuada pelo endpoint canonico de reabertura.',
            ]);
        }

        $financeiro->update([
            'user_id' => $data['user_id'],
            'data_fatura' => $data['data_fatura'] ?? $data['data_emissao'],
            'mes' => $data['mes'] ?? null,
            'data_emissao' => $data['data_emissao'],
            'data_vencimento' => $data['data_vencimento'],
            'valor_total' => $data['valor_total'],
            'oculta' => $data['oculta'] ?? $financeiro->oculta,
            'estado_pagamento' => $requestedStatus,
            'referencia_pagamento' => $data['referencia_pagamento'] ?? $financeiro->referencia_pagamento,
            'centro_custo_id' => $data['centro_custo_id'] ?? null,
            'tipo' => $data['tipo'],
            'origem_tipo' => $data['origem_tipo'] ?? $financeiro->origem_tipo,
            'origem_id' => $data['origem_id'] ?? $financeiro->origem_id,
            'observacoes' => $data['observacoes'] ?? null,
        ]);

        if (isset($data['items'])) {
            $existingItems = InvoiceItem::where('fatura_id', $financeiro->id)->get();
            $existingByProduct = $existingItems
                ->filter(fn ($item) => !empty($item->produto_id))
                ->groupBy('produto_id')
                ->map(fn ($group) => (int) $group->sum('quantidade'));

            InvoiceItem::where('fatura_id', $financeiro->id)->delete();

            $newByProduct = [];
            foreach ($data['items'] as $item) {
                InvoiceItem::create([
                    'fatura_id' => $financeiro->id,
                    'descricao' => $item['descricao'],
                    'quantidade' => $item['quantidade'],
                    'valor_unitario' => $item['valor_unitario'],
                    'imposto_percentual' => $item['imposto_percentual'] ?? 0,
                    'total_linha' => $item['total_linha'],
                    'produto_id' => $item['produto_id'] ?? null,
                    'centro_custo_id' => $item['centro_custo_id'] ?? $data['centro_custo_id'] ?? null,
                ]);

                if (!empty($item['produto_id'])) {
                    $newByProduct[$item['produto_id']] = ($newByProduct[$item['produto_id']] ?? 0) + (int) $item['quantidade'];
                }
            }

            $allProductIds = collect($existingByProduct->keys())
                ->merge(array_keys($newByProduct))
                ->unique();

            foreach ($allProductIds as $productId) {
                $previous = (int) ($existingByProduct[$productId] ?? 0);
                $next = (int) ($newByProduct[$productId] ?? 0);
                $delta = $next - $previous;
                if ($delta > 0) {
                    Product::where('id', $productId)->decrement('stock', $delta);
                } elseif ($delta < 0) {
                    Product::where('id', $productId)->increment('stock', abs($delta));
                }
            }
        }

        $this->invalidateFinanceiroCaches();

        if ($request->expectsJson()) {
            return response()->json([
                'invoice' => $financeiro->load('items'),
            ]);
        }

        return redirect()->route('financeiro.index')
            ->with('success', 'Fatura atualizada com sucesso!');
    }

    public function updateInvoicePaymentStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'estado_pagamento' => ['required', 'in:pendente,vencido'],
            'notes' => ['nullable', 'string'],
        ]);

        $updatedInvoice = $invoice->tipo === 'mensalidade'
            ? $this->monthlyInvoiceStatusService->transition($invoice, $data['estado_pagamento'], [
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
            ])
            : $this->paymentAllocationService->reopenInvoice($invoice, $data['estado_pagamento'], [
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()?->id,
                'cancelled_at' => now(),
            ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'invoice' => $updatedInvoice->load(['items']),
            'message' => 'Fatura reaberta pelo fluxo canonico com sucesso.',
        ]);
    }

    public function updateMonthlyInvoiceStatus(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate([
            'estado_pagamento' => ['required', 'in:pago,pendente,vencido'],
            'bank_statement_id' => ['nullable', 'exists:bank_statements,id'],
            'payment_date' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $updatedInvoice = $this->monthlyInvoiceStatusService->transition($invoice, $data['estado_pagamento'], [
            'bank_statement_id' => $data['bank_statement_id'] ?? null,
            'payment_date' => $data['payment_date'] ?? now()->toDateString(),
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'invoice' => $updatedInvoice->load(['items']),
            'message' => $data['estado_pagamento'] === 'pago'
                ? 'Mensalidade liquidada pelo fluxo canonico com sucesso.'
                : 'Mensalidade reaberta pelo fluxo canonico com sucesso.',
        ]);
    }

    public function generateMonthlyFees(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'current_season' => ['nullable', 'boolean'],
            'generate_for_all' => ['nullable', 'boolean'],
            'user_id' => ['nullable', 'exists:users,id'],
            'monthly_fee_id' => ['nullable', 'exists:monthly_fees,id'],
            'only_active' => ['nullable', 'boolean'],
        ]);

        if (($data['generate_for_all'] ?? false) !== true && empty($data['user_id'])) {
            throw ValidationException::withMessages([
                'user_id' => 'Selecione um utilizador ou gere para todos.',
            ]);
        }

        if (isset($data['start_date']) || isset($data['end_date'])) {
            $start = isset($data['start_date'])
                ? Carbon::parse($data['start_date'])->startOfMonth()
                : Carbon::today()->startOfMonth();
            $end = isset($data['end_date'])
                ? Carbon::parse($data['end_date'])->startOfMonth()
                : $start->copy();

            if (!empty($data['user_id'])) {
                $user = User::query()->findOrFail($data['user_id']);
                $summary = $this->monthlyFeeGenerationService->generateForUserWithSummary($user, $start, $end, [
                    'only_active' => (bool) ($data['only_active'] ?? true),
                    'start_date' => $data['start_date'] ?? null,
                    'monthly_fee_id' => $data['monthly_fee_id'] ?? null,
                    'manual_trigger' => true,
                ]);
            } else {
                $summary = $this->monthlyFeeGenerationService->generateForAllEligibleUsers($start, $end, [
                    'only_active' => (bool) ($data['only_active'] ?? true),
                    'start_date' => $data['start_date'] ?? null,
                    'monthly_fee_id' => $data['monthly_fee_id'] ?? null,
                    'manual_trigger' => true,
                ]);
            }
        } else {
            $summary = $this->monthlyFeeGenerationService->generateConfiguredCycle([
                    'only_active' => (bool) ($data['only_active'] ?? true),
                    'user_ids' => !empty($data['user_id']) ? [$data['user_id']] : null,
                    'monthly_fee_id' => $data['monthly_fee_id'] ?? null,
                    'manual_trigger' => true,
                ]);
        }

        $summary['activated_count'] = $this->monthlyFeeGenerationService->activateDueInvoices(null, ['force' => true]);

        $createdInvoices = Invoice::query()
            ->with('items')
            ->whereIn('id', $summary['created_invoice_ids'] ?? [])
            ->orderBy('data_emissao')
            ->get();

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'summary' => $summary,
            'invoices' => $createdInvoices,
        ]);
    }

    public function destroy(Invoice $financeiro): RedirectResponse|JsonResponse
    {
        if ($this->invoiceHasFinancialTrail($financeiro)) {
            $message = 'A fatura tem rasto financeiro ou fiscal. Deve ser cancelada/anulada, nao apagada.';

            if (request()->expectsJson()) {
                throw ValidationException::withMessages([
                    'invoice' => $message,
                ]);
            }

            return redirect()->route('financeiro.index')
                ->with('error', $message);
        }

        $financeiro->delete();

        $this->invalidateFinanceiroCaches();

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->route('financeiro.index')
            ->with('success', 'Fatura eliminada com sucesso!');
    }

    public function storePayment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_statement_id' => ['nullable', 'exists:bank_statements,id'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'payment_date' => ['nullable', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'create_credit' => ['nullable', 'boolean'],
            'family_id' => ['nullable', 'exists:familias,id'],
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
        $invoicesBefore = Invoice::query()->whereIn('id', $invoiceIds)->get()->keyBy('id');
        $fiscalRequestsBefore = DB::table('fiscal_document_requests')
            ->whereIn('invoice_id', $invoiceIds)
            ->count();

        try {
            $payment = $this->financialSettlementService->settleInvoices($allocations, [
                'bank_statement_id' => $data['bank_statement_id'] ?? null,
                'amount' => $data['amount'] ?? collect($allocations)->sum('amount'),
                'payment_date' => $data['payment_date'] ?? now()->toDateString(),
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'family_id' => $data['family_id'] ?? null,
                'user_id' => $invoiceIds->count() === 1 ? $invoicesBefore->first()?->user_id : null,
                'create_credit' => (bool) ($data['create_credit'] ?? false),
                'created_by' => $request->user()?->id,
                'source' => !empty($data['bank_statement_id']) ? Payment::SOURCE_BANK_STATEMENT : Payment::SOURCE_MANUAL,
            ]);
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $updatedInvoices = Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->orderBy('data_emissao', 'desc')
            ->get();
        $updatedBankStatement = $payment->bankStatement?->fresh();
        $activeFiscalRequests = DB::table('fiscal_document_requests')
            ->whereIn('invoice_id', $invoiceIds)
            ->whereIn('status', ['pending', 'in_progress', 'issued', 'error_data', 'api_error'])
            ->count();
        $hasPartialInvoice = $updatedInvoices->contains(fn (Invoice $invoice) => $invoice->estado_pagamento === 'parcial');
        $allPaid = $updatedInvoices->isNotEmpty()
            && $updatedInvoices->every(fn (Invoice $invoice) => $invoice->estado_pagamento === 'pago');

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'payment' => $payment->load(['allocations.invoice', 'credits', 'bankStatement']),
            'invoices' => $updatedInvoices,
            'bank_statement' => $updatedBankStatement,
            'summary' => [
                'all_paid' => $allPaid,
                'has_partial_invoice' => $hasPartialInvoice,
                'created_credit' => $payment->credits()->exists(),
                'bank_statement_reconciled' => $updatedBankStatement?->conciliado ?? false,
                'bank_statement_partial' => ($updatedBankStatement?->conciliacao_status ?? null) === 'partial',
                'active_fiscal_requests' => $activeFiscalRequests,
                'new_fiscal_requests' => max($activeFiscalRequests - $fiscalRequestsBefore, 0),
                'affected_invoice_count' => $updatedInvoices->count(),
            ],
        ]);
    }

    public function openInvoices(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'family_id' => ['nullable', 'exists:familias,id'],
            'search' => ['nullable', 'string'],
            'overdue' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 25);
        $search = trim((string) ($data['search'] ?? ''));
        $overdue = filter_var($data['overdue'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $operator = $this->searchOperator();

        $query = Invoice::query()
            ->with(['user:id,nome_completo,name', 'user.families:id,nome', 'costCenter:id,nome'])
            ->whereIn('estado_pagamento', ['pendente', 'vencido', 'parcial']);

        $query = $this->applyInvoiceFinancialSnapshotColumns($query);

        if (!empty($data['user_id'])) {
            $query->where('user_id', $data['user_id']);
        }

        if (!empty($data['family_id'])) {
            $familyId = $data['family_id'];
            $query->whereHas('user.families', function ($familyQuery) use ($familyId) {
                $familyQuery->where('familias.id', $familyId);
            });
        }

        if ($search !== '') {
            $tokens = collect(preg_split('/\s+/', $search) ?: [])
                ->map(fn (string $token) => trim($token))
                ->filter(fn (string $token) => strlen($token) >= 3)
                ->values();

            $query->where(function ($nestedQuery) use ($search, $tokens, $operator) {
                $nestedQuery
                    ->where('tipo', $operator, "%{$search}%")
                    ->orWhere('mes', $operator, "%{$search}%")
                    ->orWhere('referencia_pagamento', $operator, "%{$search}%");

                foreach ($tokens as $token) {
                    $like = "%{$token}%";

                    $nestedQuery
                        ->orWhereHas('user', function ($userQuery) use ($like, $operator) {
                            $userQuery
                                ->where('nome_completo', $operator, $like)
                                ->orWhere('name', $operator, $like)
                                ->orWhere('numero_socio', $operator, $like)
                                ->orWhere('nif', $operator, $like)
                                ->orWhereHas('families', function ($familyQuery) use ($like, $operator) {
                                    $familyQuery->where('familias.nome', $operator, $like);
                                })
                                ->orWhereHas('families.responsavel', function ($responsavelQuery) use ($like, $operator) {
                                    $responsavelQuery
                                        ->where('nome_completo', $operator, $like)
                                        ->orWhere('name', $operator, $like);
                                })
                                ->orWhereHas('encarregados', function ($guardianQuery) use ($like, $operator) {
                                    $guardianQuery
                                        ->where('nome_completo', $operator, $like)
                                        ->orWhere('name', $operator, $like);
                                });
                        });
                }
            });
        }

        if ($overdue) {
            $query->whereDate('data_vencimento', '<', now()->toDateString());
        }

        $paginator = $query
            ->orderBy('data_vencimento')
            ->paginate($perPage)
            ->through(function (Invoice $invoice) {
                $invoice = $this->normalizeInvoiceFinancialAmounts($invoice);
                $paidAmount = (float) ($invoice->valor_pago ?? 0);
                $outstandingAmount = (float) ($invoice->valor_em_aberto ?? 0);
                $family = $invoice->user?->families?->first();

                return [
                    'id' => $invoice->id,
                    'user_id' => $invoice->user_id,
                    'user_name' => $invoice->user?->nome_completo ?? $invoice->user?->name,
                    'family_id' => $family?->id,
                    'family_name' => $family?->nome,
                    'valor_total' => (float) $invoice->valor_total,
                    'valor_pago' => $paidAmount,
                    'valor_em_aberto' => $outstandingAmount,
                    'estado_pagamento' => $invoice->estado_pagamento,
                    'data_fatura' => optional($invoice->data_fatura)?->toDateString(),
                    'vencimento' => optional($invoice->data_vencimento)?->toDateString(),
                    'mes' => $invoice->mes,
                    'tipo' => $invoice->tipo,
                    'centro_custo_id' => $invoice->centro_custo_id,
                    'centro_custo_name' => $invoice->costCenter?->nome,
                ];
            });

        $filteredCollection = $paginator->getCollection()
            ->filter(fn (array $invoice): bool => (float) ($invoice['valor_em_aberto'] ?? 0) > 0.009)
            ->values();

        $paginator->setCollection($filteredCollection);

        return response()->json($paginator);
    }

    public function openMovements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string'],
            'user_id' => ['nullable', 'exists:users,id'],
            'family_id' => ['nullable', 'exists:familias,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 25);
        $search = trim((string) ($data['search'] ?? ''));
        $userId = $data['user_id'] ?? null;
        $familyId = $data['family_id'] ?? null;
        $operator = $this->searchOperator();

        $query = Movement::query()
            ->with([
                'user:id,nome_completo,name,numero_socio,nif',
                'user.families:id,nome',
                'user.centrosCusto:id,nome',
                'centroCusto:id,nome',
                'financialEntries' => fn ($financialEntriesQuery) => $financialEntriesQuery
                    ->select('id', 'origem_id', 'origem_tipo', 'valor_em_aberto', 'valor_pago', 'estado', 'centro_custo_id', 'created_at')
                    ->orderBy('origem_id')
                    ->orderByDesc('created_at'),
            ])
            ->whereIn('estado_pagamento', ['pendente', 'parcial']);

        if ($userId) {
            $query->where('user_id', $userId);
        }

        if ($familyId) {
            $query->whereHas('user.families', function ($familyQuery) use ($familyId): void {
                $familyQuery->where('familias.id', $familyId);
            });
        }

        if ($search !== '') {
            $tokens = collect(preg_split('/\s+/', $search) ?: [])
                ->map(fn (string $token) => trim($token))
                ->filter(fn (string $token) => strlen($token) >= 2)
                ->values();

            $query->where(function ($nestedQuery) use ($search, $tokens, $operator): void {
                $nestedQuery
                    ->where('observacoes', $operator, "%{$search}%")
                    ->orWhere('nome_manual', $operator, "%{$search}%")
                    ->orWhere('nif_manual', $operator, "%{$search}%")
                    ->orWhere('numero_recibo', $operator, "%{$search}%")
                    ->orWhere('referencia_pagamento', $operator, "%{$search}%");

                foreach ($tokens as $token) {
                    $like = "%{$token}%";

                    $nestedQuery->orWhereHas('user', function ($userQuery) use ($like, $operator): void {
                        $userQuery
                            ->where('nome_completo', $operator, $like)
                            ->orWhere('name', $operator, $like)
                            ->orWhere('numero_socio', $operator, $like)
                            ->orWhere('nif', $operator, $like)
                            ->orWhereHas('families', function ($familyQuery) use ($like, $operator): void {
                                $familyQuery->where('familias.nome', $operator, $like);
                            });
                    });
                }
            });
        }

        $paginator = $query
            ->orderBy('data_vencimento')
            ->orderBy('data_emissao')
            ->paginate($perPage)
            ->through(function (Movement $movement) {
                /** @var FinancialEntry|null $financialEntry */
                $financialEntry = $movement->financialEntries->first();
                $entryOpenAmount = $financialEntry ? max((float) ($financialEntry->valor_em_aberto ?? 0), 0) : null;
                $movementOpenAmount = round(max(abs((float) $movement->valor_total) - (float) ($financialEntry->valor_pago ?? 0), 0), 2);
                $openAmount = $entryOpenAmount !== null ? round($entryOpenAmount, 2) : $movementOpenAmount;
                $defaultCostCenterId = $movement->centro_custo_id
                    ?: $movement->user?->centrosCusto?->sortByDesc(fn ($center) => (float) ($center->pivot->peso ?? 1))->first()?->id;
                $family = $movement->user?->families?->first();

                return [
                    'id' => $movement->id,
                    'user_id' => $movement->user_id,
                    'user_name' => $movement->user?->nome_completo ?? $movement->user?->name ?? $movement->nome_manual,
                    'family_id' => $family?->id,
                    'family_name' => $family?->nome,
                    'financial_entry_id' => $financialEntry?->id,
                    'descricao' => $movement->observacoes ?: $movement->nome_manual ?: ('Movimento ' . $movement->tipo),
                    'tipo' => $movement->tipo,
                    'classificacao' => $movement->classificacao,
                    'valor_total' => (float) $movement->valor_total,
                    'valor_pago' => round(max((float) ($movement->valor_total ?? 0) - $openAmount, 0), 2),
                    'valor_em_aberto' => $openAmount,
                    'estado_pagamento' => $movement->estado_pagamento,
                    'data_emissao' => optional($movement->data_emissao)?->toDateString(),
                    'data_vencimento' => optional($movement->data_vencimento)?->toDateString(),
                    'centro_custo_id' => $movement->centro_custo_id,
                    'default_centro_custo_id' => $defaultCostCenterId,
                    'requires_centro_custo' => empty($defaultCostCenterId),
                    'centro_custo_name' => $movement->centroCusto?->nome,
                ];
            });

        $filteredCollection = $paginator->getCollection()
            ->filter(fn (array $movement): bool => (float) ($movement['valor_em_aberto'] ?? 0) > 0.009)
            ->values();

        $paginator->setCollection($filteredCollection);

        return response()->json($paginator);
    }

    private function normalizeInvoiceFinancialAmounts(Invoice $invoice): Invoice
    {
        $trackedPaidAmount = in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
            ? (float) ($invoice->valor_pago ?? 0)
            : 0.0;
        $confirmedAllocationPaid = (float) ($invoice->confirmed_payment_allocations_sum ?? 0);
        $legacyEntryPaid = (float) ($invoice->legacy_financial_entries_sum ?? 0);
        $paidAmount = round(max($trackedPaidAmount, $confirmedAllocationPaid, $legacyEntryPaid), 2);
        $fallbackOutstanding = max((float) $invoice->valor_total - $paidAmount, 0);
        $persistedOutstanding = $invoice->valor_em_aberto !== null
            ? max((float) $invoice->valor_em_aberto, 0)
            : null;

        if ($paidAmount > 0 && ($persistedOutstanding === null || abs($persistedOutstanding - $fallbackOutstanding) > 0.009)) {
            $invoice->valor_em_aberto = $fallbackOutstanding;
        // Legacy invoices can carry valor_em_aberto = 0 while still pending.
        } elseif ($invoice->estado_pagamento !== 'pago' && $fallbackOutstanding > 0 && ($persistedOutstanding === null || $persistedOutstanding <= 0)) {
            $invoice->valor_em_aberto = $fallbackOutstanding;
        } elseif ($persistedOutstanding !== null) {
            $invoice->valor_em_aberto = $persistedOutstanding;
        } else {
            $invoice->valor_em_aberto = $fallbackOutstanding;
        }

        $invoice->valor_pago = $paidAmount;

        if ($invoice->estado_pagamento !== 'cancelado') {
            if ($paidAmount > 0 && (float) $invoice->valor_em_aberto <= 0.009) {
                $invoice->estado_pagamento = 'pago';
            } elseif ($paidAmount > 0) {
                $invoice->estado_pagamento = 'parcial';
            }
        }

        $dueDate = $invoice->data_vencimento !== null
            ? Carbon::parse($invoice->data_vencimento)->startOfDay()
            : null;

        if (
            $dueDate !== null
            && in_array($invoice->estado_pagamento, ['pendente', 'vencido'], true)
            && (float) $invoice->valor_em_aberto > 0.009
            && $dueDate->lt(now()->startOfDay())
        ) {
            $invoice->estado_pagamento = 'vencido';
        }

        return $invoice;
    }

    private function buildFinancialMovementsPayload(Collection $movements, Collection $entries): Collection
    {
        $movementById = $movements
            ->filter(fn ($movement) => $movement instanceof Movement)
            ->keyBy(fn (Movement $movement) => (string) $movement->id);

        $canonicalEntries = $entries
            ->filter(fn ($entry) => $entry instanceof FinancialEntry)
            ->filter(function (FinancialEntry $entry): bool {
                if ($entry->fatura_id !== null) {
                    return false;
                }

                return $entry->origem_tipo === null
                    || !in_array($entry->origem_tipo, ['payment_allocation', 'account_credit'], true);
            })
            ->values();

        $movementIdsWithEntries = $canonicalEntries
            ->filter(fn (FinancialEntry $entry) => $entry->origem_tipo === 'movement' && !empty($entry->origem_id))
            ->pluck('origem_id')
            ->map(fn ($id) => (string) $id)
            ->unique();

        $canonicalItems = $canonicalEntries->map(function (FinancialEntry $entry) use ($movementById): array {
            $movement = $entry->origem_tipo === 'movement'
                ? $movementById->get((string) $entry->origem_id)
                : null;

            return $this->mapCanonicalFinancialMovement($entry, $movement);
        });

        $legacyItems = $movements
            ->filter(fn ($movement) => $movement instanceof Movement)
            ->reject(fn (Movement $movement) => $movementIdsWithEntries->contains((string) $movement->id))
            ->map(fn (Movement $movement): array => $this->mapLegacyFinancialMovement($movement));

        return $canonicalItems
            ->concat($legacyItems)
            ->sortByDesc('sort_date')
            ->values()
            ->map(function (array $item): array {
                unset($item['sort_date']);

                return $item;
            });
    }

    private function mapCanonicalFinancialMovement(FinancialEntry $entry, ?Movement $movement = null): array
    {
        $classificacao = $entry->tipo === 'despesa' ? 'despesa' : 'receita';
        $totalAmount = abs((float) ($entry->valor ?? 0));
        $bankStatementReconciled = (bool) ($entry->bankStatement?->conciliado)
            || (($entry->bankStatement?->conciliacao_status ?? null) === 'reconciled');
        $paidAmount = $entry->valor_pago !== null
            ? abs((float) $entry->valor_pago)
            : ($bankStatementReconciled || $entry->estado === 'pago' ? $totalAmount : null);
        $openAmount = $entry->valor_em_aberto !== null
            ? abs((float) $entry->valor_em_aberto)
            : ($bankStatementReconciled || $entry->estado === 'pago' ? 0.0 : $totalAmount);
        $emissionDate = $movement?->data_emissao
            ? Carbon::parse($movement->data_emissao)
            : ($entry->data ? Carbon::parse($entry->data) : null);
        $dueDate = $movement?->data_vencimento
            ? Carbon::parse($movement->data_vencimento)
            : $emissionDate;

        return [
            'id' => (string) ($movement?->id ?? $entry->id),
            'movimento_id' => $movement?->id ? (string) $movement->id : null,
            'financial_entry_id' => (string) $entry->id,
            'source_kind' => $movement ? 'movement' : 'financial_entry',
            'read_only' => $movement === null,
            'user_id' => $movement?->user_id ?? $entry->user_id,
            'nome_manual' => $movement?->nome_manual ?? $entry->entidade_nome ?? $entry->descricao,
            'nif_manual' => $movement?->nif_manual,
            'morada_manual' => $movement?->morada_manual,
            'classificacao' => $classificacao,
            'data_emissao' => $emissionDate?->toDateString() ?? now()->toDateString(),
            'data_vencimento' => $dueDate?->toDateString() ?? ($emissionDate?->toDateString() ?? now()->toDateString()),
            'valor_total' => $classificacao === 'despesa' ? -$totalAmount : $totalAmount,
            'valor_pago' => $paidAmount,
            'valor_em_aberto' => $openAmount,
            'estado_pagamento' => $this->resolveFinancialMovementState(
                $bankStatementReconciled && $movement === null ? 'pago' : ($entry->estado ?? 'pendente'),
                $dueDate,
                $openAmount,
            ),
            'numero_recibo' => $movement?->numero_recibo ?? $entry->documento_ref,
            'referencia_pagamento' => $movement?->referencia_pagamento ?? $entry->documento_ref,
            'metodo_pagamento' => $movement?->metodo_pagamento ?? $entry->metodo_pagamento,
            'comprovativo' => $movement?->comprovativo ?? $entry->comprovativo,
            'documento_original' => $movement?->documento_original ?? $entry->documento_original,
            'centro_custo_id' => $movement?->centro_custo_id ?? $entry->centro_custo_id,
            'tipo' => $movement?->tipo ?? $this->resolveFinancialMovementTypeFromEntry($entry),
            'origem_tipo' => $movement?->origem_tipo ?? $this->resolveFinancialMovementOriginType($entry->origem_tipo),
            'origem_id' => $movement?->origem_id ?? $entry->origem_id,
            'observacoes' => $movement?->observacoes ?? $entry->descricao,
            'created_at' => optional($movement?->created_at ?? $entry->created_at)?->toISOString(),
            'descricao_financeira' => $entry->descricao,
            'sort_date' => ($emissionDate ?? now())->toDateString(),
        ];
    }

    private function mapLegacyFinancialMovement(Movement $movement): array
    {
        $totalAmount = abs((float) $movement->valor_total);
        $paidAmount = match ($movement->estado_pagamento) {
            'pago' => $totalAmount,
            'pendente', 'vencido', 'cancelado' => 0.0,
            default => null,
        };
        $openAmount = match ($movement->estado_pagamento) {
            'pago', 'cancelado' => 0.0,
            'pendente', 'vencido' => $totalAmount,
            default => null,
        };
        $displayOriginId = $this->resolveMovementOriginDisplayId($movement->origem_id, $movement->observacoes);
        $cleanObservacoes = $this->stripMovementOriginReference($movement->observacoes);
        $emissionDate = $movement->data_emissao ? Carbon::parse($movement->data_emissao) : null;
        $dueDate = $movement->data_vencimento ? Carbon::parse($movement->data_vencimento) : $emissionDate;

        return [
            'id' => (string) $movement->id,
            'movimento_id' => (string) $movement->id,
            'financial_entry_id' => null,
            'source_kind' => 'movement',
            'read_only' => false,
            'user_id' => $movement->user_id,
            'nome_manual' => $movement->nome_manual,
            'nif_manual' => $movement->nif_manual,
            'morada_manual' => $movement->morada_manual,
            'classificacao' => $movement->classificacao,
            'data_emissao' => $emissionDate?->toDateString() ?? now()->toDateString(),
            'data_vencimento' => $dueDate?->toDateString() ?? ($emissionDate?->toDateString() ?? now()->toDateString()),
            'valor_total' => (float) $movement->valor_total,
            'valor_pago' => $paidAmount,
            'valor_em_aberto' => $openAmount,
            'estado_pagamento' => $this->resolveFinancialMovementState($movement->estado_pagamento, $dueDate, $openAmount),
            'numero_recibo' => $movement->numero_recibo,
            'referencia_pagamento' => $movement->referencia_pagamento,
            'metodo_pagamento' => $movement->metodo_pagamento,
            'comprovativo' => $movement->comprovativo,
            'documento_original' => $movement->documento_original,
            'centro_custo_id' => $movement->centro_custo_id,
            'tipo' => $movement->tipo,
            'origem_tipo' => $movement->origem_tipo,
            'origem_id' => $displayOriginId,
            'observacoes' => $cleanObservacoes,
            'created_at' => optional($movement->created_at)?->toISOString(),
            'descricao_financeira' => $cleanObservacoes,
            'sort_date' => ($emissionDate ?? now())->toDateString(),
        ];
    }

    private function decorateMovementForResponse(Movement $movement): Movement
    {
        $movement->origem_id = $this->resolveMovementOriginDisplayId($movement->origem_id, $movement->observacoes);
        $movement->observacoes = $this->stripMovementOriginReference($movement->observacoes);

        return $movement;
    }

    /**
     * @param Collection<string, string> $movementIdsByStatement
     * @param Collection<string, Movement> $movementMap
     * @return array<string, mixed>
     */
    private function serializeBankStatementForResponse(BankStatement $extrato, Collection $movementIdsByStatement, Collection $movementMap): array
    {
        $extrato->valor = (float) $extrato->valor;
        $extrato->saldo = $extrato->saldo !== null ? (float) $extrato->saldo : null;

        $movementId = null;

        if ($extrato->financialEntry?->origem_tipo === 'movement' && !empty($extrato->financialEntry?->origem_id)) {
            $movementId = (string) $extrato->financialEntry->origem_id;
        } elseif ($movementIdsByStatement->has((string) $extrato->id)) {
            $movementId = (string) $movementIdsByStatement->get((string) $extrato->id);
        }

        $movement = $movementId ? $movementMap->get($movementId) : null;

        return [
            'id' => (string) $extrato->id,
            'data_movimento' => optional($extrato->data_movimento)?->toDateString(),
            'descricao' => $extrato->descricao,
            'valor' => (float) $extrato->valor,
            'saldo' => $extrato->saldo !== null ? (float) $extrato->saldo : null,
            'referencia' => $extrato->referencia,
            'ficheiro_id' => $extrato->ficheiro_id,
            'centro_custo_id' => $extrato->centro_custo_id,
            'conciliado' => (bool) $extrato->conciliado,
            'valor_conciliado' => $extrato->valor_conciliado !== null ? (float) $extrato->valor_conciliado : null,
            'valor_por_conciliar' => $extrato->valor_por_conciliar !== null ? (float) $extrato->valor_por_conciliar : null,
            'conciliacao_status' => $extrato->conciliacao_status,
            'lancamento_id' => $extrato->lancamento_id,
            'movement_id' => $movementId,
            'movement_estado_documental' => $movement?->estado_documental,
            'created_at' => optional($extrato->created_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMovementDetail(Movement $movement): array
    {
        $movement = $this->decorateMovementForResponse($movement);
        $evaluation = $this->movementDocumentControlService->evaluate($movement);
        $mainEntry = $movement->financialEntries->first();
        $bankStatement = $mainEntry?->bankStatement;
        $reconciliationMap = $mainEntry?->reconciliationMap;

        return [
            'id' => (string) $movement->id,
            'descricao' => $this->resolveMovementDetailDescription($movement),
            'nome_manual' => $movement->nome_manual,
            'supplier' => $movement->supplier ? [
                'id' => (string) $movement->supplier->id,
                'nome' => $movement->supplier->nome,
                'nif' => $movement->supplier->nif,
                'morada' => $movement->supplier->morada,
            ] : null,
            'classificacao' => $movement->classificacao,
            'categoria' => $movement->categoria,
            'tipo' => $movement->tipo,
            'centro_custo' => $movement->centroCusto ? [
                'id' => (string) $movement->centroCusto->id,
                'nome' => $movement->centroCusto->nome,
            ] : null,
            'valor_total' => (float) $movement->valor_total,
            'data_emissao' => optional($movement->data_emissao)?->toDateString(),
            'data_vencimento' => optional($movement->data_vencimento)?->toDateString(),
            'estado_pagamento' => $movement->estado_pagamento,
            'estado_documental' => $movement->estado_documental,
            'estado_conciliacao' => $movement->estado_conciliacao,
            'origem_tipo' => $movement->origem_tipo,
            'origem_id' => $movement->origem_id,
            'metodo_pagamento' => $movement->metodo_pagamento,
            'numero_recibo' => $movement->numero_recibo,
            'referencia_pagamento' => $movement->referencia_pagamento,
            'observacoes' => $movement->observacoes,
            'document_control_status' => $movement->document_control_status,
            'missing_documents' => $evaluation['missing_documents'],
            'document_requirement' => $evaluation['requirement'],
            'items' => $movement->items->map(fn (MovementItem $item): array => [
                'id' => (string) $item->id,
                'descricao' => $item->descricao,
                'quantidade' => (int) $item->quantidade,
                'valor_unitario' => (float) $item->valor_unitario,
                'imposto_percentual' => (float) ($item->imposto_percentual ?? 0),
                'total_linha' => (float) $item->total_linha,
                'centro_custo' => $item->centroCusto ? [
                    'id' => (string) $item->centroCusto->id,
                    'nome' => $item->centroCusto->nome,
                ] : null,
            ])->values()->all(),
            'documents' => $movement->documents->sortByDesc('created_at')->values()->map(fn (MovementDocument $document): array => $this->serializeMovementDocument($document))->all(),
            'conciliation' => [
                'bank_statement' => $bankStatement ? [
                    'id' => (string) $bankStatement->id,
                    'data_movimento' => optional($bankStatement->data_movimento)?->toDateString(),
                    'descricao' => $bankStatement->descricao,
                    'valor' => (float) $bankStatement->valor,
                    'referencia' => $bankStatement->referencia,
                    'conciliado' => (bool) $bankStatement->conciliado,
                    'conciliacao_status' => $bankStatement->conciliacao_status,
                    'valor_conciliado' => (float) ($bankStatement->valor_conciliado ?? 0),
                    'valor_por_conciliar' => $bankStatement->valor_por_conciliar !== null ? (float) $bankStatement->valor_por_conciliar : null,
                ] : null,
                'reconciliation_map' => $reconciliationMap ? [
                    'id' => (string) $reconciliationMap->id,
                    'descricao' => $reconciliationMap->descricao,
                    'created_at' => optional($reconciliationMap->created_at)?->toISOString(),
                ] : null,
                'estado_conciliacao' => $movement->estado_conciliacao,
            ],
            'history' => $this->buildMovementHistory($movement),
            'created_at' => optional($movement->created_at)?->toISOString(),
            'updated_at' => optional($movement->updated_at)?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMovementDocument(MovementDocument $document): array
    {
        return [
            'id' => (string) $document->id,
            'document_type' => $document->document_type,
            'source_type' => $document->source_type,
            'source_id' => $document->source_id,
            'document_number' => $document->document_number,
            'issue_date' => optional($document->issue_date)?->toDateString(),
            'due_date' => optional($document->due_date)?->toDateString(),
            'amount' => $document->amount !== null ? (float) $document->amount : null,
            'vat_amount' => $document->vat_amount !== null ? (float) $document->vat_amount : null,
            'status' => $document->status,
            'original_filename' => $document->original_filename,
            'stored_path' => $document->stored_path,
            'file_url' => $document->stored_path ? Storage::disk('public')->url($document->stored_path) : null,
            'validated_at' => optional($document->validated_at)?->toISOString(),
            'validator' => $document->validator ? [
                'id' => (string) $document->validator->id,
                'name' => $document->validator->nome_completo ?: $document->validator->name,
            ] : null,
            'supplier' => $document->supplier ? [
                'id' => (string) $document->supplier->id,
                'nome' => $document->supplier->nome,
            ] : null,
            'notes' => $document->notes,
            'created_at' => optional($document->created_at)?->toISOString(),
            'updated_at' => optional($document->updated_at)?->toISOString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeAvailableBankStatementsForMovement(Movement $movement): array
    {
        return BankStatement::query()
            ->where('centro_custo_id', $movement->centro_custo_id)
            ->where(function ($query): void {
                $query->where('conciliado', false)
                    ->orWhereIn('conciliacao_status', ['unreconciled', 'partial'])
                    ->orWhereNull('conciliacao_status');
            })
            ->orderByDesc('data_movimento')
            ->limit(25)
            ->get()
            ->map(fn (BankStatement $statement): array => [
                'id' => (string) $statement->id,
                'data_movimento' => optional($statement->data_movimento)?->toDateString(),
                'descricao' => $statement->descricao,
                'referencia' => $statement->referencia,
                'valor' => (float) $statement->valor,
                'conciliado' => (bool) $statement->conciliado,
                'conciliacao_status' => $statement->conciliacao_status,
                'valor_por_conciliar' => $statement->valor_por_conciliar !== null ? (float) $statement->valor_por_conciliar : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildMovementHistory(Movement $movement): array
    {
        $events = collect([
            [
                'type' => 'movement_created',
                'label' => 'Movimento criado',
                'at' => optional($movement->created_at)?->toISOString(),
                'details' => $movement->origem_tipo ? 'Origem: ' . $movement->origem_tipo : 'Criado manualmente no módulo Financeiro.',
            ],
            [
                'type' => 'current_state',
                'label' => 'Estado atual',
                'at' => optional($movement->updated_at ?? $movement->created_at)?->toISOString(),
                'details' => sprintf(
                    'Pagamento: %s. Documental: %s. Conciliação: %s.',
                    (string) $movement->estado_pagamento,
                    (string) $movement->estado_documental,
                    (string) $movement->estado_conciliacao,
                ),
            ],
        ]);

        foreach ($movement->documents as $document) {
            $events->push([
                'type' => 'document_attached',
                'label' => 'Documento anexado',
                'at' => optional($document->created_at)?->toISOString(),
                'details' => trim(($document->document_type ?? 'documento') . ' ' . ($document->document_number ?? '')),
            ]);

            if (in_array($document->status, ['valid', 'rejected', 'duplicate'], true)) {
                $events->push([
                    'type' => 'document_status',
                    'label' => 'Documento ' . $document->status,
                    'at' => optional($document->validated_at ?? $document->updated_at)?->toISOString(),
                    'details' => $document->validator?->nome_completo ?: $document->validator?->name,
                ]);
            }
        }

        $entry = $movement->financialEntries->first();
        if ($entry?->bankStatement) {
            $events->push([
                'type' => 'bank_reconciliation',
                'label' => 'Conciliação bancária',
                'at' => optional($entry->bankStatement->data_movimento)?->toDateString(),
                'details' => $entry->bankStatement->descricao,
            ]);
        }

        return $events
            ->filter(fn (array $event): bool => !empty($event['at']))
            ->sortByDesc('at')
            ->values()
            ->all();
    }

    private function resolveMovementDetailDescription(Movement $movement): string
    {
        $firstItemDescription = $movement->items->first()?->descricao;

        return (string) ($movement->referencia_pagamento
            ?? $firstItemDescription
            ?? $movement->categoria
            ?? $movement->nome_manual
            ?? 'Movimento financeiro');
    }

    private function canManageMovementDocuments(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return in_array((string) $user->perfil, ['admin', 'administrador', 'gestor', 'financeiro'], true);
    }

    private function ensureCanManageMovementDocuments(?User $user): void
    {
        abort_unless($this->canManageMovementDocuments($user), 403);
    }

    private function ensureDocumentBelongsToMovement(Movement $movement, MovementDocument $document): void
    {
        abort_unless((string) $document->movement_id === (string) $movement->id, 404);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function sanitizeMovementOriginData(array $data): array
    {
        $originId = isset($data['origem_id']) && is_string($data['origem_id'])
            ? trim($data['origem_id'])
            : null;

        $cleanObservacoes = $this->stripMovementOriginReference($data['observacoes'] ?? null);

        if (!$originId) {
            $data['origem_id'] = null;
            $data['observacoes'] = $cleanObservacoes;

            return $data;
        }

        if (Str::isUuid($originId)) {
            $data['origem_id'] = $originId;
            $data['observacoes'] = $cleanObservacoes;

            return $data;
        }

        $data['origem_id'] = null;
        $data['observacoes'] = $this->mergeMovementOriginReference($cleanObservacoes, $originId);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function hydrateMovementCounterpartyData(array $data): array
    {
        if (!empty($data['user_id'])) {
            $data['supplier_id'] = null;

            return $data;
        }

        if (empty($data['supplier_id'])) {
            return $data;
        }

        $supplier = Supplier::query()->findOrFail($data['supplier_id']);

        $data['user_id'] = null;
        $data['nome_manual'] = $supplier->nome;
        $data['nif_manual'] = $supplier->nif;
        $data['morada_manual'] = $supplier->morada;

        return $data;
    }

    private function resolveMovementOriginDisplayId(?string $originId, ?string $observacoes): ?string
    {
        if (!empty($originId)) {
            return $originId;
        }

        return $this->extractMovementOriginReference($observacoes);
    }

    private function extractMovementOriginReference(?string $observacoes): ?string
    {
        if (!$observacoes) {
            return null;
        }

        foreach (preg_split('/\r\n|\r|\n/', $observacoes) ?: [] as $line) {
            if (str_starts_with($line, self::MOVEMENT_ORIGIN_REFERENCE_PREFIX)) {
                $reference = trim(substr($line, strlen(self::MOVEMENT_ORIGIN_REFERENCE_PREFIX)));

                return $reference !== '' ? $reference : null;
            }
        }

        return null;
    }

    private function stripMovementOriginReference(?string $observacoes): ?string
    {
        if (!$observacoes) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $observacoes) ?: [];
        $filtered = array_values(array_filter($lines, fn (string $line): bool => !str_starts_with($line, self::MOVEMENT_ORIGIN_REFERENCE_PREFIX)));
        $clean = trim(implode("\n", $filtered));

        return $clean !== '' ? $clean : null;
    }

    private function mergeMovementOriginReference(?string $observacoes, string $reference): string
    {
        $parts = array_filter([
            $this->stripMovementOriginReference($observacoes),
            self::MOVEMENT_ORIGIN_REFERENCE_PREFIX . trim($reference),
        ]);

        return implode("\n", $parts);
    }

    private function resolveFinancialMovementTypeFromEntry(FinancialEntry $entry): string
    {
        return match ($entry->origem_tipo) {
            'stock' => 'material',
            'patrocinio' => 'patrocinio',
            'bank_statement' => 'servico',
            default => 'outro',
        };
    }

    private function resolveFinancialMovementOriginType(?string $originType): ?string
    {
        return in_array($originType, ['evento', 'stock', 'patrocinio', 'manual', 'bank_statement'], true)
            ? $originType
            : null;
    }

    private function resolveFinancialMovementState(string $state, ?Carbon $dueDate, ?float $openAmount): string
    {
        if ($state === 'cancelado') {
            return 'cancelado';
        }

        if ($state === 'pago') {
            return 'pago';
        }

        if (in_array($state, ['parcial', 'pago_parcial'], true)) {
            return 'pago_parcial';
        }

        if ($state === 'por_pagar') {
            return 'por_pagar';
        }

        if (
            $dueDate !== null
            && ($openAmount === null || $openAmount > 0.009)
            && $dueDate->lt(now()->startOfDay())
        ) {
            return 'vencido';
        }

        return 'por_pagar';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeManualMovementRequestData(Request $request, array $data): array
    {
        if ($request->hasFile('attachment') && !$request->hasFile('documento_original')) {
            $data['attachment'] = $request->file('attachment');
        }

        $data['categoria'] = $data['categoria'] ?? null;
        $data['document_date'] = $data['document_date'] ?? ($data['data_emissao'] ?? null);
        $data['due_date'] = $data['due_date'] ?? ($data['data_vencimento'] ?? null);
        $data['metodo_pagamento'] = $data['payment_method'] ?? ($data['metodo_pagamento'] ?? null);
        $data['observacoes'] = $data['notes'] ?? ($data['observacoes'] ?? null);

        if (!empty($data['document_date'])) {
            $data['data_emissao'] = $data['document_date'];
        }

        if (!empty($data['due_date'])) {
            $data['data_vencimento'] = $data['due_date'];
        }

        $data['estado_pagamento'] = match ($data['estado_pagamento'] ?? null) {
            'pendente', null => 'por_pagar',
            'parcial' => 'pago_parcial',
            default => $data['estado_pagamento'],
        };

        return $data;
    }

    private function invoiceFinancialSnapshotQuery()
    {
        return $this->applyInvoiceFinancialSnapshotColumns(Invoice::query());
    }

    private function applyInvoiceFinancialSnapshotColumns($query)
    {
        return $query
            ->withSum([
                'paymentAllocations as confirmed_payment_allocations_sum' => function ($paymentAllocationQuery): void {
                    $paymentAllocationQuery->where('status', PaymentAllocation::STATUS_CONFIRMED);
                },
            ], 'amount')
            ->selectSub(
                $this->invoicePaymentEntriesQuery()
                    ->selectRaw('COALESCE(SUM(valor), 0)')
                    ->whereColumn('fatura_id', 'invoices.id'),
                'legacy_financial_entries_sum'
            );
    }

    private function invoicePaymentEntriesQuery()
    {
        return FinancialEntry::query()
            ->where(function ($query): void {
                $query
                    ->where('origem_tipo', 'payment_allocation')
                    ->orWhere('origem_tipo', 'manual')
                    ->orWhere(function ($legacyQuery): void {
                        $legacyQuery
                            ->whereNull('origem_tipo')
                            ->where('tipo', 'receita')
                            ->where('categoria', 'Pagamento de Fatura');
                    });
            });
    }

    public function unreconciledBankStatements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'exists:users,id'],
            'family_id' => ['nullable', 'exists:familias,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $perPage = (int) ($data['per_page'] ?? 25);
        $search = trim((string) ($data['search'] ?? ''));

        $query = BankStatement::query()
            ->where(function ($nestedQuery) {
                $nestedQuery
                    ->where('conciliado', false)
                    ->orWhere('conciliacao_status', '!=', 'reconciled')
                    ->orWhereNull('conciliacao_status');
            });

        if ($search !== '') {
            $operator = $this->searchOperator();

            $query->where(function ($nestedQuery) use ($search, $operator) {
                $nestedQuery
                    ->where('descricao', $operator, "%{$search}%")
                    ->orWhere('referencia', $operator, "%{$search}%")
                    ->orWhere('conta', $operator, "%{$search}%");
            });
        }

        if (isset($data['amount'])) {
            $query->where('valor', (float) $data['amount']);
        }

        if (!empty($data['date_from'])) {
            $query->whereDate('data_movimento', '>=', $data['date_from']);
        }

        if (!empty($data['date_to'])) {
            $query->whereDate('data_movimento', '<=', $data['date_to']);
        }

        if (!empty($data['user_id'])) {
            $query->whereHas('payments', function ($paymentQuery) use ($data) {
                $paymentQuery->where('user_id', $data['user_id']);
            });
        }

        if (!empty($data['family_id'])) {
            $query->whereHas('payments', function ($paymentQuery) use ($data) {
                $paymentQuery->where('family_id', $data['family_id']);
            });
        }

        $paginator = $query
            ->orderByDesc('data_movimento')
            ->paginate($perPage)
            ->through(function (BankStatement $statement) {
                $valorConciliado = (float) ($statement->valor_conciliado ?? 0);
                $valorPorConciliar = $statement->valor_por_conciliar !== null
                    ? (float) $statement->valor_por_conciliar
                    : max(abs((float) $statement->valor) - $valorConciliado, 0);

                return [
                    'id' => $statement->id,
                    'data_movimento' => optional($statement->data_movimento)?->toDateString(),
                    'descricao' => $statement->descricao,
                    'referencia' => $statement->referencia,
                    'valor' => (float) $statement->valor,
                    'conta' => $statement->conta,
                    'conciliado' => (bool) $statement->conciliado,
                    'valor_conciliado' => $valorConciliado,
                    'valor_por_conciliar' => $valorPorConciliar,
                    'conciliacao_status' => $statement->conciliacao_status ?: ((bool) $statement->conciliado ? 'reconciled' : 'unreconciled'),
                ];
            });

        return response()->json($paginator);
    }

    private function searchOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    private function invoiceHasFinancialTrail(Invoice $invoice): bool
    {
        $invoice = $invoice->fresh();

        $hasFiscalRequest = FiscalDocumentRequest::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->exists();

        $hasExternalFiscalDocument = FiscalDocumentRequest::withTrashed()
            ->where('invoice_id', $invoice->id)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('external_document_number')
                    ->where('external_document_number', '!=', '')
                    ->orWhere(function ($providerQuery): void {
                        $providerQuery
                            ->where('provider', FiscalDocumentRequest::PROVIDER_WINTOUCH)
                            ->whereNotNull('external_document_id');
                    });
            })
            ->exists();

        return in_array($invoice->estado_pagamento, ['pago', 'parcial'], true)
            || (float) ($invoice->valor_pago ?? 0) > 0
            || filled($invoice->numero_recibo)
            || $invoice->paymentAllocations()->exists()
            || $invoice->payments()->exists()
            || MapaConciliacao::query()->where('fatura_id', $invoice->id)->exists()
            || $hasFiscalRequest
            || $hasExternalFiscalDocument;
    }

    public function storeMovimento(Request $request)
    {
        if (is_string($request->input('items'))) {
            $decoded = json_decode($request->input('items'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge(['items' => $decoded]);
            }
        }
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'nome_manual' => ['nullable', 'string', 'max:255'],
            'nif_manual' => ['nullable', 'string', 'max:50'],
            'morada_manual' => ['nullable', 'string'],
            'classificacao' => ['required', 'in:receita,despesa'],
            'categoria' => ['nullable', 'string', 'max:255'],
            'data_emissao' => ['nullable', 'date', 'required_without:document_date'],
            'data_vencimento' => ['nullable', 'date', 'required_without:due_date'],
            'valor_total' => ['required', 'numeric'],
            'estado_pagamento' => ['nullable', 'in:pendente,por_pagar,pago,vencido,parcial,pago_parcial,cancelado'],
            'estado_conciliacao' => ['nullable', 'in:nao_conciliado,sugerido,conciliado,divergente'],
            'numero_recibo' => ['nullable', 'string', 'max:255'],
            'referencia_pagamento' => ['nullable', 'string', 'max:255'],
            'metodo_pagamento' => ['nullable', 'string', 'max:50'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
            'tipo' => ['required', 'string', 'max:30'],
            'origem_tipo' => ['nullable', 'in:evento,stock,patrocinio,manual,bank_statement'],
            'origem_id' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'documento_original' => ['nullable', 'file'],
            'attachment' => ['nullable', 'file'],
            'document_type' => ['nullable', 'in:invoice,receipt,invoice_receipt,payment_proof,credit_note,other'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'vat_amount' => ['nullable', 'numeric'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descricao' => ['required', 'string', 'max:255'],
            'items.*.quantidade' => ['required', 'integer', 'min:1'],
            'items.*.valor_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.imposto_percentual' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_linha' => ['required', 'numeric', 'min:0'],
            'items.*.produto_id' => ['nullable', 'exists:products,id'],
            'items.*.centro_custo_id' => ['nullable', 'exists:cost_centers,id'],
            'items.*.fatura_id' => ['nullable', 'string', 'max:255'],
        ]);

        $data = $this->normalizeManualMovementRequestData($request, $data);
        $data = $this->sanitizeMovementOriginData($data);

        if (($data['estado_conciliacao'] ?? 'nao_conciliado') === 'conciliado') {
            throw ValidationException::withMessages([
                'estado_conciliacao' => 'A conciliacao do movimento tem de ser efetuada pelo fluxo canonico de conciliacao/alocacao.',
            ]);
        }

        if ($data['classificacao'] === 'despesa') {
            $result = $this->manualExpenseService->createSimpleExpense($data, $request->user());

            $this->invalidateFinanceiroCaches();

            return response()->json([
                'movimento' => $this->decorateMovementForResponse($result['movement']),
                'items' => $result['items'],
                'lancamento' => $result['financial_entry'],
                'documento' => $result['document'],
            ]);
        }

        if (in_array($data['estado_pagamento'] ?? 'pendente', ['pago', 'parcial'], true)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A liquidacao do movimento tem de ser efetuada pelo fluxo de pagamento.',
            ]);
        }

        if (!empty($data['user_id']) && !empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Selecione apenas um utilizador ou um fornecedor.',
            ]);
        }

        $data = $this->hydrateMovementCounterpartyData($data);

        if (empty($data['user_id']) && empty($data['nome_manual'])) {
            $data['nome_manual'] = app(ClubSettingsService::class)->defaultFinancialEntityName($data['classificacao'] ?? null);
        }

        if ($request->hasFile('documento_original')) {
            $data['documento_original'] = $request->file('documento_original')->store('financeiro/movimentos', 'public');
        }

        $movimento = Movement::create([
            'user_id' => $data['user_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'nome_manual' => $data['nome_manual'] ?? null,
            'nif_manual' => $data['nif_manual'] ?? null,
            'morada_manual' => $data['morada_manual'] ?? null,
            'classificacao' => $data['classificacao'],
            'data_emissao' => $data['data_emissao'],
            'data_vencimento' => $data['data_vencimento'],
            'valor_total' => $data['valor_total'],
            'estado_pagamento' => $data['estado_pagamento'] ?? 'pendente',
            'numero_recibo' => $data['numero_recibo'] ?? null,
            'referencia_pagamento' => $data['referencia_pagamento'] ?? null,
            'metodo_pagamento' => $data['metodo_pagamento'] ?? null,
            'comprovativo' => $data['comprovativo'] ?? null,
            'documento_original' => $data['documento_original'] ?? null,
            'centro_custo_id' => $data['centro_custo_id'],
            'tipo' => $data['tipo'],
            'origem_tipo' => $data['origem_tipo'] ?? null,
            'origem_id' => $data['origem_id'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
        ]);

        $createdItems = [];
        foreach ($data['items'] as $item) {
            $createdItems[] = MovementItem::create([
                'movimento_id' => $movimento->id,
                'descricao' => $item['descricao'],
                'quantidade' => $item['quantidade'],
                'valor_unitario' => $item['valor_unitario'],
                'imposto_percentual' => $item['imposto_percentual'] ?? 0,
                'total_linha' => $item['total_linha'],
                'produto_id' => $item['produto_id'] ?? null,
                'centro_custo_id' => $item['centro_custo_id'] ?? $data['centro_custo_id'],
                'fatura_id' => $item['fatura_id'] ?? null,
            ]);

            if (!empty($item['produto_id']) && ($data['origem_tipo'] ?? null) === 'stock') {
                $delta = $data['classificacao'] === 'despesa' ? (int) $item['quantidade'] : -((int) $item['quantidade']);
                Product::where('id', $item['produto_id'])->increment('stock', $delta);
            }
        }

        $this->invalidateFinanceiroCaches();

        $movimento->refresh();
        $movimento = $this->decorateMovementForResponse($movimento);

        return response()->json([
            'movimento' => $movimento,
            'items' => $createdItems,
        ]);
    }

    public function updateMovimento(Request $request, Movement $movimento)
    {
        if (is_string($request->input('items'))) {
            $decoded = json_decode($request->input('items'), true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge(['items' => $decoded]);
            }
        }
        $data = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'nome_manual' => ['nullable', 'string', 'max:255'],
            'nif_manual' => ['nullable', 'string', 'max:50'],
            'morada_manual' => ['nullable', 'string'],
            'classificacao' => ['required', 'in:receita,despesa'],
            'categoria' => ['nullable', 'string', 'max:255'],
            'data_emissao' => ['nullable', 'date'],
            'data_vencimento' => ['nullable', 'date'],
            'valor_total' => ['required', 'numeric'],
            'estado_pagamento' => ['nullable', 'in:pendente,por_pagar,pago,vencido,parcial,pago_parcial,cancelado'],
            'estado_conciliacao' => ['nullable', 'in:nao_conciliado,sugerido,conciliado,divergente'],
            'numero_recibo' => ['nullable', 'string', 'max:255'],
            'referencia_pagamento' => ['nullable', 'string', 'max:255'],
            'metodo_pagamento' => ['nullable', 'string', 'max:50'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
            'tipo' => ['required', 'string', 'max:30'],
            'origem_tipo' => ['nullable', 'in:evento,stock,patrocinio,manual,bank_statement'],
            'origem_id' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'documento_original' => ['nullable', 'file'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.descricao' => ['required', 'string', 'max:255'],
            'items.*.quantidade' => ['required', 'integer', 'min:1'],
            'items.*.valor_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.imposto_percentual' => ['nullable', 'numeric', 'min:0'],
            'items.*.total_linha' => ['required', 'numeric', 'min:0'],
            'items.*.produto_id' => ['nullable', 'exists:products,id'],
            'items.*.centro_custo_id' => ['nullable', 'exists:cost_centers,id'],
            'items.*.fatura_id' => ['nullable', 'string', 'max:255'],
        ]);

        $requestedPaymentState = $data['estado_pagamento'] ?? $movimento->estado_pagamento;
        $requestedReconciliationState = $data['estado_conciliacao'] ?? $movimento->estado_conciliacao;

        $paymentStateChanged = $requestedPaymentState !== $movimento->estado_pagamento;
        $reconciliationStateChanged = $requestedReconciliationState !== $movimento->estado_conciliacao;

        if (
            $paymentStateChanged
            && (
                in_array($requestedPaymentState, ['pago', 'parcial', 'pago_parcial'], true)
                || in_array($movimento->estado_pagamento, ['pago', 'parcial', 'pago_parcial'], true)
            )
        ) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A liquidacao ou reabertura do movimento tem de ser efetuada pelo fluxo canonico de pagamento.',
            ]);
        }

        if (
            $reconciliationStateChanged
            && (
                $requestedReconciliationState === 'conciliado'
                || $movimento->estado_conciliacao === 'conciliado'
            )
        ) {
            throw ValidationException::withMessages([
                'estado_conciliacao' => 'A alteracao do estado de conciliacao tem de ser efetuada pelo fluxo canonico de conciliacao.',
            ]);
        }

        if (
            in_array($requestedPaymentState, ['pago', 'parcial'], true)
            && !in_array($movimento->estado_pagamento, ['pago', 'parcial'], true)
        ) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A liquidacao do movimento tem de ser efetuada pelo fluxo de pagamento.',
            ]);
        }

        if (!empty($data['user_id']) && !empty($data['supplier_id'])) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Selecione apenas um utilizador ou um fornecedor.',
            ]);
        }

        $data = $this->hydrateMovementCounterpartyData($data);

        if (empty($data['user_id']) && empty($data['nome_manual'])) {
            $data['nome_manual'] = app(ClubSettingsService::class)->defaultFinancialEntityName($data['classificacao'] ?? null);
        }

        $data = $this->sanitizeMovementOriginData($data);

        if ($request->hasFile('documento_original')) {
            if ($movimento->documento_original) {
                Storage::disk('public')->delete($movimento->documento_original);
            }
            $data['documento_original'] = $request->file('documento_original')->store('financeiro/movimentos', 'public');
        }

        $movimento->update([
            'user_id' => $data['user_id'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'nome_manual' => $data['nome_manual'] ?? null,
            'nif_manual' => $data['nif_manual'] ?? null,
            'morada_manual' => $data['morada_manual'] ?? null,
            'classificacao' => $data['classificacao'],
            'categoria' => $data['categoria'] ?? $movimento->categoria,
            'data_emissao' => $data['data_emissao'],
            'data_vencimento' => $data['data_vencimento'],
            'valor_total' => $data['valor_total'],
            'estado_pagamento' => $requestedPaymentState,
            'numero_recibo' => $data['numero_recibo'] ?? null,
            'referencia_pagamento' => $data['referencia_pagamento'] ?? null,
            'metodo_pagamento' => $data['metodo_pagamento'] ?? null,
            'documento_original' => $data['documento_original'] ?? $movimento->documento_original,
            'centro_custo_id' => $data['centro_custo_id'],
            'tipo' => $data['tipo'],
            'origem_tipo' => $data['origem_tipo'] ?? null,
            'origem_id' => $data['origem_id'] ?? null,
            'observacoes' => $data['observacoes'] ?? null,
        ]);

        $existingItems = MovementItem::where('movimento_id', $movimento->id)->get();
        $existingByProduct = $existingItems
            ->filter(fn ($item) => !empty($item->produto_id))
            ->groupBy('produto_id')
            ->map(fn ($group) => (int) $group->sum('quantidade'));

        MovementItem::where('movimento_id', $movimento->id)->delete();

        $createdItems = [];
        $newByProduct = [];
        foreach ($data['items'] as $item) {
            $createdItems[] = MovementItem::create([
                'movimento_id' => $movimento->id,
                'descricao' => $item['descricao'],
                'quantidade' => $item['quantidade'],
                'valor_unitario' => $item['valor_unitario'],
                'imposto_percentual' => $item['imposto_percentual'] ?? 0,
                'total_linha' => $item['total_linha'],
                'produto_id' => $item['produto_id'] ?? null,
                'centro_custo_id' => $item['centro_custo_id'] ?? $data['centro_custo_id'],
                'fatura_id' => $item['fatura_id'] ?? null,
            ]);

            if (!empty($item['produto_id']) && ($data['origem_tipo'] ?? null) === 'stock') {
                $newByProduct[$item['produto_id']] = ($newByProduct[$item['produto_id']] ?? 0) + (int) $item['quantidade'];
            }
        }

        if (($data['origem_tipo'] ?? null) === 'stock') {
            $allProductIds = collect($existingByProduct->keys())
                ->merge(array_keys($newByProduct))
                ->unique();

            foreach ($allProductIds as $productId) {
                $previous = (int) ($existingByProduct[$productId] ?? 0);
                $next = (int) ($newByProduct[$productId] ?? 0);
                $delta = $next - $previous;
                if ($delta > 0) {
                    $adjust = $data['classificacao'] === 'despesa' ? $delta : -$delta;
                    Product::where('id', $productId)->increment('stock', $adjust);
                } elseif ($delta < 0) {
                    $adjust = $data['classificacao'] === 'despesa' ? abs($delta) : -abs($delta);
                    Product::where('id', $productId)->increment('stock', $adjust);
                }
            }
        }

        $this->invalidateFinanceiroCaches();

        $movimento->refresh();
        $movimento = $this->decorateMovementForResponse($movimento);

        return response()->json([
            'movimento' => $movimento,
            'items' => $createdItems,
        ]);
    }

    public function destroyMovimento(Movement $movimento)
    {
        if ($movimento->documento_original) {
            Storage::disk('public')->delete($movimento->documento_original);
        }
        if ($movimento->comprovativo) {
            Storage::disk('public')->delete($movimento->comprovativo);
        }

        MovementItem::where('movimento_id', $movimento->id)->delete();
        $movimento->delete();

        $this->invalidateFinanceiroCaches();

        return response()->json(['success' => true]);
    }

    public function liquidarMovimento(Request $request, Movement $movimento)
    {
        $data = $request->validate([
            'numero_recibo' => ['nullable', 'string', 'max:255'],
            'metodo_pagamento' => ['nullable', 'string', 'max:50'],
            'bank_statement_id' => ['nullable', 'exists:bank_statements,id'],
        ]);

        if ($request->hasFile('comprovativo')) {
            if ($movimento->comprovativo) {
                Storage::disk('public')->delete($movimento->comprovativo);
            }
            $movimento->comprovativo = $request->file('comprovativo')->store('financeiro/movimentos', 'public');
        }

        $financialEntry = $this->financialSettlementService->findOrCreateFinancialEntryForMovement($movimento, [
            'description' => $movimento->observacoes,
            'reference' => $data['numero_recibo'] ?? null,
            'method' => $data['metodo_pagamento'] ?? $movimento->metodo_pagamento,
            'comprovativo' => $movimento->comprovativo,
        ]);

        $result = $this->financialSettlementService->settleFinancialEntry($financialEntry, [
            'numero_recibo' => $data['numero_recibo'] ?? null,
            'amount' => abs((float) $movimento->valor_total),
            'payment_amount' => abs((float) $movimento->valor_total),
            'payment_date' => optional($movimento->data_emissao)?->toDateString() ?? now()->toDateString(),
            'method' => $data['metodo_pagamento'] ?? $movimento->metodo_pagamento,
            'reference' => $data['numero_recibo'] ?? null,
            'description' => $movimento->observacoes,
            'user_id' => $movimento->user_id,
            'comprovativo' => $movimento->comprovativo,
            'bank_statement_id' => $data['bank_statement_id'] ?? null,
            'created_by' => $request->user()?->id,
            'source' => ! empty($data['bank_statement_id']) ? Payment::SOURCE_BANK_STATEMENT : Payment::SOURCE_MANUAL,
            'notes' => 'Liquidacao manual de movimento.',
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'movimento' => $movimento->fresh(),
            'lancamento' => $result['financial_entry'],
            'payment' => $result['payment'],
        ]);
    }

    public function reopenMovimento(Request $request, Movement $movimento)
    {
        $data = $request->validate([
            'estado_pagamento' => ['required', 'in:pendente,vencido'],
        ]);

        $result = $this->financialSettlementService->reopenMovement($movimento, $data['estado_pagamento'], [
            'created_by' => $request->user()?->id,
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'movimento' => $this->decorateMovementForResponse($result['movement']),
            'lancamento' => $result['financial_entry'] ?? null,
            'bank_statements' => $result['bank_statements'] ?? [],
        ]);
    }

    public function createExpenseFromBankStatement(Request $request, BankStatement $extrato): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'nome_manual' => ['nullable', 'string', 'max:255'],
            'categoria' => ['nullable', 'string', 'max:255'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
            'tipo' => ['nullable', 'string', 'max:30'],
            'document_type' => ['nullable', 'in:bank_statement_line,payment_proof,invoice,receipt,invoice_receipt,other'],
            'document_number' => ['nullable', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'vat_amount' => ['nullable', 'numeric'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file'],
        ]);

        $data = $this->normalizeManualMovementRequestData($request, array_merge($data, [
            'items' => [[
                'descricao' => $data['notes'] ?? $extrato->descricao,
                'quantidade' => 1,
                'valor_unitario' => abs((float) $extrato->valor),
                'imposto_percentual' => 0,
                'total_linha' => abs((float) $extrato->valor),
                'centro_custo_id' => $data['centro_custo_id'],
            ]],
            'valor_total' => abs((float) $extrato->valor),
            'metodo_pagamento' => $data['payment_method'] ?? 'transferencia',
            'notes' => $data['notes'] ?? $extrato->descricao,
        ]));

        $result = $this->manualExpenseService->createExpenseFromBankStatement($extrato, $data, $request->user());

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'movimento' => $this->decorateMovementForResponse($result['movement']),
            'lancamento' => $result['financial_entry'],
            'payment' => $result['payment'],
            'extrato' => $result['bank_statement'],
        ]);
    }

    public function storeExtrato(Request $request)
    {
        $data = $request->validate([
            'conta' => ['nullable', 'string', 'max:255'],
            'data_movimento' => ['required', 'date'],
            'descricao' => ['required', 'string'],
            'valor' => ['required', 'numeric'],
            'saldo' => ['nullable', 'numeric'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'ficheiro_id' => ['nullable', 'string', 'max:255'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
        ]);

        $extrato = DB::transaction(function () use ($data): BankStatement {
            $extrato = BankStatement::create([
                'conta' => $data['conta'] ?? null,
                'data_movimento' => $data['data_movimento'],
                'descricao' => $data['descricao'],
                'valor' => $data['valor'],
                'saldo' => null,
                'referencia' => $data['referencia'] ?? null,
                'ficheiro_id' => $data['ficheiro_id'] ?? null,
                'centro_custo_id' => $data['centro_custo_id'],
                'conciliado' => false,
            ]);

            $this->recalculateBankStatementBalances();

            return $extrato->fresh();
        });

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'extrato' => $extrato,
            'extratos' => $this->financeBankStatements(),
        ]);
    }

    public function storeExtratosBulk(Request $request)
    {
        $data = $request->validate([
            'extratos' => ['required', 'array', 'min:1'],
            'extratos.*.conta' => ['nullable', 'string', 'max:255'],
            'extratos.*.data_movimento' => ['required', 'date'],
            'extratos.*.descricao' => ['required', 'string'],
            'extratos.*.valor' => ['required', 'numeric'],
            'extratos.*.saldo' => ['nullable', 'numeric'],
            'extratos.*.referencia' => ['nullable', 'string', 'max:255'],
            'extratos.*.ficheiro_id' => ['nullable', 'string', 'max:255'],
            'extratos.*.centro_custo_id' => ['required', 'exists:cost_centers,id'],
        ]);

        $created = DB::transaction(function () use ($data): Collection {
            $created = [];

            foreach ($data['extratos'] as $row) {
                $created[] = BankStatement::create([
                    'conta' => $row['conta'] ?? null,
                    'data_movimento' => $row['data_movimento'],
                    'descricao' => $row['descricao'],
                    'valor' => $row['valor'],
                    'saldo' => null,
                    'referencia' => $row['referencia'] ?? null,
                    'ficheiro_id' => $row['ficheiro_id'] ?? null,
                    'centro_custo_id' => $row['centro_custo_id'],
                    'conciliado' => false,
                ]);
            }

            $this->recalculateBankStatementBalances();

            return collect($created)->map(fn (BankStatement $statement) => $statement->fresh());
        });

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'created_extratos' => $created,
            'extratos' => $this->financeBankStatements(),
        ]);
    }

    public function updateExtrato(Request $request, BankStatement $extrato)
    {
        $data = $request->validate([
            'data_movimento' => ['required', 'date'],
            'descricao' => ['required', 'string'],
            'valor' => ['required', 'numeric'],
            'saldo' => ['nullable', 'numeric'],
            'referencia' => ['nullable', 'string', 'max:255'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
        ]);

        $extrato = DB::transaction(function () use ($extrato, $data): BankStatement {
            $extrato->update([
                'data_movimento' => $data['data_movimento'],
                'descricao' => $data['descricao'],
                'valor' => $data['valor'],
                'saldo' => null,
                'referencia' => $data['referencia'] ?? null,
                'centro_custo_id' => $data['centro_custo_id'],
            ]);

            $this->recalculateBankStatementBalances();

            return $extrato->fresh();
        });

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'extrato' => $extrato,
            'extratos' => $this->financeBankStatements(),
        ]);
    }

    public function destroyExtrato(BankStatement $extrato)
    {
        if ($extrato->lancamento_id) {
            FinancialEntry::where('id', $extrato->lancamento_id)->delete();
        }

        DB::transaction(function () use ($extrato): void {
            $extrato->delete();
            $this->recalculateBankStatementBalances();
        });

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'success' => true,
            'extratos' => $this->financeBankStatements(),
        ]);
    }

    private function recalculateBankStatementBalances(): void
    {
        $runningBalance = 0.0;

        BankStatement::query()
            ->orderBy('data_movimento')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (BankStatement $statement) use (&$runningBalance): void {
                $runningBalance = round($runningBalance + (float) $statement->valor, 2);

                if ((float) ($statement->saldo ?? 0) === $runningBalance) {
                    return;
                }

                $statement->forceFill([
                    'saldo' => $runningBalance,
                ])->saveQuietly();
            });
    }

    private function financeBankStatements(): Collection
    {
        return BankStatement::query()
            ->orderBy('data_movimento', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(1000)
            ->get()
            ->map(function (BankStatement $extrato) {
                $extrato->valor = (float) $extrato->valor;
                $extrato->saldo = $extrato->saldo !== null ? (float) $extrato->saldo : null;

                return $extrato;
            });
    }

    public function conciliarExtrato(Request $request, BankStatement $extrato)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:receita,despesa'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'fatura_id' => ['nullable', 'exists:invoices,id'],
            'movimento_id' => ['nullable', 'exists:movements,id'],
            'financial_entry_id' => ['nullable', 'exists:financial_entries,id'],
            'itens' => ['nullable', 'array', 'min:1'],
            'itens.*.tipo' => ['required_with:itens', 'in:fatura,movimento,financial_entry'],
            'itens.*.id' => ['required_with:itens', 'string'],
            'itens.*.valor' => ['required_with:itens', 'numeric', 'min:0.01'],
        ]);

        $items = $data['itens'] ?? [];
        if ($items === []) {
            if (!empty($data['fatura_id'])) {
                $items[] = [
                    'tipo' => 'fatura',
                    'id' => $data['fatura_id'],
                    'valor' => abs((float) $extrato->valor),
                ];
            }
            if (!empty($data['movimento_id'])) {
                $items[] = [
                    'tipo' => 'movimento',
                    'id' => $data['movimento_id'],
                    'valor' => abs((float) $extrato->valor),
                ];
            }
            if (!empty($data['financial_entry_id'])) {
                $items[] = [
                    'tipo' => 'financial_entry',
                    'id' => $data['financial_entry_id'],
                    'valor' => abs((float) $extrato->valor),
                ];
            }
        }

        $result = $this->bankReconciliationService->reconcile($extrato, array_merge($data, [
            'itens' => $items,
            'metodo_pagamento' => 'transferencia',
        ]), [
            'created_by' => $request->user()?->id,
            'source' => Payment::SOURCE_RECONCILIATION,
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'extrato' => $result['bank_statement'],
            'lancamentos' => $result['entries'],
            'faturas' => $result['invoices'],
            'movimentos' => $result['movements'],
            'payments' => $result['payments'],
            'conciliacoes' => MapaConciliacao::query()->where('extrato_id', $extrato->id)->get(),
        ]);
    }

    public function desconciliarExtrato(BankStatement $extrato)
    {
        $result = $this->bankReconciliationService->unreconcile($extrato, [
            'created_by' => auth()->id(),
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'extrato' => $result['bank_statement'],
            'faturas' => $result['invoices'],
            'movimentos' => $result['movements'],
            'lancamentos_removidos' => $result['removed_entry_ids'],
        ]);
    }

    private function invalidateFinanceiroCaches(): void
    {
        Cache::forget('financeiro:index');
        Cache::forget('financeiro:fiscal_requests');
        Cache::forget('financeiro:faturas');
        Cache::forget('financeiro:fatura_itens');
        Cache::forget('financeiro:movimentos');
        Cache::forget('financeiro:movimento_itens');
        Cache::forget('financeiro:lancamentos');
        Cache::forget('financeiro:extratos');
        Cache::forget('financeiro:conciliacoes');
        Cache::forget('financeiro:centros_custo');
        Cache::forget('financeiro:users');
        Cache::forget('financeiro:suppliers');
        Cache::forget('financeiro:products');
        Cache::forget('financeiro:mensalidades');
        Cache::forget('financeiro:invoice_types');
        Cache::forget('financeiro:payment_methods');
        Cache::forget('financeiro:age_groups');
        Cache::forget('dashboard:stats');
    }
}

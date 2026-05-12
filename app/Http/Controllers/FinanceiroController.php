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
use App\Models\MovementItem;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\User;
use App\Services\Club\ClubSettingsService;
use App\Services\Financeiro\BankReconciliationService;
use App\Services\Financeiro\FinanceDashboardService;
use App\Services\Financeiro\FinancialSettlementService;
use App\Services\Financeiro\FiscalDocumentRequestService;
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
use Illuminate\Validation\ValidationException;

class FinanceiroController extends Controller
{
    public function __construct(
        private readonly PaymentAllocationService $paymentAllocationService,
        private readonly MonthlyFeeGenerationService $monthlyFeeGenerationService,
        private readonly FinancialSettlementService $financialSettlementService,
        private readonly BankReconciliationService $bankReconciliationService,
        private readonly FinanceDashboardService $financeDashboardService,
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
                    return $movimento;
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
                FinancialEntry::orderBy('data', 'desc')->limit(1000)->get()->map(function ($lancamento) {
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
            $extratos = Cache::remember('financeiro:extratos', 60, fn () =>
                BankStatement::orderBy('data_movimento', 'desc')->limit(1000)->get()->map(function ($extrato) {
                    $extrato->valor = (float) $extrato->valor;
                    $extrato->saldo = $extrato->saldo !== null ? (float) $extrato->saldo : null;
                    return $extrato;
                })
            );
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

        $invoice = Invoice::create([
            'user_id' => $data['user_id'],
            'data_fatura' => $data['data_fatura'] ?? $data['data_emissao'],
            'mes' => $data['mes'] ?? null,
            'data_emissao' => $data['data_emissao'],
            'data_vencimento' => $data['data_vencimento'],
            'valor_total' => $data['valor_total'],
            'oculta' => $data['oculta'] ?? false,
            'estado_pagamento' => $data['estado_pagamento'] ?? 'pendente',
            'numero_recibo' => $data['numero_recibo'] ?? null,
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
        if (
            in_array($requestedStatus, ['pago', 'parcial'], true)
            && !in_array($financeiro->estado_pagamento, ['pago', 'parcial'], true)
        ) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A liquidacao da fatura tem de ser efetuada pelo fluxo de pagamento.',
            ]);
        }

        $isManualPaymentReversal = in_array($financeiro->estado_pagamento, ['pago', 'parcial'], true)
            && !in_array($requestedStatus, ['pago', 'parcial'], true);

        if ($isManualPaymentReversal) {
            $financeiro = $this->paymentAllocationService->reverseInvoicePayments($financeiro, [
                'cancelled_by' => $request->user()?->id,
                'cancelled_at' => now(),
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
            'numero_recibo' => $data['numero_recibo'] ?? $financeiro->numero_recibo,
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

        $query = Invoice::query()
            ->with('user:id,nome_completo,name')
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

            $query->where(function ($nestedQuery) use ($search, $tokens) {
                $nestedQuery
                    ->where('tipo', 'ilike', "%{$search}%")
                    ->orWhere('mes', 'ilike', "%{$search}%")
                    ->orWhere('referencia_pagamento', 'ilike', "%{$search}%");

                foreach ($tokens as $token) {
                    $like = "%{$token}%";

                    $nestedQuery
                        ->orWhereHas('user', function ($userQuery) use ($like) {
                            $userQuery
                                ->where('nome_completo', 'ilike', $like)
                                ->orWhere('name', 'ilike', $like)
                                ->orWhere('numero_socio', 'ilike', $like)
                                ->orWhereHas('families', function ($familyQuery) use ($like) {
                                    $familyQuery->where('familias.nome', 'ilike', $like);
                                })
                                ->orWhereHas('families.responsavel', function ($responsavelQuery) use ($like) {
                                    $responsavelQuery
                                        ->where('nome_completo', 'ilike', $like)
                                        ->orWhere('name', 'ilike', $like);
                                })
                                ->orWhereHas('encarregados', function ($guardianQuery) use ($like) {
                                    $guardianQuery
                                        ->where('nome_completo', 'ilike', $like)
                                        ->orWhere('name', 'ilike', $like);
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

                return [
                    'id' => $invoice->id,
                    'user_id' => $invoice->user_id,
                    'user_name' => $invoice->user?->nome_completo ?? $invoice->user?->name,
                    'valor_total' => (float) $invoice->valor_total,
                    'valor_pago' => $paidAmount,
                    'valor_em_aberto' => $outstandingAmount,
                    'estado_pagamento' => $invoice->estado_pagamento,
                    'data_fatura' => optional($invoice->data_fatura)?->toDateString(),
                    'vencimento' => optional($invoice->data_vencimento)?->toDateString(),
                    'mes' => $invoice->mes,
                    'tipo' => $invoice->tipo,
                ];
            });

        $filteredCollection = $paginator->getCollection()
            ->filter(fn (array $invoice): bool => (float) ($invoice['valor_em_aberto'] ?? 0) > 0.009)
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
        $paidAmount = $entry->valor_pago !== null
            ? abs((float) $entry->valor_pago)
            : ($entry->estado === 'pago' ? $totalAmount : null);
        $openAmount = $entry->valor_em_aberto !== null
            ? abs((float) $entry->valor_em_aberto)
            : ($entry->estado === 'pago' ? 0.0 : $totalAmount);
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
            'estado_pagamento' => $this->resolveFinancialMovementState($entry->estado ?? 'pendente', $dueDate, $openAmount),
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
            'origem_id' => $movement->origem_id,
            'observacoes' => $movement->observacoes,
            'created_at' => optional($movement->created_at)?->toISOString(),
            'descricao_financeira' => $movement->observacoes,
            'sort_date' => ($emissionDate ?? now())->toDateString(),
        ];
    }

    private function resolveFinancialMovementTypeFromEntry(FinancialEntry $entry): string
    {
        return match ($entry->origem_tipo) {
            'stock' => 'material',
            'patrocinio' => 'patrocinio',
            default => 'outro',
        };
    }

    private function resolveFinancialMovementOriginType(?string $originType): ?string
    {
        return in_array($originType, ['evento', 'stock', 'patrocinio', 'manual'], true)
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

        if ($state === 'parcial') {
            return 'parcial';
        }

        if (
            $dueDate !== null
            && ($openAmount === null || $openAmount > 0.009)
            && $dueDate->lt(now()->startOfDay())
        ) {
            return 'vencido';
        }

        return 'pendente';
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
            $query->where(function ($nestedQuery) use ($search) {
                $nestedQuery
                    ->where('descricao', 'ilike', "%{$search}%")
                    ->orWhere('referencia', 'ilike', "%{$search}%")
                    ->orWhere('conta', 'ilike', "%{$search}%");
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
            'nome_manual' => ['nullable', 'string', 'max:255'],
            'nif_manual' => ['nullable', 'string', 'max:50'],
            'morada_manual' => ['nullable', 'string'],
            'classificacao' => ['required', 'in:receita,despesa'],
            'data_emissao' => ['required', 'date'],
            'data_vencimento' => ['required', 'date'],
            'valor_total' => ['required', 'numeric'],
            'estado_pagamento' => ['nullable', 'in:pendente,pago,vencido,parcial,cancelado'],
            'numero_recibo' => ['nullable', 'string', 'max:255'],
            'referencia_pagamento' => ['nullable', 'string', 'max:255'],
            'metodo_pagamento' => ['nullable', 'string', 'max:50'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
            'tipo' => ['required', 'string', 'max:30'],
            'origem_tipo' => ['nullable', 'in:evento,stock,patrocinio,manual'],
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

        if (in_array($data['estado_pagamento'] ?? 'pendente', ['pago', 'parcial'], true)) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A liquidacao do movimento tem de ser efetuada pelo fluxo de pagamento.',
            ]);
        }

        if (!$data['user_id'] && empty($data['nome_manual'])) {
            $data['nome_manual'] = app(ClubSettingsService::class)->defaultFinancialEntityName($data['classificacao'] ?? null);
        }

        if ($request->hasFile('documento_original')) {
            $data['documento_original'] = $request->file('documento_original')->store('financeiro/movimentos', 'public');
        }

        $movimento = Movement::create([
            'user_id' => $data['user_id'] ?? null,
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
            'nome_manual' => ['nullable', 'string', 'max:255'],
            'nif_manual' => ['nullable', 'string', 'max:50'],
            'morada_manual' => ['nullable', 'string'],
            'classificacao' => ['required', 'in:receita,despesa'],
            'data_emissao' => ['required', 'date'],
            'data_vencimento' => ['required', 'date'],
            'valor_total' => ['required', 'numeric'],
            'estado_pagamento' => ['nullable', 'in:pendente,pago,vencido,parcial,cancelado'],
            'numero_recibo' => ['nullable', 'string', 'max:255'],
            'referencia_pagamento' => ['nullable', 'string', 'max:255'],
            'metodo_pagamento' => ['nullable', 'string', 'max:50'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
            'tipo' => ['required', 'string', 'max:30'],
            'origem_tipo' => ['nullable', 'in:evento,stock,patrocinio,manual'],
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

        if (
            in_array($data['estado_pagamento'] ?? $movimento->estado_pagamento, ['pago', 'parcial'], true)
            && !in_array($movimento->estado_pagamento, ['pago', 'parcial'], true)
        ) {
            throw ValidationException::withMessages([
                'estado_pagamento' => 'A liquidacao do movimento tem de ser efetuada pelo fluxo de pagamento.',
            ]);
        }

        if (!$data['user_id'] && empty($data['nome_manual'])) {
            $data['nome_manual'] = app(ClubSettingsService::class)->defaultFinancialEntityName($data['classificacao'] ?? null);
        }

        if ($request->hasFile('documento_original')) {
            if ($movimento->documento_original) {
                Storage::disk('public')->delete($movimento->documento_original);
            }
            $data['documento_original'] = $request->file('documento_original')->store('financeiro/movimentos', 'public');
        }

        $movimento->update([
            'user_id' => $data['user_id'] ?? null,
            'nome_manual' => $data['nome_manual'] ?? null,
            'nif_manual' => $data['nif_manual'] ?? null,
            'morada_manual' => $data['morada_manual'] ?? null,
            'classificacao' => $data['classificacao'],
            'data_emissao' => $data['data_emissao'],
            'data_vencimento' => $data['data_vencimento'],
            'valor_total' => $data['valor_total'],
            'estado_pagamento' => $data['estado_pagamento'] ?? $movimento->estado_pagamento,
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
            'numero_recibo' => ['required', 'string', 'max:255'],
            'metodo_pagamento' => ['nullable', 'string', 'max:50'],
        ]);

        if ($request->hasFile('comprovativo')) {
            if ($movimento->comprovativo) {
                Storage::disk('public')->delete($movimento->comprovativo);
            }
            $movimento->comprovativo = $request->file('comprovativo')->store('financeiro/movimentos', 'public');
        }

        $financialEntry = $this->financialSettlementService->findOrCreateFinancialEntryForMovement($movimento, [
            'description' => $movimento->observacoes,
            'reference' => $data['numero_recibo'],
            'method' => $data['metodo_pagamento'] ?? $movimento->metodo_pagamento,
            'comprovativo' => $movimento->comprovativo,
        ]);

        $result = $this->financialSettlementService->settleFinancialEntry($financialEntry, [
            'numero_recibo' => $data['numero_recibo'],
            'amount' => abs((float) $movimento->valor_total),
            'payment_amount' => abs((float) $movimento->valor_total),
            'payment_date' => optional($movimento->data_emissao)?->toDateString() ?? now()->toDateString(),
            'method' => $data['metodo_pagamento'] ?? $movimento->metodo_pagamento,
            'reference' => $data['numero_recibo'],
            'description' => $movimento->observacoes,
            'user_id' => $movimento->user_id,
            'comprovativo' => $movimento->comprovativo,
            'created_by' => $request->user()?->id,
            'source' => Payment::SOURCE_MANUAL,
            'notes' => 'Liquidacao manual de movimento.',
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'movimento' => $movimento->fresh(),
            'lancamento' => $result['financial_entry'],
            'payment' => $result['payment'],
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
        Cache::forget('financeiro:faturas');
        Cache::forget('financeiro:fatura_itens');
        Cache::forget('financeiro:movimentos');
        Cache::forget('financeiro:movimento_itens');
        Cache::forget('financeiro:lancamentos');
        Cache::forget('financeiro:extratos');
        Cache::forget('financeiro:conciliacoes');
        Cache::forget('financeiro:centros_custo');
        Cache::forget('financeiro:users');
        Cache::forget('financeiro:products');
        Cache::forget('financeiro:mensalidades');
        Cache::forget('financeiro:invoice_types');
        Cache::forget('financeiro:age_groups');
        Cache::forget('dashboard:stats');
    }
}

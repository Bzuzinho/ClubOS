<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\UpdateInvoiceRequest;
use App\Models\AgeGroup;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\MonthlyFee;
use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\MapaConciliacao;
use App\Models\Product;
use App\Models\User;
use App\Services\Club\ClubSettingsService;
use App\Services\Financeiro\FiscalDocumentRequestService;
use App\Services\Financeiro\ReconciliationAliasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class FinanceiroController extends Controller
{
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
        try {
            $faturas = Cache::remember('financeiro:faturas', 60, fn () =>
                Invoice::orderBy('data_emissao', 'desc')->limit(1000)->get()->map(function ($fatura) {
                    $fatura->valor_total = (float) $fatura->valor_total;
                    return $fatura;
                })
            );
        } catch (\Exception $e) {
            \Log::error('FinanceiroController::index - Faturas query failed: ' . $e->getMessage());
            $faturas = [];
        }

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

        return [
            'faturas' => $faturas,
            'faturaItens' => $faturaItens,
            'movimentos' => $movimentos,
            'movimentoItens' => $movimentoItens,
            'lancamentos' => $lancamentos,
            'extratos' => $extratos,
            'conciliacoes' => $conciliacoes,
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

        if ($request->expectsJson()) {
            return response()->json([
                'invoice' => $invoice->load('items'),
            ]);
        }

        $this->invalidateFinanceiroCaches();

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

        $financeiro->update([
            'user_id' => $data['user_id'],
            'data_fatura' => $data['data_fatura'] ?? $data['data_emissao'],
            'mes' => $data['mes'] ?? null,
            'data_emissao' => $data['data_emissao'],
            'data_vencimento' => $data['data_vencimento'],
            'valor_total' => $data['valor_total'],
            'oculta' => $data['oculta'] ?? $financeiro->oculta,
            'estado_pagamento' => $data['estado_pagamento'] ?? $financeiro->estado_pagamento,
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

        if ($request->expectsJson()) {
            return response()->json([
                'invoice' => $financeiro->load('items'),
            ]);
        }

        $this->invalidateFinanceiroCaches();

        return redirect()->route('financeiro.index')
            ->with('success', 'Fatura atualizada com sucesso!');
    }

    public function destroy(Invoice $financeiro): RedirectResponse|JsonResponse
    {
        $financeiro->delete();

        if (request()->expectsJson()) {
            return response()->json(['success' => true]);
        }

        $this->invalidateFinanceiroCaches();

        return redirect()->route('financeiro.index')
            ->with('success', 'Fatura eliminada com sucesso!');
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

        $movimento->estado_pagamento = 'pago';
        $movimento->numero_recibo = $data['numero_recibo'];
        $movimento->metodo_pagamento = $data['metodo_pagamento'] ?? $movimento->metodo_pagamento;
        $movimento->save();

        $lancamento = FinancialEntry::where('origem_id', $movimento->id)
            ->orderByDesc('created_at')
            ->first();

        if ($lancamento) {
            $lancamento->update([
                'documento_ref' => $movimento->numero_recibo,
                'metodo_pagamento' => $movimento->metodo_pagamento,
                'comprovativo' => $movimento->comprovativo,
            ]);
        } else {
            $lancamento = FinancialEntry::create([
                'data' => $movimento->data_emissao,
                'tipo' => $movimento->classificacao,
                'categoria' => 'Pagamento de Movimento',
                'descricao' => "Pagamento de movimento {$movimento->tipo}",
                'documento_ref' => $movimento->numero_recibo,
                'valor' => abs($movimento->valor_total),
                'centro_custo_id' => $movimento->centro_custo_id,
                'user_id' => $movimento->user_id,
                'origem_tipo' => $movimento->origem_tipo ?? 'manual',
                'origem_id' => $movimento->id,
                'metodo_pagamento' => $movimento->metodo_pagamento,
                'comprovativo' => $movimento->comprovativo,
            ]);
        }

        $this->invalidateFinanceiroCaches();

        return response()->json(['movimento' => $movimento, 'lancamento' => $lancamento]);
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

        $extrato = BankStatement::create([
            'conta' => $data['conta'] ?? null,
            'data_movimento' => $data['data_movimento'],
            'descricao' => $data['descricao'],
            'valor' => $data['valor'],
            'saldo' => $data['saldo'] ?? null,
            'referencia' => $data['referencia'] ?? null,
            'ficheiro_id' => $data['ficheiro_id'] ?? null,
            'centro_custo_id' => $data['centro_custo_id'],
            'conciliado' => false,
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json(['extrato' => $extrato]);
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

        $created = [];
        foreach ($data['extratos'] as $row) {
            $created[] = BankStatement::create([
                'conta' => $row['conta'] ?? null,
                'data_movimento' => $row['data_movimento'],
                'descricao' => $row['descricao'],
                'valor' => $row['valor'],
                'saldo' => $row['saldo'] ?? null,
                'referencia' => $row['referencia'] ?? null,
                'ficheiro_id' => $row['ficheiro_id'] ?? null,
                'centro_custo_id' => $row['centro_custo_id'],
                'conciliado' => false,
            ]);
        }

        $this->invalidateFinanceiroCaches();

        return response()->json(['extratos' => $created]);
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

        $extrato->update([
            'data_movimento' => $data['data_movimento'],
            'descricao' => $data['descricao'],
            'valor' => $data['valor'],
            'saldo' => $data['saldo'] ?? null,
            'referencia' => $data['referencia'] ?? null,
            'centro_custo_id' => $data['centro_custo_id'],
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json(['extrato' => $extrato]);
    }

    public function destroyExtrato(BankStatement $extrato)
    {
        if ($extrato->lancamento_id) {
            FinancialEntry::where('id', $extrato->lancamento_id)->delete();
        }

        $extrato->delete();

        $this->invalidateFinanceiroCaches();

        return response()->json(['success' => true]);
    }

    public function conciliarExtrato(Request $request, BankStatement $extrato)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:receita,despesa'],
            'centro_custo_id' => ['required', 'exists:cost_centers,id'],
            'user_id' => ['nullable', 'exists:users,id'],
            'fatura_id' => ['nullable', 'exists:invoices,id'],
            'movimento_id' => ['nullable', 'exists:movements,id'],
            'itens' => ['nullable', 'array', 'min:1'],
            'itens.*.tipo' => ['required_with:itens', 'in:fatura,movimento'],
            'itens.*.id' => ['required_with:itens', 'string'],
            'itens.*.valor' => ['required_with:itens', 'numeric', 'min:0.01'],
        ]);

        $items = $data['itens'] ?? null;
        if (!$items) {
            $items = [];
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
        }

        if (!$items || count($items) === 0) {
            return response()->json(['message' => 'Nenhum item para conciliar.'], 422);
        }

        $lancamentos = [];
        $mapas = [];
        $mapasPorFatura = [];
        $faturasAtualizadas = [];
        $movimentosAtualizados = [];
        $faturasAfetadas = [];
        $movimentosAfetados = [];
        $totalConciliado = 0;

        foreach ($items as $item) {
            $valorItem = abs((float) $item['valor']);
            if ($valorItem <= 0) {
                continue;
            }

            $fatura = null;
            $movimento = null;
            $estadoFaturaAnterior = null;
            $estadoMovimentoAnterior = null;
            $centroCustoId = $data['centro_custo_id'] ?? null;
            $userId = $data['user_id'] ?? null;

            if ($item['tipo'] === 'fatura') {
                $fatura = Invoice::find($item['id']);
                if ($fatura) {
                    $estadoFaturaAnterior = $fatura->estado_pagamento;
                    $centroCustoId = $fatura->centro_custo_id ?: $centroCustoId;
                    $userId = $fatura->user_id ?: $userId;
                    $faturasAfetadas[$fatura->id] = $estadoFaturaAnterior;
                }
            } elseif ($item['tipo'] === 'movimento') {
                $movimento = Movement::find($item['id']);
                if ($movimento) {
                    $estadoMovimentoAnterior = $movimento->estado_pagamento;
                    $centroCustoId = $movimento->centro_custo_id ?: $centroCustoId;
                    $userId = $movimento->user_id ?: $userId;
                    $movimentosAfetados[$movimento->id] = $estadoMovimentoAnterior;
                }
            }

            if (!$centroCustoId) {
                continue;
            }

            $entry = FinancialEntry::create([
                'data' => $extrato->data_movimento,
                'tipo' => $data['tipo'],
                'descricao' => $extrato->descricao,
                'documento_ref' => $extrato->referencia,
                'valor' => $valorItem,
                'centro_custo_id' => $centroCustoId,
                'user_id' => $userId,
                'fatura_id' => $fatura?->id,
                'origem_tipo' => 'manual',
                'origem_id' => $movimento?->id,
                'metodo_pagamento' => 'transferencia',
            ]);

            $mapa = MapaConciliacao::create([
                'extrato_id' => $extrato->id,
                'lancamento_id' => $entry->id,
                'fatura_id' => $fatura?->id,
                'movimento_id' => $movimento?->id,
                'estado_fatura_anterior' => $estadoFaturaAnterior,
                'estado_movimento_anterior' => $estadoMovimentoAnterior,
                'valor_conciliado' => $valorItem,
                'status' => 'confirmado',
                'regra_usada' => 'manual',
            ]);

            $mapas[] = $mapa;

            if ($fatura?->id) {
                $mapasPorFatura[$fatura->id] = $mapa;
            }

            $lancamentos[] = $entry;
            $totalConciliado += $valorItem;
        }

        $valorExtrato = abs((float) $extrato->valor);
        $extrato->update([
            'conciliado' => $totalConciliado >= $valorExtrato && $valorExtrato > 0,
            'lancamento_id' => count($lancamentos) === 1 ? $lancamentos[0]->id : null,
        ]);

        foreach ($faturasAfetadas as $faturaId => $estadoAnterior) {
            $fatura = Invoice::find($faturaId);
            if (!$fatura) {
                continue;
            }
            $totalPago = (float) FinancialEntry::where('fatura_id', $faturaId)->sum('valor');
            if ($totalPago >= (float) $fatura->valor_total) {
                $fatura->estado_pagamento = 'pago';
            } elseif ($totalPago > 0) {
                $fatura->estado_pagamento = 'parcial';
            } elseif ($estadoAnterior) {
                $fatura->estado_pagamento = $estadoAnterior;
            } else {
                $fatura->estado_pagamento = 'pendente';
            }
            $fatura->save();
            $faturasAtualizadas[] = $fatura;

            if ($fatura->estado_pagamento === 'pago' && isset($mapasPorFatura[$fatura->id])) {
                try {
                    app(FiscalDocumentRequestService::class)->createFromReconciliation($mapasPorFatura[$fatura->id], [
                        'paid_at' => $extrato->data_movimento,
                        'bank_statement_id' => $extrato->id,
                        'financial_entry_id' => $mapasPorFatura[$fatura->id]->lancamento_id,
                        'created_by' => $request->user()?->id,
                    ]);
                } catch (\Throwable $exception) {
                    \Log::warning('FinanceiroController::conciliarExtrato - fiscal request creation failed', [
                        'invoice_id' => $fatura->id,
                        'bank_statement_id' => $extrato->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        foreach ($movimentosAfetados as $movimentoId => $estadoAnterior) {
            $movimento = Movement::find($movimentoId);
            if (!$movimento) {
                continue;
            }
            $totalPago = (float) FinancialEntry::where('origem_id', $movimentoId)->sum('valor');
            $valorMovimento = abs((float) $movimento->valor_total);
            if ($totalPago >= $valorMovimento) {
                $movimento->estado_pagamento = 'pago';
            } elseif ($totalPago > 0) {
                $movimento->estado_pagamento = 'parcial';
            } elseif ($estadoAnterior) {
                $movimento->estado_pagamento = $estadoAnterior;
            } else {
                $movimento->estado_pagamento = 'pendente';
            }
            $movimento->save();
            $movimentosAtualizados[] = $movimento;
        }

        $resolvedUserId = collect($lancamentos)
            ->pluck('user_id')
            ->filter()
            ->first() ?? ($data['user_id'] ?? null);

        if ($resolvedUserId) {
            app(ReconciliationAliasService::class)->suggestFromConfirmedReconciliation(
                $extrato,
                $resolvedUserId,
                null,
                $request->user()?->id,
            );
        }

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'extrato' => $extrato,
            'lancamentos' => $lancamentos,
            'faturas' => $faturasAtualizadas,
            'movimentos' => $movimentosAtualizados,
            'conciliacoes' => $mapas,
        ]);
    }

    public function desconciliarExtrato(BankStatement $extrato)
    {
        $mapas = MapaConciliacao::where('extrato_id', $extrato->id)->get();
        $faturasAfetadas = [];
        $movimentosAfetados = [];
        $lancamentosRemovidos = [];

        foreach ($mapas as $mapa) {
            if ($mapa->lancamento_id) {
                $lancamentosRemovidos[] = $mapa->lancamento_id;
                FinancialEntry::where('id', $mapa->lancamento_id)->delete();
            }
            if ($mapa->fatura_id) {
                $faturasAfetadas[$mapa->fatura_id] = $mapa->estado_fatura_anterior;
            }
            if ($mapa->movimento_id) {
                $movimentosAfetados[$mapa->movimento_id] = $mapa->estado_movimento_anterior;
            }
        }

        MapaConciliacao::where('extrato_id', $extrato->id)->delete();

        $faturasAtualizadas = [];
        foreach ($faturasAfetadas as $faturaId => $estadoAnterior) {
            $fatura = Invoice::find($faturaId);
            if (!$fatura) {
                continue;
            }
            $totalPago = (float) FinancialEntry::where('fatura_id', $faturaId)->sum('valor');
            if ($totalPago >= (float) $fatura->valor_total) {
                $fatura->estado_pagamento = 'pago';
            } elseif ($totalPago > 0) {
                $fatura->estado_pagamento = 'parcial';
            } else {
                $fatura->estado_pagamento = $estadoAnterior ?? 'pendente';
            }
            $fatura->save();
            $faturasAtualizadas[] = $fatura;
        }

        $movimentosAtualizados = [];
        foreach ($movimentosAfetados as $movimentoId => $estadoAnterior) {
            $movimento = Movement::find($movimentoId);
            if (!$movimento) {
                continue;
            }
            $totalPago = (float) FinancialEntry::where('origem_id', $movimentoId)->sum('valor');
            $valorMovimento = abs((float) $movimento->valor_total);
            if ($totalPago >= $valorMovimento) {
                $movimento->estado_pagamento = 'pago';
            } elseif ($totalPago > 0) {
                $movimento->estado_pagamento = 'parcial';
            } else {
                $movimento->estado_pagamento = $estadoAnterior ?? 'pendente';
            }
            $movimento->save();
            $movimentosAtualizados[] = $movimento;
        }

        $extrato->update([
            'conciliado' => false,
            'lancamento_id' => null,
        ]);

        $this->invalidateFinanceiroCaches();

        return response()->json([
            'extrato' => $extrato,
            'faturas' => $faturasAtualizadas,
            'movimentos' => $movimentosAtualizados,
            'lancamentos_removidos' => $lancamentosRemovidos,
        ]);
    }

    private function invalidateFinanceiroCaches(): void
    {
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

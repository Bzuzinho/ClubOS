<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateEquipmentLoanRequest;
use App\Http\Requests\InvoiceLogisticsRequestRequest;
use App\Http\Requests\RegisterStockMovementRequest;
use App\Http\Requests\RegisterSupplierPurchaseRequest;
use App\Http\Requests\StoreLogisticsRequestRequest;
use App\Http\Requests\UpdateEquipmentLoanRequest;
use App\Http\Requests\UpdateLogisticsRequestRequest;
use App\Http\Requests\UpdateSupplierPurchaseRequest;
use App\Models\EquipmentLoan;
use App\Models\LogisticsRequest;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Models\UserType;
use App\Services\Members\MemberIdentityDisplayResolver;
use App\Services\Logistica\ApproveLogisticsRequestAction;
use App\Services\Logistica\CreateEquipmentLoanAction;
use App\Services\Logistica\CreateLogisticsRequestAction;
use App\Services\Logistica\DeleteEquipmentLoanAction;
use App\Services\Logistica\DeleteLogisticsRequestAction;
use App\Services\Logistica\DeleteSupplierPurchaseAction;
use App\Services\Logistica\DeliverLogisticsRequestAction;
use App\Services\Logistica\InvoiceLogisticsRequestAction;
use App\Services\Logistica\RegisterStockMovementAction;
use App\Services\Logistica\RegisterSupplierPurchaseAction;
use App\Services\Logistica\ReturnEquipmentLoanAction;
use App\Services\Logistica\UpdateEquipmentLoanAction;
use App\Services\Logistica\UpdateLogisticsRequestAction;
use App\Services\Logistica\UpdateSupplierPurchaseAction;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class LogisticaController extends Controller
{
    public function index(): Response
    {
        $tab = request()->query('tab', 'dashboard');
        $identityResolver = app(MemberIdentityDisplayResolver::class);

        $products = Product::query()
            ->with('supplier:id,nome')
            ->orderBy('nome')
            ->get()
            ->map(function (Product $product) {
                $stock = (int) $product->stock;
                $reserved = (int) ($product->stock_reservado ?? 0);
                $available = $stock - $reserved;

                return [
                    'id' => $product->id,
                    'codigo' => $product->codigo,
                    'nome' => $product->nome,
                    'categoria' => $product->categoria,
                    'preco' => (float) $product->preco,
                    'stock' => $stock,
                    'stock_reservado' => $reserved,
                    'stock_disponivel' => $available,
                    'stock_minimo' => (int) $product->stock_minimo,
                    'status' => $available <= (int) $product->stock_minimo ? 'baixo' : 'ok',
                    'ativo' => (bool) $product->ativo,
                    'supplier' => $product->supplier ? [
                        'id' => $product->supplier->id,
                        'nome' => $product->supplier->nome,
                    ] : null,
                ];
            })
            ->values();

        $requests = LogisticsRequest::query()
            ->with(['items', 'requester:id,nome_completo', 'financialInvoice:id,valor_total,estado_pagamento'])
            ->latest()
            ->limit(80)
            ->get();

        $loans = EquipmentLoan::query()
            ->with(['borrower:id,nome_completo'])
            ->latest('loan_date')
            ->limit(80)
            ->get()
            ->map(function (EquipmentLoan $loan) {
                if ($loan->status === 'active' && $loan->due_date && now()->isAfter($loan->due_date)) {
                    $loan->status = 'overdue';
                }

                return $loan;
            });

        $supplierPurchases = SupplierPurchase::query()
            ->with(['supplier:id,nome', 'financialMovement:id,valor_total,estado_pagamento', 'items'])
            ->latest('invoice_date')
            ->limit(200)
            ->get();

        $lowStockCount = $products->filter(fn ($p) => $p['status'] === 'baixo')->count();
        $stockValuation = $products->sum(fn ($p) => ((float) $p['preco']) * ((int) $p['stock']));
        $pendingRequests = $requests->whereIn('status', ['pending', 'approved', 'invoiced'])->count();
        $activeLoans = $loans->whereIn('status', ['active', 'overdue'])->count();

        $latestFinancialActions = collect()
            ->merge($requests->filter(fn ($r) => !empty($r->financial_invoice_id))->take(5)->map(function ($r) {
                return [
                    'type' => 'request_invoice',
                    'label' => 'Fatura de requisição gerada',
                    'reference' => $r->financial_invoice_id,
                    'date' => $r->updated_at,
                ];
            }))
            ->merge($supplierPurchases->filter(fn ($p) => !empty($p->financial_movement_id))->take(5)->map(function ($p) {
                return [
                    'type' => 'supplier_expense',
                    'label' => 'Despesa de fornecedor registada',
                    'reference' => $p->financial_movement_id,
                    'date' => $p->updated_at,
                ];
            }))
            ->sortByDesc('date')
            ->take(8)
            ->values();

        return Inertia::render('Logistica/Index', [
            'tab' => $tab,
            'products' => $products,
            'suppliers' => Supplier::query()->orderBy('nome')->get(),
            'users' => User::query()
                ->with('dadosPessoais:id,user_id,nome_completo')
                ->select('id', 'name')
                ->where('estado', 'ativo')
                ->orderBy('name')
                ->get()
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'nome_completo' => $identityResolver->displayName($user),
                ])
                ->sortBy(fn (array $user): string => mb_strtolower((string) ($user['nome_completo'] ?? '')))
                ->values(),
            'userTypes' => UserType::query()->where('ativo', true)->orderBy('nome')->get(['id', 'nome']),
            'requests' => $requests,
            'stockMovements' => StockMovement::query()->with('article:id,nome')->latest()->limit(200)->get(),
            'loans' => $loans,
            'supplierPurchases' => $supplierPurchases,
            'dashboard' => [
                'stock_valuation' => (float) $stockValuation,
                'low_stock_alerts' => $lowStockCount,
                'pending_requests' => $pendingRequests,
                'active_loans' => $activeLoans,
                'latest_supplier_purchases' => $supplierPurchases->take(5)->values(),
                'latest_financial_actions' => $latestFinancialActions,
            ],
        ]);
    }

    public function storeRequest(
        StoreLogisticsRequestRequest $request,
        CreateLogisticsRequestAction $action
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return redirect()->route('logistica.index')->with('success', 'Requisição logística criada com sucesso.');
    }

    public function updateRequest(
        UpdateLogisticsRequestRequest $request,
        LogisticsRequest $logisticsRequest,
        UpdateLogisticsRequestAction $action
    ): RedirectResponse {
        $action->execute($logisticsRequest, $request->validated(), $request->user());

        return redirect()->route('logistica.index')->with('success', 'Requisição atualizada com sucesso.');
    }

    public function destroyRequest(
        LogisticsRequest $logisticsRequest,
        DeleteLogisticsRequestAction $action
    ): RedirectResponse {
        $action->execute($logisticsRequest, request()->user());

        return redirect()->route('logistica.index')->with('success', 'Requisição apagada com sucesso.');
    }

    public function approveRequest(
        LogisticsRequest $logisticsRequest,
        ApproveLogisticsRequestAction $action
    ): RedirectResponse {
        $action->execute($logisticsRequest, request()->user());

        return redirect()->route('logistica.index')->with('success', 'Requisição aprovada e stock reservado.');
    }

    public function invoiceRequest(
        InvoiceLogisticsRequestRequest $request,
        LogisticsRequest $logisticsRequest,
        InvoiceLogisticsRequestAction $action
    ): RedirectResponse {
        $action->execute($logisticsRequest, $request->validated(), $request->user());

        return redirect()->route('logistica.index')->with('success', 'Fatura financeira gerada para a requisição.');
    }

    public function deliverRequest(
        LogisticsRequest $logisticsRequest,
        DeliverLogisticsRequestAction $action
    ): RedirectResponse {
        $action->execute($logisticsRequest, request()->user());

        return redirect()->route('logistica.index')->with('success', 'Requisição entregue e stock atualizado.');
    }

    public function registerStockMovement(
        RegisterStockMovementRequest $request,
        RegisterStockMovementAction $action
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return redirect()->route('logistica.index', ['tab' => 'stock'])->with('success', 'Movimento de stock registado.');
    }

    public function storeLoan(
        CreateEquipmentLoanRequest $request,
        CreateEquipmentLoanAction $action
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return redirect()->route('logistica.index')->with('success', 'Empréstimo criado com sucesso.');
    }

    public function updateLoan(
        UpdateEquipmentLoanRequest $request,
        EquipmentLoan $equipmentLoan,
        UpdateEquipmentLoanAction $action
    ): RedirectResponse {
        $action->execute($equipmentLoan, $request->validated());

        return redirect()->route('logistica.index')->with('success', 'Empréstimo atualizado e stock recalculado.');
    }

    public function destroyLoan(
        EquipmentLoan $equipmentLoan,
        DeleteEquipmentLoanAction $action
    ): RedirectResponse {
        $action->execute($equipmentLoan);

        return redirect()->route('logistica.index')->with('success', 'Empréstimo apagado e stock reposto.');
    }

    public function returnLoan(
        EquipmentLoan $equipmentLoan,
        ReturnEquipmentLoanAction $action
    ): RedirectResponse {
        $action->execute($equipmentLoan, request()->user());

        return redirect()->route('logistica.index')->with('success', 'Empréstimo devolvido e stock reposto.');
    }

    public function registerSupplierPurchase(
        RegisterSupplierPurchaseRequest $request,
        RegisterSupplierPurchaseAction $action
    ): RedirectResponse {
        $action->execute($request->validated(), $request->user());

        return redirect()->route('logistica.index')->with('success', 'Compra de fornecedor registada e integrada no financeiro.');
    }

    public function updateSupplierPurchase(
        UpdateSupplierPurchaseRequest $request,
        SupplierPurchase $supplierPurchase,
        UpdateSupplierPurchaseAction $action
    ): RedirectResponse {
        $action->execute($supplierPurchase, $request->validated(), $request->user());

        return redirect()->route('logistica.index')->with('success', 'Compra de fornecedor atualizada e stock/financeiro recalculados.');
    }

    public function destroySupplierPurchase(
        SupplierPurchase $supplierPurchase,
        DeleteSupplierPurchaseAction $action
    ): RedirectResponse {
        $action->execute($supplierPurchase);

        return redirect()->route('logistica.index')->with('success', 'Compra de fornecedor removida com sucesso.');
    }
}

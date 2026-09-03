<?php

namespace App\Services\Loja;

use App\Models\LojaCarrinho;
use App\Models\LojaCarrinhoItem;
use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Catalog\CanonicalProductStockService;
use App\Services\Communication\InAppAlertService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LojaEncomendaService
{
    public function __construct(
        private readonly LojaCarrinhoService $carrinhoService,
        private readonly CanonicalProductStockService $stockService,
        private readonly LojaFinanceiroService $financeiroService,
        private readonly CancelStoreOrderStockAction $cancelStockAction,
        private readonly StoreProfileResolver $profileResolver,
        private readonly InAppAlertService $inAppAlertService,
    ) {
    }

    public function submit(User $user, array $payload): LojaEncomenda
    {
        return DB::transaction(function () use ($user, $payload) {
            $targetUserId = $this->profileResolver->normalizeTargetUserId($user, $payload['target_user_id'] ?? null);

            $cart = LojaCarrinho::query()
                ->with(['itens.article', 'itens.productVariant'])
                ->open()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->latest('updated_at')
                ->first();

            if (! $cart || $cart->itens->isEmpty()) {
                throw ValidationException::withMessages([
                    'cart' => 'O carrinho está vazio.',
                ]);
            }

            $order = LojaEncomenda::create([
                'numero' => $this->generateNumero(),
                'user_id' => $user->id,
                'target_user_id' => $targetUserId,
                'estado' => LojaEncomenda::ESTADO_PENDENTE,
                'subtotal' => 0,
                'total' => 0,
                'observacoes' => $payload['observacoes'] ?? $cart->observacoes,
                'origem' => 'portal',
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $subtotal = 0;

            foreach ($cart->itens as $cartItem) {
                $produto = Product::query()->lockForUpdate()->findOrFail($this->requireArticleId($cartItem));
                $variante = $cartItem->product_variant_id
                    ? ProductVariant::query()->lockForUpdate()->findOrFail($cartItem->product_variant_id)
                    : null;

                $this->stockService->ensureAvailableForStore($produto, $variante, (int) $cartItem->quantidade);

                $unitPrice = $this->stockService->saleUnitPrice($produto, $variante);
                $lineTotal = $unitPrice * (int) $cartItem->quantidade;

                $orderItem = LojaEncomendaItem::create([
                    'loja_encomenda_id' => $order->id,
                    'article_id' => $produto->id,
                    'product_variant_id' => $variante?->id,
                    'descricao' => $this->buildDescricao($produto, $variante),
                    'quantidade' => (int) $cartItem->quantidade,
                    'preco_unitario' => $unitPrice,
                    'total_linha' => $lineTotal,
                ]);

                $this->stockService->decrementOnSale($produto, $variante, (int) $cartItem->quantidade, [
                    'source_type' => 'store_order_item',
                    'source_id' => $orderItem->id,
                    'idempotency_key' => 'store-order-item-'.$orderItem->id,
                    'notes' => 'Saída de stock por encomenda da loja',
                    'created_by' => $user->id,
                    'occurred_at' => now(),
                ]);
                $subtotal += $lineTotal;
            }

            $order->update([
                'subtotal' => $subtotal,
                'total' => $subtotal,
            ]);

            $faturaId = $this->financeiroService->prepareForOrder($order->fresh());
            if ($faturaId) {
                $order->update(['fatura_id' => $faturaId]);
            }

            $freshOrder = $order->fresh(['itens.article.category', 'itens.productVariant', 'user', 'targetUser']);
            $this->dispatchOperationalAlert($freshOrder);

            $cart->update([
                'estado' => LojaCarrinho::ESTADO_CONVERTIDO,
            ]);

            return $freshOrder;
        });
    }

    public function updateEstado(LojaEncomenda $encomenda, string $estado, User $actor): LojaEncomenda
    {
        return DB::transaction(function () use ($encomenda, $estado, $actor) {
            $estado = trim($estado);
            $allowedStates = [
                LojaEncomenda::ESTADO_PENDENTE,
                LojaEncomenda::ESTADO_APROVADO,
                LojaEncomenda::ESTADO_PREPARADO,
                LojaEncomenda::ESTADO_ENTREGUE,
                LojaEncomenda::ESTADO_CANCELADO,
            ];

            if (! in_array($estado, $allowedStates, true)) {
                throw ValidationException::withMessages([
                    'estado' => 'Estado de encomenda inválido.',
                ]);
            }

            $encomenda = LojaEncomenda::query()->whereKey($encomenda->id)->lockForUpdate()->firstOrFail();

            if ($encomenda->estado === LojaEncomenda::ESTADO_CANCELADO) {
                if ($estado !== LojaEncomenda::ESTADO_CANCELADO) {
                    throw ValidationException::withMessages([
                        'estado' => 'Uma encomenda cancelada não pode ser reativada.',
                    ]);
                }

                return $encomenda->fresh(['itens.article.category', 'itens.productVariant', 'user', 'targetUser']);
            }

            if ($encomenda->estado === LojaEncomenda::ESTADO_DEVOLVIDO) {
                throw ValidationException::withMessages([
                    'estado' => 'Uma encomenda devolvida é terminal e preserva o respetivo histórico de reversão.',
                ]);
            }

            if ($encomenda->estado === LojaEncomenda::ESTADO_ENTREGUE) {
                if ($estado !== LojaEncomenda::ESTADO_ENTREGUE) {
                    throw ValidationException::withMessages([
                        'estado' => 'Uma encomenda entregue exige um fluxo explícito de devolução e não pode mudar diretamente de estado.',
                    ]);
                }

                return $encomenda->fresh(['itens.article.category', 'itens.productVariant', 'user', 'targetUser']);
            }

            if ($estado === LojaEncomenda::ESTADO_CANCELADO) {
                $this->financeiroService->cancelPristineInvoiceForOrder($encomenda);
                $this->cancelStockAction->execute($encomenda, $actor);
            }

            $encomenda->update([
                'estado' => $estado,
                'updated_by' => $actor->id,
            ]);

            if ($estado === LojaEncomenda::ESTADO_ENTREGUE) {
                $this->financeiroService->syncDeliveredRevenueMovement($encomenda);
            }

            return $encomenda->fresh(['itens.article.category', 'itens.productVariant', 'user', 'targetUser', 'invoice.fiscalDocumentRequests']);
        });
    }

    public function visibleForUser(Builder $query, User $user): Builder
    {
        if ($user->perfil === 'admin') {
            return $query;
        }

        $allowedIds = $this->profileResolver->allowedProfiles($user)->pluck('id')->all();

        return $query->where(function (Builder $subQuery) use ($user, $allowedIds) {
            $subQuery->where('user_id', $user->id)
                ->orWhereIn('target_user_id', $allowedIds);
        });
    }

    public function dashboardMetrics(): array
    {
        return [
            'total_produtos_ativos' => Product::query()->active()->visibleInStore()->count(),
            'produtos_sem_stock' => Product::query()->active()->visibleInStore()->whereRaw('(stock - COALESCE(stock_reservado, 0)) <= 0')->count(),
            'encomendas_pendentes' => LojaEncomenda::query()->where('estado', LojaEncomenda::ESTADO_PENDENTE)->count(),
            'encomendas_preparadas' => LojaEncomenda::query()->where('estado', LojaEncomenda::ESTADO_PREPARADO)->count(),
            'ultimos_pedidos' => LojaEncomenda::query()->with(['user:id,nome_completo', 'targetUser:id,nome_completo'])->ordered()->limit(5)->get(),
        ];
    }

    private function buildDescricao(Product $produto, ?ProductVariant $variante): string
    {
        if (! $variante || $variante->label === '') {
            return $produto->nome;
        }

        return $produto->nome . ' - ' . $variante->label;
    }

    private function requireArticleId(LojaCarrinhoItem $item): string
    {
        if (filled($item->article_id)) {
            return $item->article_id;
        }

        throw ValidationException::withMessages([
            'article_id' => 'O item do carrinho nao esta associado a um produto canonico valido.',
        ]);
    }

    private function generateNumero(): string
    {
        do {
            $numero = 'LJ-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        } while (LojaEncomenda::query()->where('numero', $numero)->exists());

        return $numero;
    }

    private function dispatchOperationalAlert(LojaEncomenda $order): void
    {
        $recipients = $this->operationalRecipients();

        if ($recipients->isEmpty()) {
            return;
        }

        $customerName = $order->user?->nome_completo ?: $order->user?->name ?: 'utilizador sem nome';
        $targetSuffix = $order->targetUser && $order->target_user_id !== $order->user_id
            ? ' para ' . ($order->targetUser->nome_completo ?: $order->targetUser->name ?: 'perfil associado')
            : '';

        $this->inAppAlertService->createAlerts([
            'title' => 'Nova encomenda na Loja',
            'message' => sprintf('Foi gerada a encomenda %s por %s%s.', $order->numero, $customerName, $targetSuffix),
            'link' => '/admin/loja/encomendas/' . $order->id,
            'type' => 'warning',
        ], $recipients);
    }

    private function operationalRecipients(): Collection
    {
        return User::query()
            ->where(function (Builder $builder) {
                $builder->where('estado', 'ativo')->orWhereNull('estado');
            })
            ->where(function (Builder $builder) {
                $builder->whereIn('perfil', ['admin', 'administrador', 'gestor', 'logistica'])
                    ->orWhereJsonContains('tipo_membro', 'admin')
                    ->orWhereJsonContains('tipo_membro', 'gestor')
                    ->orWhereJsonContains('tipo_membro', 'logistica');
            })
            ->get(['id'])
            ->map(fn (User $recipient) => ['user_id' => (string) $recipient->id])
            ->values();
    }
}

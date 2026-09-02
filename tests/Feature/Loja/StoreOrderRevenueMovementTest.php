<?php

namespace Tests\Feature\Loja;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Movement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOrderRevenueMovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivered_store_order_generates_revenue_movement_visible_in_admin_finance_and_user_portal(): void
    {
        $admin = User::factory()->admin()->create(['nome_completo' => 'Admin Financeiro']);
        $buyer = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
            'nome_completo' => 'Comprador Loja',
        ]);

        $product = Product::query()->create([
            'codigo' => 'LOJA-MOV-001',
            'slug' => 'fato-treino',
            'nome' => 'Fato de Treino',
            'preco' => 30,
            'preco_venda' => 35,
            'stock' => 10,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
            'track_stock' => true,
        ]);

        $this->actingAs($buyer)
            ->postJson('/api/loja/carrinho/itens', [
                'article_id' => $product->id,
                'quantidade' => 2,
            ])
            ->assertCreated();

        $response = $this->actingAs($buyer)
            ->postJson('/api/loja/carrinho/submeter', []);

        $orderId = (string) $response->assertCreated()->json('encomenda_id');

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/encomendas/' . $orderId . '/estado', [
                'estado' => 'entregue',
            ])
            ->assertOk()
            ->assertJsonPath('estado', 'entregue');

        $movement = Movement::query()
            ->where('origem_tipo', 'stock')
            ->where('origem_id', $orderId)
            ->firstOrFail();

        $this->assertSame($buyer->id, $movement->user_id);
        $this->assertSame('receita', $movement->classificacao);
        $this->assertSame('material', $movement->tipo);
        $this->assertSame('pendente', $movement->estado_pagamento);
        $this->assertDatabaseHas('movement_items', [
            'movimento_id' => $movement->id,
            'descricao' => 'Fato de Treino',
            'quantidade' => 2,
            'total_linha' => 70,
            'produto_id' => $product->id,
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/encomendas/' . $orderId . '/estado', [
                'estado' => 'cancelado',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->assertDatabaseHas('loja_encomendas', [
            'id' => $orderId,
            'estado' => 'entregue',
        ]);
        $this->assertDatabaseCount('movements', 1);
        $this->assertDatabaseMissing('stock_movements', [
            'movement_type' => 'return',
            'reference_type' => 'store_order_item',
        ]);

        $financeResponse = $this->inertiaGetAs($admin, route('financeiro.index'));
        $financeResponse->assertOk();
        $financeResponse->assertJsonPath('component', 'Financeiro/Index');
        $financeResponse->assertJsonPath('props.movimentos.0.id', $movement->id);
        $financeResponse->assertJsonPath('props.movimentos.0.tipo', 'material');

        $portalResponse = $this->inertiaGetAs($buyer, route('portal.payments'));
        $portalResponse->assertOk();
        $portalResponse->assertJsonPath('component', 'Portal/Payments');
        $portalResponse->assertJsonPath('props.hero.status', 'Pagamento pendente');
        $portalResponse->assertJsonPath('props.kpis.outstanding_value', 70);
        $portalResponse->assertJsonPath('props.movements.0.id', $movement->id);
        $portalResponse->assertJsonPath('props.movements.0.description', 'Loja - ' . $movement->referencia_pagamento);
    }

    private function inertiaGetAs(User $user, string $uri)
    {
        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get($uri);
    }
}

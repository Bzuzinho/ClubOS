<?php

namespace Tests\Feature\Loja;

use App\Models\LojaEncomenda;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCartOrderCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_cart_add_item_uses_canonical_article_and_variant_ids(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'codigo' => 'CAN-CART-001',
            'slug' => 'mochila-clube',
            'nome' => 'Mochila Clube',
            'preco' => 25,
            'preco_venda' => 27,
            'stock' => 12,
            'stock_reservado' => 2,
            'ativo' => true,
            'visible_in_store' => true,
            'track_stock' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Junior',
            'cor' => 'Azul',
            'sku' => 'CAN-CART-001-JR',
            'preco_extra' => 3,
            'stock' => 5,
            'stock_reservado' => 1,
            'ativo' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/loja/carrinho/itens', [
                'article_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantidade' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('items.0.produto.id', $product->id)
            ->assertJsonPath('items.0.produto.slug', 'mochila-clube')
            ->assertJsonPath('items.0.variante.id', $variant->id)
            ->assertJsonPath('items.0.variante.etiqueta', 'Junior / Azul');

        $this->assertDatabaseHas('loja_carrinho_itens', [
            'article_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantidade' => 2,
        ]);
    }

    public function test_submit_order_uses_canonical_article_ids_and_decrements_stock(): void
    {
        $admin = User::factory()->admin()->create(['nome_completo' => 'Admin Loja']);
        $user = User::factory()->create();
        $product = Product::query()->create([
            'codigo' => 'CAN-ORDER-001',
            'slug' => 'casaco-clube',
            'nome' => 'Casaco Clube',
            'preco' => 40,
            'preco_venda' => 42,
            'stock' => 9,
            'stock_reservado' => 1,
            'stock_minimo' => 1,
            'ativo' => true,
            'visible_in_store' => true,
            'track_stock' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/loja/carrinho/itens', [
                'article_id' => $product->id,
                'quantidade' => 3,
            ])
            ->assertCreated();

        $response = $this->actingAs($user)
            ->postJson('/api/loja/carrinho/submeter', []);

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Encomenda submetida com sucesso.');

        $order = LojaEncomenda::query()->latest()->firstOrFail();

        $this->assertDatabaseHas('loja_encomenda_itens', [
            'loja_encomenda_id' => $order->id,
            'article_id' => $product->id,
            'product_variant_id' => null,
            'quantidade' => 3,
            'descricao' => 'Casaco Clube',
        ]);

        $product->refresh();
        $this->assertSame(6, (int) $product->stock);
        $this->assertDatabaseHas('in_app_alerts', [
            'user_id' => $admin->id,
            'title' => 'Nova encomenda na Loja',
            'link' => '/admin/loja/encomendas/' . $order->id,
            'type' => 'warning',
            'is_read' => false,
        ]);

        $this->actingAs($user)
            ->getJson('/api/loja/encomendas/' . $order->id)
            ->assertOk()
            ->assertJsonPath('items.0.produto.id', $product->id)
            ->assertJsonPath('items.0.produto.slug', 'casaco-clube')
            ->assertJsonPath('items.0.total_linha', 126);
    }
}
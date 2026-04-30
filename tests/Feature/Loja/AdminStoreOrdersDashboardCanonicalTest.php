<?php

namespace Tests\Feature\Loja;

use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStoreOrdersDashboardCanonicalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_metrics_use_canonical_store_catalog_and_recent_orders(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create(['nome_completo' => 'Comprador Canonico']);

        Product::query()->create([
            'codigo' => 'DASH-001',
            'slug' => 'ativo-loja',
            'nome' => 'Ativo Loja',
            'preco' => 10,
            'preco_venda' => 12,
            'stock' => 0,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
        ]);

        Product::query()->create([
            'codigo' => 'DASH-002',
            'slug' => 'interno',
            'nome' => 'Interno',
            'preco' => 10,
            'preco_venda' => 12,
            'stock' => 5,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => false,
            'allow_sale' => true,
        ]);

        $order = LojaEncomenda::query()->create([
            'numero' => 'LJ-20260430-ABC123',
            'user_id' => $buyer->id,
            'target_user_id' => null,
            'estado' => LojaEncomenda::ESTADO_PENDENTE,
            'subtotal' => 36,
            'total' => 36,
            'observacoes' => null,
            'origem' => 'portal',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/loja/dashboard')
            ->assertOk()
            ->assertJsonPath('total_produtos_ativos', 1)
            ->assertJsonPath('produtos_sem_stock', 1)
            ->assertJsonPath('encomendas_pendentes', 1)
            ->assertJsonPath('ultimos_pedidos.0.id', $order->id)
            ->assertJsonPath('ultimos_pedidos.0.user', 'Comprador Canonico');
    }

    public function test_admin_order_detail_uses_canonical_order_item_relations(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create(['nome_completo' => 'Atleta Canonico']);
        $product = Product::query()->create([
            'codigo' => 'ORD-DET-001',
            'slug' => 'casaco-admin',
            'nome' => 'Casaco Admin',
            'preco' => 30,
            'preco_venda' => 33,
            'stock' => 10,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Adulto',
            'tamanho' => 'M',
            'cor' => 'Azul',
            'sku' => 'ORD-DET-001-M',
            'preco_extra' => 2,
            'stock' => 4,
            'stock_reservado' => 0,
            'ativo' => true,
        ]);

        $order = LojaEncomenda::query()->create([
            'numero' => 'LJ-20260430-XYZ789',
            'user_id' => $buyer->id,
            'target_user_id' => null,
            'estado' => LojaEncomenda::ESTADO_PREPARADO,
            'subtotal' => 70,
            'total' => 70,
            'observacoes' => 'Separar para entrega.',
            'origem' => 'portal',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        LojaEncomendaItem::query()->create([
            'loja_encomenda_id' => $order->id,
            'article_id' => $product->id,
            'product_variant_id' => $variant->id,
            'descricao' => 'Casaco Admin - Adulto / M / Azul',
            'quantidade' => 2,
            'preco_unitario' => 35,
            'total_linha' => 70,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/loja/encomendas/' . $order->id)
            ->assertOk()
            ->assertJsonPath('numero', 'LJ-20260430-XYZ789')
            ->assertJsonPath('items.0.produto.id', $product->id)
            ->assertJsonPath('items.0.produto.slug', 'casaco-admin')
            ->assertJsonPath('items.0.variante.id', $variant->id)
            ->assertJsonPath('items.0.variante.etiqueta', 'Adulto / M / Azul')
            ->assertJsonPath('items.0.total_linha', 70);
    }
}
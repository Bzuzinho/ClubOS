<?php

namespace Tests\Feature\Loja;

use App\Models\ItemCategory;
use App\Models\LojaHeroItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCatalogReadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_endpoint_uses_canonical_catalog_payload(): void
    {
        $user = User::factory()->create();
        $category = ItemCategory::query()->create([
            'codigo' => 'MERCH',
            'nome' => 'Merchandising',
            'contexto' => 'loja',
            'ativo' => true,
        ]);

        $product = Product::query()->create([
            'codigo' => 'CAN-STORE-001',
            'slug' => 'camisola-oficial',
            'nome' => 'Camisola Oficial',
            'descricao' => 'Produto canonico da loja.',
            'categoria_id' => $category->id,
            'preco' => 19.50,
            'preco_venda' => 21.00,
            'stock' => 8,
            'stock_reservado' => 3,
            'stock_minimo' => 2,
            'imagem' => '/storage/camisola.png',
            'ativo' => true,
            'visible_in_store' => true,
            'destaque' => true,
            'track_stock' => true,
        ]);

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Adulto',
            'tamanho' => 'M',
            'cor' => 'Azul',
            'sku' => 'CAN-STORE-001-M',
            'preco_extra' => 2.50,
            'stock' => 5,
            'stock_reservado' => 1,
            'ativo' => true,
        ]);

        Product::query()->create([
            'codigo' => 'CAN-STORE-002',
            'slug' => 'nao-visivel',
            'nome' => 'Nao Visivel',
            'preco' => 10.00,
            'ativo' => true,
            'visible_in_store' => false,
        ]);

        $this->actingAs($user)
            ->getJson('/api/loja/produtos')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $product->id)
            ->assertJsonPath('0.slug', 'camisola-oficial')
            ->assertJsonPath('0.preco', 21)
            ->assertJsonPath('0.imagem_principal_path', '/storage/camisola.png')
            ->assertJsonPath('0.gere_stock', true)
            ->assertJsonPath('0.stock_atual', 5)
            ->assertJsonPath('0.tem_stock_baixo', false)
            ->assertJsonPath('0.variantes.0.stock_atual', 4)
            ->assertJsonPath('0.variantes.0.etiqueta', 'Adulto / M / Azul');
    }

    public function test_product_detail_endpoint_resolves_canonical_product_by_slug(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'codigo' => 'CAN-DETAIL-001',
            'slug' => 'fato-treino',
            'nome' => 'Fato de Treino',
            'preco' => 30.00,
            'preco_venda' => 32.00,
            'stock' => 6,
            'stock_reservado' => 1,
            'ativo' => true,
            'visible_in_store' => true,
            'track_stock' => true,
        ]);

        $this->actingAs($user)
            ->getJson('/api/loja/produtos/fato-treino')
            ->assertOk()
            ->assertJsonPath('id', $product->id)
            ->assertJsonPath('slug', 'fato-treino')
            ->assertJsonPath('preco', 32)
            ->assertJsonPath('stock_atual', 5);
    }

    public function test_categories_endpoint_uses_canonical_store_visibility(): void
    {
        $user = User::factory()->create();
        $visibleCategory = ItemCategory::query()->create([
            'codigo' => 'EQUIP',
            'nome' => 'Equipamento',
            'contexto' => 'loja',
            'ativo' => true,
        ]);
        $hiddenCategory = ItemCategory::query()->create([
            'codigo' => 'OUTRO',
            'nome' => 'Outro',
            'contexto' => 'loja',
            'ativo' => true,
        ]);

        Product::query()->create([
            'codigo' => 'CAN-CAT-001',
            'slug' => 'calcoes',
            'nome' => 'Calcoes',
            'categoria_id' => $visibleCategory->id,
            'preco' => 10,
            'ativo' => true,
            'visible_in_store' => true,
        ]);

        Product::query()->create([
            'codigo' => 'CAN-CAT-002',
            'slug' => 'interno',
            'nome' => 'Interno',
            'categoria_id' => $hiddenCategory->id,
            'preco' => 10,
            'ativo' => true,
            'visible_in_store' => false,
        ]);

        $this->actingAs($user)
            ->getJson('/api/loja/categorias')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $visibleCategory->id)
            ->assertJsonPath('0.nome', 'Equipamento');
    }

    public function test_hero_endpoint_uses_canonical_article_target(): void
    {
        $user = User::factory()->create();
        $canonicalProduct = Product::query()->create([
            'codigo' => 'CAN-HERO-001',
            'slug' => 'hero-canonico',
            'nome' => 'Hero Canonico',
            'preco' => 12,
            'ativo' => true,
            'visible_in_store' => true,
        ]);

        LojaHeroItem::query()->create([
            'titulo_principal' => 'Hero principal',
            'tipo_destino' => LojaHeroItem::DESTINO_PRODUTO,
            'article_id' => $canonicalProduct->id,
            'ativo' => true,
            'ordem' => 1,
        ]);

        $this->actingAs($user)
            ->getJson('/api/loja/hero')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.produto.id', $canonicalProduct->id)
            ->assertJsonPath('0.produto.slug', 'hero-canonico')
            ->assertJsonPath('0.produto.nome', 'Hero Canonico');
    }
}
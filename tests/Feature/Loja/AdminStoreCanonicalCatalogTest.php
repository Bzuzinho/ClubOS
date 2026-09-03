<?php

namespace Tests\Feature\Loja;

use App\Models\ItemCategory;
use App\Models\LojaHeroItem;
use App\Models\Product;
use App\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminStoreCanonicalCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_store_product_in_canonical_catalog(): void
    {
        $admin = User::factory()->admin()->create();
        $category = ItemCategory::query()->create([
            'codigo' => 'MERCH',
            'nome' => 'Merchandising',
            'contexto' => 'loja',
            'ativo' => true,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/api/admin/loja/produtos', [
                'categoria_id' => $category->id,
                'codigo' => 'ADM-CAN-001',
                'nome' => 'Polo Staff',
                'slug' => 'polo-staff',
                'descricao' => 'Produto canónico criado no admin.',
                'preco' => 29.90,
                'imagem_principal_path' => '/storage/polo.png',
                'ativo' => true,
                'destaque' => true,
                'gere_stock' => true,
                'stock_atual' => 14,
                'stock_minimo' => 3,
                'ordem' => 5,
                'variantes' => [
                    [
                        'nome' => 'Adulto',
                        'tamanho' => 'L',
                        'cor' => 'Branco',
                        'sku' => 'ADM-CAN-001-L',
                        'preco_extra' => 2.5,
                        'stock_atual' => 6,
                        'ativo' => true,
                    ],
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('slug', 'polo-staff')
            ->assertJsonPath('preco', 29.9)
            ->assertJsonPath('imagem_principal_path', '/storage/polo.png')
            ->assertJsonPath('stock_atual', 6)
            ->assertJsonPath('variantes.0.stock_atual', 6);

        $product = Product::query()->where('codigo', 'ADM-CAN-001')->firstOrFail();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'categoria_id' => $category->id,
            'slug' => 'polo-staff',
            'preco_venda' => 29.90,
            'imagem' => '/storage/polo.png',
            'allow_sale' => true,
            'visible_in_store' => true,
            'track_stock' => true,
            'stock' => 6,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'product_id' => $product->id,
            'sku' => 'ADM-CAN-001-L',
            'stock' => 6,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'product_variant_id' => $product->variants()->firstOrFail()->id,
            'movement_type' => 'adjustment',
            'quantity' => 6,
            'reference_type' => 'catalog_manual_adjustment',
        ]);
    }

    public function test_admin_delete_removes_product_from_store_without_deleting_canonical_row(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::query()->create([
            'codigo' => 'ADM-CAN-DEL-001',
            'slug' => 'produto-removivel',
            'nome' => 'Produto Removível',
            'preco' => 10,
            'preco_venda' => 12,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
            'destaque' => true,
        ]);

        $this->actingAs($admin)
            ->deleteJson('/api/admin/loja/produtos/' . $product->id)
            ->assertOk()
            ->assertJsonPath('message', 'Produto removido da loja com sucesso.');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'allow_sale' => false,
            'visible_in_store' => false,
            'destaque' => false,
        ]);
    }

    public function test_store_edit_preserves_logistics_capabilities_on_shared_product(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'codigo' => 'ADM-SHARED-H5E',
            'slug' => 'artigo-partilhado-h5e',
            'nome' => 'Artigo partilhado H5e',
            'preco' => 12,
            'preco_venda' => 15,
            'stock' => 5,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
        ]);

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/produtos/'.$product->id, [
                'categoria_id' => null,
                'codigo' => $product->codigo,
                'nome' => 'Artigo partilhado atualizado',
                'slug' => $product->slug,
                'descricao' => null,
                'preco' => 16,
                'imagem_principal_path' => null,
                'ativo' => true,
                'destaque' => false,
                'gere_stock' => true,
                'stock_atual' => 5,
                'stock_minimo' => 0,
                'ordem' => null,
                'variantes' => [],
            ])
            ->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'allow_sale' => true,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
        ]);
    }

    public function test_admin_hero_accepts_canonical_product_id_and_lists_it_back(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::query()->create([
            'codigo' => 'ADM-HERO-001',
            'slug' => 'hero-canonico-admin',
            'nome' => 'Hero Canónico Admin',
            'preco' => 20,
            'preco_venda' => 20,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
            'imagem' => '/storage/destaque.png',
        ]);

        $this->actingAs($admin)
            ->postJson('/api/admin/loja/hero', [
                'produto_id' => $product->id,
                'ativo' => true,
                'ordem' => 1,
            ])
            ->assertCreated()
            ->assertJsonPath('produto_id', $product->id)
            ->assertJsonPath('produto.id', $product->id)
            ->assertJsonPath('produto.nome', 'Hero Canónico Admin')
            ->assertJsonPath('produto.imagem_principal_path', '/storage/destaque.png');

        $this->assertDatabaseHas('loja_hero_items', [
            'article_id' => $product->id,
            'titulo_principal' => 'Hero Canónico Admin',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/loja/hero')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.produto_id', $product->id)
            ->assertJsonPath('0.produto.id', $product->id)
            ->assertJsonPath('0.produto.nome', 'Hero Canónico Admin');

        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        $this->actingAs($admin)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-Inertia-Version' => (string) $inertiaVersion,
            ])
            ->get('/loja')
            ->assertOk()
            ->assertJsonPath('props.featuredProducts.0.id', $product->id)
            ->assertJsonPath('props.featuredProducts.0.nome', 'Hero Canónico Admin');
    }
}

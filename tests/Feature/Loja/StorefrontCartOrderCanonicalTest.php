<?php

namespace Tests\Feature\Loja;

use App\Models\CostCenter;
use App\Models\Familia;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\LojaEncomenda;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Loja\LojaFinanceiroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
        $invoice = Invoice::query()->findOrFail($order->fatura_id);

        $this->assertDatabaseHas('loja_encomenda_itens', [
            'loja_encomenda_id' => $order->id,
            'article_id' => $product->id,
            'product_variant_id' => null,
            'quantidade' => 3,
            'descricao' => 'Casaco Clube',
        ]);

        $product->refresh();
        $this->assertSame(6, (int) $product->stock);
        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'quantity' => 3,
            'reference_type' => 'store_order_item',
        ]);
        $this->assertSame($user->id, $invoice->user_id);
        $this->assertSame('store_order', $invoice->origem_tipo);
        $this->assertSame($order->id, $invoice->origem_id);
        $this->assertSame('material', $invoice->tipo);
        $this->assertSame('pendente', $invoice->estado_pagamento);
        $this->assertSame(126.0, (float) $invoice->valor_total);
        $this->assertSame(126.0, (float) $invoice->valor_em_aberto);
        $this->assertDatabaseHas('invoice_items', [
            'fatura_id' => $invoice->id,
            'descricao' => 'Casaco Clube',
            'quantidade' => 3,
            'total_linha' => 126,
            'produto_id' => $product->id,
        ]);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => 'invoice_item',
            'article_id' => $product->id,
        ]);
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
            ->assertJsonPath('items.0.total_linha', 126)
            ->assertJsonPath('invoice.id', $invoice->id)
            ->assertJsonPath('invoice.estado_pagamento', 'pendente')
            ->assertJsonPath('invoice.valor_em_aberto', 126);
    }

    public function test_store_invoice_uses_target_profile_cost_center_and_is_idempotent(): void
    {
        $buyer = User::factory()->create();
        $target = User::factory()->create();
        $costCenter = CostCenter::query()->create([
            'codigo' => 'LOJA-H5B',
            'nome' => 'Loja H5b',
            'tipo' => 'equipa',
            'ativo' => true,
        ]);
        $target->centrosCusto()->attach($costCenter->id, [
            'id' => (string) Str::uuid(),
            'peso' => 1,
        ]);

        $family = Familia::query()->create([
            'nome' => 'Família Loja H5b',
            'responsavel_user_id' => $buyer->id,
            'ativo' => true,
        ]);
        $family->members()->attach($buyer->id, [
            'id' => (string) Str::uuid(),
            'papel_na_familia' => 'responsavel',
            'pode_editar' => true,
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
        ]);
        $family->members()->attach($target->id, [
            'id' => (string) Str::uuid(),
            'papel_na_familia' => 'educando',
            'pode_editar' => false,
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
        ]);

        $product = Product::query()->create([
            'codigo' => 'CAN-ORDER-H5B',
            'slug' => 'produto-h5b',
            'nome' => 'Produto H5b',
            'preco' => 18,
            'preco_venda' => 20,
            'stock' => 5,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'track_stock' => true,
        ]);

        $this->actingAs($buyer)->postJson('/api/loja/carrinho/itens', [
            'article_id' => $product->id,
            'quantidade' => 2,
        ])->assertCreated();

        $orderId = (string) $this->actingAs($buyer)
            ->postJson('/api/loja/carrinho/submeter', ['target_user_id' => $target->id])
            ->assertCreated()
            ->json('encomenda_id');

        $order = LojaEncomenda::query()->findOrFail($orderId);
        $invoice = Invoice::query()->findOrFail($order->fatura_id);

        $this->assertSame($target->id, $invoice->user_id);
        $this->assertSame($costCenter->id, $invoice->centro_custo_id);
        $this->assertSame($costCenter->id, InvoiceItem::query()->where('fatura_id', $invoice->id)->value('centro_custo_id'));

        $resolvedId = app(LojaFinanceiroService::class)->prepareForOrder($order->fresh());

        $this->assertSame($invoice->id, $resolvedId);
        $this->assertSame(1, Invoice::query()->where('origem_tipo', 'store_order')->where('origem_id', $order->id)->count());
        $this->assertSame(1, InvoiceItem::query()->where('fatura_id', $invoice->id)->count());
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'store_order_item')->count());
    }

    public function test_submit_order_with_variant_keeps_variant_snapshot_outside_product_ledger(): void
    {
        $user = User::factory()->create();
        $product = Product::query()->create([
            'codigo' => 'CAN-ORDER-002',
            'slug' => 'camisola-clube',
            'nome' => 'Camisola Clube',
            'preco' => 30,
            'preco_venda' => 35,
            'stock' => 10,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'ativo' => true,
            'visible_in_store' => true,
            'track_stock' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Senior',
            'cor' => 'Azul',
            'sku' => 'CAN-ORDER-002-SR',
            'preco_extra' => 5,
            'stock' => 4,
            'stock_reservado' => 0,
            'ativo' => true,
        ]);

        $this->actingAs($user)
            ->postJson('/api/loja/carrinho/itens', [
                'article_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantidade' => 2,
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/loja/carrinho/submeter', [])
            ->assertCreated();

        $order = LojaEncomenda::query()->latest()->firstOrFail();
        $orderItem = $order->itens()->firstOrFail();

        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::query()
            ->where('article_id', $product->id)
            ->where('movement_type', 'exit')
            ->where('quantity', 2)
            ->where('reference_type', 'store_order_item')
            ->where('reference_id', $orderItem->id)
            ->count());
    }

    public function test_cancelling_order_restores_product_and_variant_stock_once_and_is_terminal(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $product = Product::query()->create([
            'codigo' => 'CAN-CANCEL-001',
            'slug' => 'camisola-cancelamento',
            'nome' => 'Camisola Cancelamento',
            'preco' => 30,
            'preco_venda' => 35,
            'stock' => 10,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'track_stock' => true,
        ]);
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'nome' => 'Senior',
            'tamanho' => 'M',
            'sku' => 'CAN-CANCEL-001-M',
            'preco_extra' => 2,
            'stock' => 4,
            'stock_reservado' => 0,
            'ativo' => true,
        ]);

        $this->actingAs($buyer)->postJson('/api/loja/carrinho/itens', [
            'article_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantidade' => 2,
        ])->assertCreated();

        $orderId = (string) $this->actingAs($buyer)
            ->postJson('/api/loja/carrinho/submeter', [])
            ->assertCreated()
            ->json('encomenda_id');
        $order = LojaEncomenda::query()->findOrFail($orderId);
        $orderItem = $order->itens()->firstOrFail();

        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(2, (int) $variant->fresh()->stock);

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/encomendas/'.$orderId.'/estado', ['estado' => 'cancelado'])
            ->assertOk()
            ->assertJsonPath('estado', 'cancelado');

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(4, (int) $variant->fresh()->stock);
        $this->assertSame(1, StockMovement::query()
            ->where('article_id', $product->id)
            ->where('product_variant_id', $variant->id)
            ->where('movement_type', 'return')
            ->where('quantity', 2)
            ->where('reference_type', 'store_order_item')
            ->where('reference_id', $orderItem->id)
            ->count());

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/encomendas/'.$orderId.'/estado', ['estado' => 'cancelado'])
            ->assertOk();

        $this->assertSame(1, StockMovement::query()
            ->where('movement_type', 'return')
            ->where('reference_type', 'store_order_item')
            ->where('reference_id', $orderItem->id)
            ->count());

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/encomendas/'.$orderId.'/estado', ['estado' => 'aprovado'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->assertSame(LojaEncomenda::ESTADO_CANCELADO, $order->fresh()->estado);
        $this->assertSame('cancelado', $order->fresh()->invoice->estado_pagamento);
        $this->assertSame(0.0, (float) $order->fresh()->invoice->valor_em_aberto);
        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertSame(4, (int) $variant->fresh()->stock);
    }

    public function test_cancellation_fails_closed_when_order_has_financial_invoice_reference(): void
    {
        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create();
        $product = Product::query()->create([
            'codigo' => 'CAN-CANCEL-002',
            'slug' => 'produto-com-fatura',
            'nome' => 'Produto com Fatura',
            'preco' => 20,
            'preco_venda' => 25,
            'stock' => 5,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'track_stock' => true,
        ]);

        $this->actingAs($buyer)->postJson('/api/loja/carrinho/itens', [
            'article_id' => $product->id,
            'quantidade' => 1,
        ])->assertCreated();

        $orderId = (string) $this->actingAs($buyer)
            ->postJson('/api/loja/carrinho/submeter', [])
            ->assertCreated()
            ->json('encomenda_id');
        $order = LojaEncomenda::query()->findOrFail($orderId);
        $invoice = $order->invoice()->firstOrFail();
        $invoice->forceFill([
            'estado_pagamento' => 'pago',
            'valor_pago' => $invoice->valor_total,
            'valor_em_aberto' => 0,
            'data_pagamento' => now()->toDateString(),
        ])->saveQuietly();

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/encomendas/'.$orderId.'/estado', ['estado' => 'cancelado'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('estado');

        $this->assertSame(LojaEncomenda::ESTADO_PENDENTE, $order->fresh()->estado);
        $this->assertSame(4, (int) $product->fresh()->stock);
        $this->assertSame(0, StockMovement::query()
            ->where('movement_type', 'return')
            ->where('reference_type', 'store_order_item')
            ->count());
    }
}

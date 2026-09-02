<?php

namespace Tests\Feature\Loja;

use App\Models\DadosPessoais;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\LojaEncomenda;
use App\Models\Movement;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Financeiro\FinancialSettlementService;
use App\Services\Financeiro\FiscalDocumentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOrderPaymentFiscalProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_allocations_project_payment_and_manual_wintouch_fiscal_states_without_changing_logistics(): void
    {
        config()->set('fiscal.operation_mode', 'manual_wintouch');
        config()->set('fiscal.provider', 'wintouch');

        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
            'nome_completo' => 'Comprador H5c',
            'email' => 'comprador-h5c@example.test',
        ]);
        DadosPessoais::query()->create([
            'user_id' => $buyer->id,
            'nome_completo' => 'Comprador H5c',
            'nif' => '211111111',
            'morada' => 'Rua Canónica 5',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa',
        ]);
        $product = Product::query()->create([
            'codigo' => 'LOJA-H5C-001',
            'slug' => 'produto-h5c',
            'nome' => 'Produto H5c',
            'preco' => 20,
            'preco_venda' => 25,
            'stock' => 8,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
            'track_stock' => true,
        ]);

        $this->actingAs($buyer)->postJson('/api/loja/carrinho/itens', [
            'article_id' => $product->id,
            'quantidade' => 2,
        ])->assertCreated();

        $orderId = (string) $this->actingAs($buyer)
            ->postJson('/api/loja/carrinho/submeter', [])
            ->assertCreated()
            ->json('encomenda_id');
        $order = LojaEncomenda::query()->findOrFail($orderId);
        $invoice = Invoice::query()->findOrFail($order->fatura_id);

        $this->actingAs($buyer)
            ->getJson('/api/loja/encomendas/'.$orderId)
            ->assertOk()
            ->assertJsonPath('estado', 'pendente')
            ->assertJsonPath('financeiro.estado_pagamento', 'pendente')
            ->assertJsonPath('financeiro.valor_pago', 0)
            ->assertJsonPath('financeiro.valor_em_aberto', 50)
            ->assertJsonPath('financeiro.estado_fiscal', 'aguarda_pagamento')
            ->assertJsonPath('financeiro.modo_fiscal', 'manual_wintouch');

        app(FinancialSettlementService::class)->settleInvoices([
            ['invoice_id' => $invoice->id, 'amount' => 20],
        ], [
            'amount' => 20,
            'method' => 'dinheiro',
            'payment_date' => now()->toDateString(),
            'user_id' => $buyer->id,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/loja/encomendas/'.$orderId)
            ->assertOk()
            ->assertJsonPath('estado', 'pendente')
            ->assertJsonPath('financeiro.estado_pagamento', 'parcial')
            ->assertJsonPath('financeiro.pagamento_confirmado', false)
            ->assertJsonPath('financeiro.valor_pago', 20)
            ->assertJsonPath('financeiro.valor_em_aberto', 30)
            ->assertJsonPath('financeiro.estado_fiscal', 'aguarda_pagamento');
        $this->assertDatabaseCount('fiscal_document_requests', 0);

        app(FinancialSettlementService::class)->settleInvoices([
            ['invoice_id' => $invoice->id, 'amount' => 30],
        ], [
            'amount' => 30,
            'method' => 'dinheiro',
            'payment_date' => now()->toDateString(),
            'user_id' => $buyer->id,
            'created_by' => $admin->id,
        ]);

        $request = FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->sole();

        $this->assertSame(FiscalDocumentRequest::PROVIDER_WINTOUCH, $request->provider);
        $this->assertSame(FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT, $request->document_type);
        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $request->status);
        $this->assertSame(50.0, (float) $request->amount);
        $this->assertSame(2, PaymentAllocation::query()->where('invoice_id', $invoice->id)->confirmed()->count());

        $this->actingAs($buyer)
            ->getJson('/api/loja/encomendas/'.$orderId)
            ->assertOk()
            ->assertJsonPath('estado', 'pendente')
            ->assertJsonPath('financeiro.estado_pagamento', 'pago')
            ->assertJsonPath('financeiro.pagamento_confirmado', true)
            ->assertJsonPath('financeiro.valor_pago', 50)
            ->assertJsonPath('financeiro.valor_em_aberto', 0)
            ->assertJsonPath('financeiro.estado_fiscal', 'pendente_emissao')
            ->assertJsonPath('financeiro.provider_fiscal', 'wintouch');

        app(FiscalDocumentRequestService::class)->markIssued($request, [
            'external_document_number' => 'FT WINT/2026/51',
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'issued_at' => now(),
        ], $admin->id);

        $this->actingAs($admin)
            ->getJson('/api/admin/loja/encomendas/'.$orderId)
            ->assertOk()
            ->assertJsonPath('estado', 'pendente')
            ->assertJsonPath('financeiro.estado_pagamento', 'pago')
            ->assertJsonPath('financeiro.estado_fiscal', 'emitido')
            ->assertJsonPath('financeiro.numero_documento_fiscal', 'FT WINT/2026/51');

        $this->assertSame(LojaEncomenda::ESTADO_PENDENTE, $order->fresh()->estado);
        $this->assertDatabaseCount('fiscal_document_requests', 1);
        $this->assertSame(0, Movement::query()->count());
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'store_order_item')
            ->where('movement_type', 'exit')
            ->count());
    }
}

<?php

namespace Tests\Feature\Loja;

use App\Models\DadosPessoais;
use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\LojaEncomenda;
use App\Models\LojaEncomendaDevolucao;
use App\Models\PaymentAllocation;
use App\Models\PaymentReversal;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Financeiro\FinancialSettlementService;
use App\Services\Financeiro\FiscalDocumentRequestService;
use App\Services\Inventario\StoreLogisticsStockAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StoreOrderDeliveredReturnReversalTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivered_paid_order_waits_for_wintouch_credit_note_then_reverses_history_and_restocks_once(): void
    {
        config()->set('fiscal.operation_mode', 'manual_wintouch');
        config()->set('fiscal.provider', 'wintouch');

        $admin = User::factory()->admin()->create();
        $buyer = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
            'nome_completo' => 'Comprador H5d',
            'email' => 'comprador-h5d@example.test',
        ]);
        DadosPessoais::query()->create([
            'user_id' => $buyer->id,
            'nome_completo' => 'Comprador H5d',
            'nif' => '222222222',
            'morada' => 'Rua da Devolução 5',
            'codigo_postal' => '2000-200',
            'localidade' => 'Santarém',
        ]);
        $product = Product::query()->create([
            'codigo' => 'LOJA-H5D-001',
            'slug' => 'produto-h5d',
            'nome' => 'Produto H5d',
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
        $orderItem = $order->itens()->sole();

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/encomendas/'.$orderId.'/estado', ['estado' => 'entregue'])
            ->assertOk()
            ->assertJsonPath('estado', 'entregue');

        $payment = app(FinancialSettlementService::class)->settleInvoices([
            ['invoice_id' => $invoice->id, 'amount' => 50],
        ], [
            'amount' => 50,
            'method' => 'dinheiro',
            'payment_date' => now()->toDateString(),
            'user_id' => $buyer->id,
            'created_by' => $admin->id,
        ]);
        $allocation = PaymentAllocation::query()->where('invoice_id', $invoice->id)->sole();
        $receipt = FiscalDocumentRequest::query()
            ->where('invoice_id', $invoice->id)
            ->where('document_type', FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT)
            ->sole();
        app(FiscalDocumentRequestService::class)->markIssued($receipt, [
            'external_document_number' => 'RC WINT/2026/500',
            'issued_at' => now(),
        ], $admin->id);

        $this->actingAs($admin)
            ->postJson('/api/admin/loja/encomendas/'.$orderId.'/devolucao', [
                'motivo' => 'Equipamento devolvido após a entrega.',
            ])
            ->assertOk()
            ->assertJsonPath('estado', 'entregue')
            ->assertJsonPath('devolucao.estado', LojaEncomendaDevolucao::ESTADO_AGUARDA_NOTA_CREDITO)
            ->assertJsonPath('financeiro.estado_fiscal', 'nota_credito_pendente');

        $return = LojaEncomendaDevolucao::query()->where('loja_encomenda_id', $orderId)->sole();
        $creditNote = FiscalDocumentRequest::query()
            ->where('invoice_id', $invoice->id)
            ->where('document_type', FiscalDocumentRequest::DOCUMENT_TYPE_CREDIT_NOTE)
            ->sole();

        $this->assertSame(FiscalDocumentRequest::PROVIDER_WINTOUCH, $creditNote->provider);
        $this->assertSame($receipt->id, data_get($creditNote->metadata, 'reverses_fiscal_document_request_id'));
        $this->assertSame(6, (int) $product->fresh()->stock);
        $this->assertSame(0, StockMovement::query()->where('movement_type', 'return')->count());
        $this->assertSame(0, PaymentReversal::query()->count());
        $this->assertSame(PaymentAllocation::STATUS_CONFIRMED, $allocation->fresh()->status);
        $this->assertSame('pago', $invoice->fresh()->estado_pagamento);

        app(FiscalDocumentRequestService::class)->markIssued($creditNote, [
            'external_document_number' => 'NC WINT/2026/501',
            'issued_at' => now(),
        ], $admin->id);

        $this->actingAs($admin)
            ->postJson('/api/admin/loja/encomendas/'.$orderId.'/devolucao', [
                'motivo' => 'Equipamento devolvido após a entrega.',
            ])
            ->assertOk()
            ->assertJsonPath('estado', 'devolvido')
            ->assertJsonPath('devolucao.estado', LojaEncomendaDevolucao::ESTADO_CONCLUIDA)
            ->assertJsonPath('financeiro.estado_pagamento', 'cancelado')
            ->assertJsonPath('financeiro.estado_fiscal', 'revertido')
            ->assertJsonPath('financeiro.numero_documento_fiscal', 'NC WINT/2026/501');

        $this->assertSame(FiscalDocumentRequest::STATUS_CANCELLED, $receipt->fresh()->status);
        $this->assertSame(FiscalDocumentRequest::STATUS_ISSUED, $creditNote->fresh()->status);
        $this->assertSame('cancelado', $invoice->fresh()->estado_pagamento);
        $this->assertSame('RC WINT/2026/500', $invoice->fresh()->numero_recibo);
        $this->assertSame(PaymentAllocation::STATUS_CANCELLED, $allocation->fresh()->status);
        $this->assertFalse($allocation->fresh()->trashed());
        $this->assertSame(1, PaymentReversal::query()->where('payment_allocation_id', $allocation->id)->count());
        $this->assertSame(0.0, (float) $payment->fresh()->unallocated_amount);
        $this->assertDatabaseHas('financial_entries', [
            'origem_tipo' => 'payment_allocation',
            'origem_id' => $allocation->id,
            'estado' => 'cancelado',
        ]);
        $this->assertSame(1, FinancialEntry::query()
            ->where('origem_tipo', 'store_order_return')
            ->where('origem_id', $return->id)
            ->where('tipo', 'despesa')
            ->count());
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame(1, StockMovement::query()
            ->where('reference_type', 'store_order_item')
            ->where('reference_id', $orderItem->id)
            ->where('movement_type', 'return')
            ->count());

        $this->actingAs($admin)
            ->postJson('/api/admin/loja/encomendas/'.$orderId.'/devolucao', [
                'motivo' => 'Equipamento devolvido após a entrega.',
            ])
            ->assertOk()
            ->assertJsonPath('estado', 'devolvido');

        $this->assertSame(1, PaymentReversal::query()->count());
        $this->assertSame(1, FinancialEntry::query()->where('origem_tipo', 'store_order_return')->count());
        $this->assertSame(1, StockMovement::query()->where('movement_type', 'return')->count());
        $this->assertSame(8, (int) $product->fresh()->stock);

        $audit = app(StoreLogisticsStockAuditService::class)->audit(['order' => $orderId]);
        $this->assertSame(1, $audit['summary']['store_returns_completed_clean_count']);
        $this->assertSame(0, $audit['summary']['store_returns_inconsistent_count']);
        $this->assertSame(1, $audit['summary']['returned_stock_restored_count']);
        $this->assertSame(0, $audit['summary']['returned_stock_unbalanced_count']);
        $this->assertSame(0, $audit['summary']['critical_count']);
        $this->assertSame(0, $audit['summary']['warning_count']);
        $this->assertSame(0, $audit['summary']['actionable_count']);
    }
}

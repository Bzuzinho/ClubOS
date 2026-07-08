<?php

namespace Tests\Feature\Logistica;

use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FiscalDocumentRequest;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LogisticsRequestFinancialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_is_blocked_when_invoice_is_paid(): void
    {
        [$admin, $request, $product] = $this->createInvoicedRequest();

        Invoice::query()->whereKey($request->financial_invoice_id)->update([
            'estado_pagamento' => 'pago',
            'valor_pago' => 40,
            'valor_em_aberto' => 0,
            'data_pagamento' => now()->toDateString(),
        ]);

        $this->actingAs($admin)
            ->put(route('logistica.requisicoes.update', $request->id), $this->updatePayload($request, $product, 3))
            ->assertStatus(302)
            ->assertSessionHasErrors('request');
    }

    public function test_update_is_blocked_when_allocation_exists(): void
    {
        [$admin, $request, $product] = $this->createInvoicedRequest();
        $invoice = Invoice::query()->findOrFail($request->financial_invoice_id);

        $payment = Payment::query()->create([
            'amount' => 40,
            'allocated_amount' => 40,
            'unallocated_amount' => 0,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->put(route('logistica.requisicoes.update', $request->id), $this->updatePayload($request, $product, 3))
            ->assertStatus(302)
            ->assertSessionHasErrors('request');
    }

    public function test_delete_is_blocked_when_fiscal_document_is_issued(): void
    {
        [$admin, $request] = $this->createInvoicedRequest();
        $invoice = Invoice::query()->findOrFail($request->financial_invoice_id);

        FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 40,
            'external_document_number' => 'FT-XFIN5-001',
        ]);

        $this->actingAs($admin)
            ->delete(route('logistica.requisicoes.destroy', $request->id))
            ->assertStatus(302)
            ->assertSessionHasErrors('request');
    }

    public function test_delete_is_blocked_when_invoice_is_reconciled(): void
    {
        [$admin, $request] = $this->createInvoicedRequest();
        $invoice = Invoice::query()->findOrFail($request->financial_invoice_id);

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'receita',
            'categoria' => 'Teste',
            'descricao' => 'Entry XFIN5',
            'valor' => 40,
            'estado' => 'pendente',
        ]);

        $statement = BankStatement::query()->create([
            'data_movimento' => now()->toDateString(),
            'descricao' => 'Extrato XFIN5',
            'valor' => 40,
        ]);

        $payment = Payment::query()->create([
            'amount' => 40,
            'allocated_amount' => 40,
            'unallocated_amount' => 0,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        $allocation = PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        MapaConciliacao::query()->create([
            'extrato_id' => $statement->id,
            'lancamento_id' => $entry->id,
            'fatura_id' => $invoice->id,
            'payment_allocation_id' => $allocation->id,
            'status' => 'confirmado',
            'valor_conciliado' => 40,
        ]);

        $this->actingAs($admin)
            ->delete(route('logistica.requisicoes.destroy', $request->id))
            ->assertStatus(302)
            ->assertSessionHasErrors('request');
    }

    public function test_delete_pending_invoiced_request_deletes_pending_fiscal_requests_and_invoice(): void
    {
        [$admin, $request] = $this->createInvoicedRequest();
        $invoice = Invoice::query()->findOrFail($request->financial_invoice_id);

        $fiscalRequest = FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 40,
        ]);

        $this->actingAs($admin)
            ->delete(route('logistica.requisicoes.destroy', $request->id))
            ->assertRedirect(route('logistica.index'));

        $this->assertDatabaseMissing('logistics_requests', ['id' => $request->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertSoftDeleted('fiscal_document_requests', ['id' => $fiscalRequest->id]);
    }

    public function test_invoice_uses_single_canonical_cost_center_when_available(): void
    {
        $admin = User::factory()->create();
        $requester = User::factory()->athlete()->create();

        $primaryCenter = CostCenter::query()->create([
            'codigo' => 'CC-XFIN5-1',
            'nome' => 'Centro XFIN5 A',
            'ativo' => true,
        ]);

        $secondaryCenter = CostCenter::query()->create([
            'codigo' => 'CC-XFIN5-2',
            'nome' => 'Centro XFIN5 B',
            'ativo' => true,
        ]);

        $requester->centrosCusto()->attach($primaryCenter->id, [
            'id' => (string) Str::uuid(),
            'peso' => 80,
        ]);
        $requester->centrosCusto()->attach($secondaryCenter->id, [
            'id' => (string) Str::uuid(),
            'peso' => 20,
        ]);

        $product = Product::query()->create([
            'codigo' => 'ART-XFIN5-CC',
            'nome' => 'Produto XFIN5 CC',
            'categoria' => 'Material',
            'preco' => 10,
            'stock' => 50,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.store'), [
                'requester_user_id' => $requester->id,
                'requester_name_snapshot' => $requester->nome_completo,
                'requester_area' => 'Natação',
                'requester_type' => 'Atleta',
                'status' => 'pending',
                'items' => [[
                    'article_id' => $product->id,
                    'quantity' => 4,
                    'unit_price' => 10,
                ]],
            ])
            ->assertRedirect(route('logistica.index'));

        $request = LogisticsRequest::query()->latest()->firstOrFail();

        $this->actingAs($admin)->post(route('logistica.requisicoes.approve', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.invoice', $request->id))->assertRedirect(route('logistica.index'));

        $invoice = Invoice::query()->findOrFail($request->fresh()->financial_invoice_id);

        $this->assertSame('logistics_request', $invoice->origem_tipo);
        $this->assertSame((string) $primaryCenter->id, (string) $invoice->centro_custo_id);
    }

    public function test_invoice_center_is_null_when_top_weights_are_tied(): void
    {
        $admin = User::factory()->create();
        $requester = User::factory()->athlete()->create();

        $centerA = CostCenter::query()->create([
            'codigo' => 'CC-XFIN5-TIE-A',
            'nome' => 'Centro XFIN5 Tie A',
            'ativo' => true,
        ]);
        $centerB = CostCenter::query()->create([
            'codigo' => 'CC-XFIN5-TIE-B',
            'nome' => 'Centro XFIN5 Tie B',
            'ativo' => true,
        ]);

        $requester->centrosCusto()->attach($centerA->id, [
            'id' => (string) Str::uuid(),
            'peso' => 50,
        ]);
        $requester->centrosCusto()->attach($centerB->id, [
            'id' => (string) Str::uuid(),
            'peso' => 50,
        ]);

        [$__, $request, $product] = $this->createInvoicedRequest($admin, $requester);

        $this->actingAs($admin)
            ->put(route('logistica.requisicoes.update', $request->id), $this->updatePayload($request, $product, 3))
            ->assertRedirect(route('logistica.index'));

        $invoice = Invoice::query()->findOrFail($request->fresh()->financial_invoice_id);
        $this->assertNull($invoice->centro_custo_id);
        $this->assertSame('logistics_request', $invoice->origem_tipo);
        $this->assertSame('pendente', $invoice->estado_pagamento);
        $this->assertEquals(0.0, (float) $invoice->valor_pago);
        $this->assertEquals((float) $invoice->valor_total, (float) $invoice->valor_em_aberto);
    }

    /**
     * @return array{0:User,1:LogisticsRequest,2:Product}
     */
    private function createInvoicedRequest(?User $admin = null, ?User $requester = null): array
    {
        $admin = $admin ?? User::factory()->create();
        $requester = $requester ?? User::factory()->athlete()->create();

        $product = Product::query()->create([
            'codigo' => 'ART-XFIN5-' . substr((string) $requester->id, 0, 6),
            'nome' => 'Produto XFIN5',
            'categoria' => 'Material',
            'preco' => 10,
            'stock' => 50,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.store'), [
                'requester_user_id' => $requester->id,
                'requester_name_snapshot' => $requester->nome_completo,
                'requester_area' => 'Natação',
                'requester_type' => 'Atleta',
                'status' => 'pending',
                'items' => [[
                    'article_id' => $product->id,
                    'quantity' => 4,
                    'unit_price' => 10,
                ]],
            ])
            ->assertRedirect(route('logistica.index'));

        $request = LogisticsRequest::query()->latest()->firstOrFail();

        $this->actingAs($admin)->post(route('logistica.requisicoes.approve', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.invoice', $request->id))->assertRedirect(route('logistica.index'));

        return [$admin, $request->fresh(), $product];
    }

    /**
     * @return array<string,mixed>
     */
    private function updatePayload(LogisticsRequest $request, Product $product, int $quantity): array
    {
        return [
            'requester_user_id' => $request->requester_user_id,
            'requester_name_snapshot' => $request->requester_name_snapshot,
            'requester_area' => $request->requester_area,
            'requester_type' => $request->requester_type,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 10,
            ]],
        ];
    }
}

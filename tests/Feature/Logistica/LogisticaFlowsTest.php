<?php

namespace Tests\Feature\Logistica;

use App\Models\EquipmentLoan;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use Illuminate\Http\UploadedFile;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPurchase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogisticaFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_requisition_flow_reserves_invoices_and_delivers_stock(): void
    {
        $admin = User::factory()->create();
        $requester = User::factory()->athlete()->create();

        $product = Product::create([
            'codigo' => 'ART-REQ-01',
            'nome' => 'Prancha Treino',
            'categoria' => 'Material',
            'preco' => 10.50,
            'stock' => 20,
            'stock_reservado' => 0,
            'stock_minimo' => 3,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.store'), [
                'requester_user_id' => $requester->id,
                'requester_name_snapshot' => $requester->nome_completo,
                'requester_area' => 'Natação',
                'requester_type' => 'athlete',
                'status' => 'pending',
                'items' => [
                    [
                        'article_id' => $product->id,
                        'quantity' => 4,
                        'unit_price' => 10.50,
                    ],
                ],
            ])
            ->assertRedirect(route('logistica.index'));

        $request = LogisticsRequest::query()->latest()->firstOrFail();

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.approve', $request->id))
            ->assertRedirect(route('logistica.index'));

        $product->refresh();
        $request->refresh();

        $this->assertSame('approved', $request->status);
        $this->assertSame(20, (int) $product->stock);
        $this->assertSame(4, (int) $product->stock_reservado);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.invoice', $request->id), [
                'tipo' => 'material',
            ])
            ->assertRedirect(route('logistica.index'));

        $request->refresh();
        $this->assertSame('invoiced', $request->status);
        $this->assertNotNull($request->financial_invoice_id);
        $product->refresh();
        $this->assertSame(20, (int) $product->stock);
        $this->assertSame(4, (int) $product->stock_reservado);
        $this->assertDatabaseHas('invoice_items', [
            'fatura_id' => $request->financial_invoice_id,
            'produto_id' => $product->id,
            'quantidade' => 4,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.deliver', $request->id))
            ->assertRedirect(route('logistica.index'));

        $product->refresh();
        $request->refresh();

        $this->assertSame('delivered', $request->status);
        $this->assertSame(16, (int) $product->stock);
        $this->assertSame(0, (int) $product->stock_reservado);

        $invoice = Invoice::find($request->financial_invoice_id);
        $this->assertNotNull($invoice);
        $this->assertSame($requester->id, $invoice->user_id);
        $this->assertSame('logistics_request', $invoice->origem_tipo);
    }

    public function test_supplier_purchase_flow_updates_stock_and_finance(): void
    {
        $admin = User::factory()->create();

        $supplier = Supplier::create([
            'nome' => 'Fornecedor Equipamentos SA',
            'nif' => '509999990',
            'email' => 'fornecedor@example.com',
            'telefone' => '912345678',
            'categoria' => 'Equipamentos',
            'ativo' => true,
        ]);

        $product = Product::create([
            'codigo' => 'ART-COMP-01',
            'nome' => 'Palas Técnicas',
            'categoria' => 'Equipamento',
            'preco' => 25.00,
            'stock' => 2,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'supplier_id' => $supplier->id,
            'ativo' => true,
        ]);

        $attachment = UploadedFile::fake()->create('fornecedor.pdf', 20, 'application/pdf');

        $this->actingAs($admin)
            ->post(route('logistica.fornecedores.compras.store'), [
                'supplier_id' => $supplier->id,
                'invoice_reference' => 'FAC-2026-009',
                'invoice_date' => now()->toDateString(),
                'document_type' => 'invoice',
                'attachment' => $attachment,
                'items' => [
                    [
                        'article_id' => $product->id,
                        'quantity' => 5,
                        'unit_cost' => 12.40,
                    ],
                ],
            ])
            ->assertRedirect(route('logistica.index'));

        $purchase = SupplierPurchase::query()->latest()->firstOrFail();
        $product->refresh();

        $this->assertSame(7, (int) $product->stock);
        $this->assertNotNull($purchase->financial_movement_id);
        $this->assertNull($purchase->financial_entry_id);

        $movement = Movement::find($purchase->financial_movement_id);

        $this->assertNotNull($movement);
        $this->assertSame('despesa', $movement->classificacao);
        $this->assertSame(62.0, (float) $movement->valor_total);
        $this->assertSame('supplier_purchase', $movement->origem_tipo);
        $this->assertSame('por_pagar', $movement->estado_pagamento);
        $this->assertDatabaseHas('movement_documents', [
            'movement_id' => $movement->id,
            'document_type' => 'invoice',
            'source_type' => 'logistics',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'entry',
            'reference_type' => 'supplier_purchase',
            'reference_id' => $purchase->id,
        ]);
        $this->assertDatabaseMissing('financial_entries', [
            'origem_tipo' => 'supplier_purchase',
            'origem_id' => $purchase->id,
        ]);
    }

    public function test_equipment_loan_flow_exits_and_returns_stock(): void
    {
        $admin = User::factory()->create();
        $borrower = User::factory()->create();

        $product = Product::create([
            'codigo' => 'ART-EMP-01',
            'nome' => 'Corda Elástica',
            'categoria' => 'Treino Seco',
            'preco' => 9.99,
            'stock' => 6,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.emprestimos.store'), [
                'borrower_user_id' => $borrower->id,
                'borrower_name_snapshot' => $borrower->nome_completo,
                'article_id' => $product->id,
                'quantity' => 2,
                'loan_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertRedirect(route('logistica.index'));

        $loan = EquipmentLoan::query()->latest()->firstOrFail();
        $product->refresh();

        $this->assertSame('active', $loan->status);
        $this->assertSame(4, (int) $product->stock);

        $this->actingAs($admin)
            ->post(route('logistica.emprestimos.return', $loan->id))
            ->assertRedirect(route('logistica.index'));

        $loan->refresh();
        $product->refresh();

        $this->assertSame('returned', $loan->status);
        $this->assertNotNull($loan->return_date);
        $this->assertSame(6, (int) $product->stock);

        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'reference_type' => 'equipment_loan',
            'reference_id' => $loan->id,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'return',
            'reference_type' => 'equipment_loan',
            'reference_id' => $loan->id,
        ]);
    }

    public function test_updating_equipment_loan_adjusts_stock_through_ledger(): void
    {
        $admin = User::factory()->create();
        $borrower = User::factory()->create();

        $product = Product::create([
            'codigo' => 'ART-EMP-UPD-01',
            'nome' => 'Cinto de treino',
            'categoria' => 'Treino Seco',
            'preco' => 9.99,
            'stock' => 6,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.emprestimos.store'), [
                'borrower_user_id' => $borrower->id,
                'borrower_name_snapshot' => $borrower->nome_completo,
                'article_id' => $product->id,
                'quantity' => 2,
                'loan_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertRedirect(route('logistica.index'));

        $loan = EquipmentLoan::query()->latest()->firstOrFail();

        $this->actingAs($admin)
            ->put(route('logistica.emprestimos.update', $loan), [
                'borrower_user_id' => $borrower->id,
                'borrower_name_snapshot' => $borrower->nome_completo,
                'article_id' => $product->id,
                'quantity' => 3,
                'loan_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertRedirect(route('logistica.index'));

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'exit',
            'quantity' => 1,
            'reference_type' => 'equipment_loan_update',
            'reference_id' => $loan->id,
        ]);
    }

    public function test_deleting_equipment_loan_returns_stock_through_ledger(): void
    {
        $admin = User::factory()->create();
        $borrower = User::factory()->create();

        $product = Product::create([
            'codigo' => 'ART-EMP-DEL-01',
            'nome' => 'Elástico',
            'categoria' => 'Treino Seco',
            'preco' => 9.99,
            'stock' => 6,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.emprestimos.store'), [
                'borrower_user_id' => $borrower->id,
                'borrower_name_snapshot' => $borrower->nome_completo,
                'article_id' => $product->id,
                'quantity' => 2,
                'loan_date' => now()->toDateString(),
                'due_date' => now()->addDays(7)->toDateString(),
            ])
            ->assertRedirect(route('logistica.index'));

        $loan = EquipmentLoan::query()->latest()->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('logistica.emprestimos.destroy', $loan))
            ->assertRedirect(route('logistica.index'));

        $this->assertDatabaseMissing('equipment_loans', ['id' => $loan->id]);
        $this->assertSame(6, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'return',
            'quantity' => 2,
            'reference_type' => 'equipment_loan_delete',
            'reference_id' => $loan->id,
        ]);
    }

    public function test_updating_invoiced_request_updates_invoice_and_stock_delta(): void
    {
        $admin = User::factory()->create();
        $requesterA = User::factory()->athlete()->create();
        $requesterB = User::factory()->athlete()->create();

        $product = Product::create([
            'codigo' => 'ART-EDIT-01',
            'nome' => 'Pala de Mão',
            'categoria' => 'Material',
            'preco' => 12.00,
            'stock' => 30,
            'stock_reservado' => 0,
            'stock_minimo' => 2,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.store'), [
                'requester_user_id' => $requesterA->id,
                'requester_name_snapshot' => $requesterA->nome_completo,
                'requester_area' => 'Natação',
                'requester_type' => 'Atleta',
                'status' => 'pending',
                'items' => [
                    [
                        'article_id' => $product->id,
                        'quantity' => 5,
                        'unit_price' => 12.00,
                    ],
                ],
            ])
            ->assertRedirect(route('logistica.index'));

        $request = LogisticsRequest::query()->latest()->firstOrFail();

        $this->actingAs($admin)->post(route('logistica.requisicoes.approve', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.invoice', $request->id))->assertRedirect(route('logistica.index'));

        $request->refresh();
        $this->assertSame('invoiced', $request->status);
        $this->assertNotNull($request->financial_invoice_id);

        $this->actingAs($admin)
            ->put(route('logistica.requisicoes.update', $request->id), [
                'requester_user_id' => $requesterB->id,
                'requester_name_snapshot' => $requesterB->nome_completo,
                'requester_area' => 'Competição',
                'requester_type' => 'Atleta',
                'items' => [
                    [
                        'article_id' => $product->id,
                        'quantity' => 3,
                        'unit_price' => 12.00,
                    ],
                ],
            ])
            ->assertRedirect(route('logistica.index'));

        $request->refresh();
        $product->refresh();
        $invoice = Invoice::findOrFail($request->financial_invoice_id);

        $this->assertSame(30, (int) $product->stock);
        $this->assertSame(3, (int) $product->stock_reservado);
        $this->assertSame($requesterB->id, $invoice->user_id);
        $this->assertEquals(36.00, (float) $invoice->valor_total);
        $this->assertSame('logistics_request', $invoice->origem_tipo);
        $this->assertEquals(0.00, (float) $invoice->valor_pago);
        $this->assertEquals(36.00, (float) $invoice->valor_em_aberto);
        $this->assertDatabaseHas('invoice_items', [
            'fatura_id' => $invoice->id,
            'produto_id' => $product->id,
            'quantidade' => 3,
        ]);
    }

    public function test_deleting_invoiced_request_deletes_invoice_and_restores_stock(): void
    {
        $admin = User::factory()->create();
        $requester = User::factory()->athlete()->create();

        $product = Product::create([
            'codigo' => 'ART-DEL-01',
            'nome' => 'Barbatanas',
            'categoria' => 'Material',
            'preco' => 18.00,
            'stock' => 14,
            'stock_reservado' => 0,
            'stock_minimo' => 2,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.store'), [
                'requester_user_id' => $requester->id,
                'requester_name_snapshot' => $requester->nome_completo,
                'requester_area' => 'Natação',
                'requester_type' => 'Atleta',
                'status' => 'pending',
                'items' => [
                    [
                        'article_id' => $product->id,
                        'quantity' => 4,
                        'unit_price' => 18.00,
                    ],
                ],
            ])
            ->assertRedirect(route('logistica.index'));

        $request = LogisticsRequest::query()->latest()->firstOrFail();

        $this->actingAs($admin)->post(route('logistica.requisicoes.approve', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.invoice', $request->id))->assertRedirect(route('logistica.index'));

        $request->refresh();
        $invoiceId = $request->financial_invoice_id;
        $this->assertNotNull($invoiceId);

        $this->actingAs($admin)
            ->delete(route('logistica.requisicoes.destroy', $request->id))
            ->assertRedirect(route('logistica.index'));

        $product->refresh();

        $this->assertDatabaseMissing('logistics_requests', ['id' => $request->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $invoiceId]);
        $this->assertSame(14, (int) $product->stock);
        $this->assertSame(0, (int) $product->stock_reservado);
    }

    public function test_updating_delivered_request_updates_invoice_and_adjusts_physical_stock(): void
    {
        $admin = User::factory()->create();
        $requesterA = User::factory()->athlete()->create();
        $requesterB = User::factory()->athlete()->create();

        $product = Product::create([
            'codigo' => 'ART-ED-DEL-01',
            'nome' => 'Pullbuoy',
            'categoria' => 'Material',
            'preco' => 15.00,
            'stock' => 20,
            'stock_reservado' => 0,
            'stock_minimo' => 2,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.store'), [
                'requester_user_id' => $requesterA->id,
                'requester_name_snapshot' => $requesterA->nome_completo,
                'requester_area' => 'Natação',
                'requester_type' => 'Atleta',
                'status' => 'pending',
                'items' => [[
                    'article_id' => $product->id,
                    'quantity' => 4,
                    'unit_price' => 15.00,
                ]],
            ])
            ->assertRedirect(route('logistica.index'));

        $request = LogisticsRequest::query()->latest()->firstOrFail();

        $this->actingAs($admin)->post(route('logistica.requisicoes.approve', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.invoice', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.deliver', $request->id))->assertRedirect(route('logistica.index'));

        $this->actingAs($admin)
            ->put(route('logistica.requisicoes.update', $request->id), [
                'requester_user_id' => $requesterB->id,
                'requester_name_snapshot' => $requesterB->nome_completo,
                'requester_area' => 'Competição',
                'requester_type' => 'Atleta',
                'items' => [[
                    'article_id' => $product->id,
                    'quantity' => 3,
                    'unit_price' => 15.00,
                ]],
            ])
            ->assertRedirect(route('logistica.index'));

        $request->refresh();
        $product->refresh();
        $invoice = Invoice::findOrFail($request->financial_invoice_id);

        $this->assertSame('delivered', $request->status);
        $this->assertSame(17, (int) $product->stock);
        $this->assertSame(0, (int) $product->stock_reservado);
        $this->assertSame($requesterB->id, $invoice->user_id);
        $this->assertEquals(45.00, (float) $invoice->valor_total);
    }

    public function test_deleting_delivered_request_deletes_invoice_and_restores_physical_stock(): void
    {
        $admin = User::factory()->create();
        $requester = User::factory()->athlete()->create();

        $product = Product::create([
            'codigo' => 'ART-DEL-ENT-01',
            'nome' => 'Prancha',
            'categoria' => 'Material',
            'preco' => 10.00,
            'stock' => 12,
            'stock_reservado' => 0,
            'stock_minimo' => 2,
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
                    'quantity' => 3,
                    'unit_price' => 10.00,
                ]],
            ])
            ->assertRedirect(route('logistica.index'));

        $request = LogisticsRequest::query()->latest()->firstOrFail();

        $this->actingAs($admin)->post(route('logistica.requisicoes.approve', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.invoice', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.deliver', $request->id))->assertRedirect(route('logistica.index'));

        $request->refresh();
        $invoiceId = $request->financial_invoice_id;

        $this->actingAs($admin)
            ->delete(route('logistica.requisicoes.destroy', $request->id))
            ->assertRedirect(route('logistica.index'));

        $product->refresh();

        $this->assertDatabaseMissing('logistics_requests', ['id' => $request->id]);
        $this->assertDatabaseMissing('invoices', ['id' => $invoiceId]);
        $this->assertSame(12, (int) $product->stock);
        $this->assertSame(0, (int) $product->stock_reservado);
    }
}

<?php

namespace Tests\Feature\Logistica;

use App\Models\FinancialEntry;
use App\Models\FiscalDocumentRequest;
use App\Models\Movement;
use App\Models\MovementItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\Financeiro\FinancialSettlementService;
use App\Services\Logistica\DeleteSupplierPurchaseAction;
use App\Services\Logistica\RegisterSupplierPurchaseAction;
use App\Services\Logistica\UpdateSupplierPurchaseAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierPurchaseFinancialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_builds_canonical_supplier_purchase_without_parallel_financial_entry(): void
    {
        [$purchase, $product] = $this->createSupplierPurchase();

        $movement = Movement::query()->findOrFail($purchase->financial_movement_id);

        $this->assertNotNull($purchase->financial_movement_id);
        $this->assertNull($purchase->financial_entry_id);
        $this->assertSame('despesa', $movement->classificacao);
        $this->assertSame(50.0, (float) $movement->valor_total);
        $this->assertSame('supplier_purchase', $movement->origem_tipo);
        $this->assertSame((string) $purchase->id, (string) $movement->origem_id);
        $this->assertSame(15, (int) $product->fresh()->stock);

        $this->assertDatabaseHas('movement_items', [
            'movimento_id' => $movement->id,
            'produto_id' => $product->id,
            'quantidade' => 5,
            'total_linha' => 50,
        ]);

        $this->assertSame(0, FinancialEntry::query()
            ->whereIn('origem_tipo', ['stock', 'supplier_purchase'])
            ->where('origem_id', $purchase->id)
            ->count());
    }

    public function test_settlement_creates_canonical_movement_financial_entry(): void
    {
        [$purchase] = $this->createSupplierPurchase();
        $movement = Movement::query()->findOrFail($purchase->financial_movement_id);

        $result = app(FinancialSettlementService::class)->settleMovement($movement, [
            'method' => 'dinheiro',
            'payment_date' => now()->toDateString(),
        ]);

        /** @var FinancialEntry $entry */
        $entry = $result['financial_entry'];

        $this->assertSame('movement', $entry->origem_tipo);
        $this->assertSame((string) $movement->id, (string) $entry->origem_id);
        $this->assertSame('despesa', $entry->tipo);
        $this->assertSame(50.0, (float) $entry->valor);
        $this->assertSame(1, PaymentAllocation::query()->where('financial_entry_id', $entry->id)->count());
    }

    public function test_update_pre_settlement_reuses_same_movement_and_updates_values_without_creating_entry(): void
    {
        [$purchase, $product, $supplier, $actor] = $this->createSupplierPurchase();

        $originalMovementId = $purchase->financial_movement_id;

        app(UpdateSupplierPurchaseAction::class)->execute($purchase, [
            'supplier_id' => $supplier->id,
            'invoice_reference' => 'SUP-UPD-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'centro_custo_id' => null,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => 4,
                'unit_cost' => 12.50,
            ]],
        ], $actor);

        $purchase = $purchase->fresh();
        $movement = Movement::query()->findOrFail($purchase->financial_movement_id);

        $this->assertSame((string) $originalMovementId, (string) $purchase->financial_movement_id);
        $this->assertSame(50.0, (float) $movement->valor_total);
        $this->assertSame('supplier_purchase', $movement->origem_tipo);
        $this->assertSame('despesa', $movement->classificacao);
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'supplier_purchase')->where('origem_id', $purchase->id)->count());
        $this->assertSame(1, MovementItem::query()->where('movimento_id', $movement->id)->count());
        $this->assertSame(14, (int) $product->fresh()->stock);

        $this->assertSame(0, FinancialEntry::query()
            ->whereIn('origem_tipo', ['stock', 'supplier_purchase'])
            ->where('origem_id', $purchase->id)
            ->count());
        $this->assertSame(0, FinancialEntry::query()->where('origem_tipo', 'movement')->where('origem_id', $movement->id)->count());
    }

    public function test_delete_pre_settlement_removes_purchase_and_financial_movement_and_reverts_stock(): void
    {
        [$purchase, $product] = $this->createSupplierPurchase();
        $movementId = $purchase->financial_movement_id;

        app(DeleteSupplierPurchaseAction::class)->execute($purchase);

        $this->assertDatabaseMissing('supplier_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('movements', ['id' => $movementId]);
        $this->assertDatabaseMissing('movement_items', ['movimento_id' => $movementId]);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => 'supplier_purchase',
            'reference_id' => $purchase->id,
        ]);
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public function test_update_is_blocked_when_movement_is_partial(): void
    {
        [$purchase, $product, $supplier, $actor] = $this->createSupplierPurchase();

        Movement::query()->whereKey($purchase->financial_movement_id)->update([
            'estado_pagamento' => 'parcial',
        ]);

        $this->expectException(ValidationException::class);

        app(UpdateSupplierPurchaseAction::class)->execute($purchase->fresh(), $this->buildUpdatePayload($supplier, $product), $actor);
    }

    public function test_update_is_blocked_when_movement_is_paid(): void
    {
        [$purchase, $product, $supplier, $actor] = $this->createSupplierPurchase();

        Movement::query()->whereKey($purchase->financial_movement_id)->update([
            'estado_pagamento' => 'pago',
        ]);

        $this->expectException(ValidationException::class);

        app(UpdateSupplierPurchaseAction::class)->execute($purchase->fresh(), $this->buildUpdatePayload($supplier, $product), $actor);
    }

    public function test_update_is_blocked_when_confirmed_payment_allocation_exists(): void
    {
        [$purchase, $product, $supplier, $actor] = $this->createSupplierPurchase();
        $movement = Movement::query()->findOrFail($purchase->financial_movement_id);

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Entrada canónica de teste',
            'valor' => 50,
            'valor_pago' => 0,
            'valor_em_aberto' => 50,
            'estado' => 'pendente',
            'origem_tipo' => 'movement',
            'origem_modulo' => 'financeiro',
            'origem_id' => $movement->id,
        ]);

        $payment = Payment::query()->create([
            'amount' => 50,
            'allocated_amount' => 50,
            'unallocated_amount' => 0,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'financial_entry_id' => $entry->id,
            'amount' => 50,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(UpdateSupplierPurchaseAction::class)->execute($purchase->fresh(), $this->buildUpdatePayload($supplier, $product), $actor);
    }

    public function test_update_is_blocked_when_movement_is_reconciled(): void
    {
        [$purchase, $product, $supplier, $actor] = $this->createSupplierPurchase();

        Movement::query()->whereKey($purchase->financial_movement_id)->update([
            'estado_conciliacao' => 'conciliado',
        ]);

        $this->expectException(ValidationException::class);

        app(UpdateSupplierPurchaseAction::class)->execute($purchase->fresh(), $this->buildUpdatePayload($supplier, $product), $actor);
    }

    public function test_update_is_blocked_when_issued_fiscal_document_exists(): void
    {
        [$purchase, $product, $supplier, $actor] = $this->createSupplierPurchase();
        $movement = Movement::query()->findOrFail($purchase->financial_movement_id);

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Entrada canónica para fiscal',
            'valor' => 50,
            'valor_pago' => 50,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'origem_tipo' => 'movement',
            'origem_modulo' => 'financeiro',
            'origem_id' => $movement->id,
        ]);

        FiscalDocumentRequest::query()->create([
            'financial_entry_id' => $entry->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 50,
        ]);

        $this->expectException(ValidationException::class);

        app(UpdateSupplierPurchaseAction::class)->execute($purchase->fresh(), $this->buildUpdatePayload($supplier, $product), $actor);
    }

    public function test_delete_is_blocked_when_movement_is_partial(): void
    {
        [$purchase] = $this->createSupplierPurchase();

        Movement::query()->whereKey($purchase->financial_movement_id)->update([
            'estado_pagamento' => 'parcial',
        ]);

        $this->expectException(ValidationException::class);

        app(DeleteSupplierPurchaseAction::class)->execute($purchase->fresh());
    }

    public function test_delete_is_blocked_when_movement_is_paid(): void
    {
        [$purchase] = $this->createSupplierPurchase();

        Movement::query()->whereKey($purchase->financial_movement_id)->update([
            'estado_pagamento' => 'pago',
        ]);

        $this->expectException(ValidationException::class);

        app(DeleteSupplierPurchaseAction::class)->execute($purchase->fresh());
    }

    public function test_delete_is_blocked_when_movement_is_reconciled(): void
    {
        [$purchase] = $this->createSupplierPurchase();

        Movement::query()->whereKey($purchase->financial_movement_id)->update([
            'estado_conciliacao' => 'conciliado',
        ]);

        $this->expectException(ValidationException::class);

        app(DeleteSupplierPurchaseAction::class)->execute($purchase->fresh());
    }

    public function test_delete_is_blocked_when_fiscal_document_exists(): void
    {
        [$purchase] = $this->createSupplierPurchase();
        $movement = Movement::query()->findOrFail($purchase->financial_movement_id);

        $entry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Fornecedor',
            'descricao' => 'Entrada canónica para fiscal delete',
            'valor' => 50,
            'valor_pago' => 50,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'origem_tipo' => 'movement',
            'origem_modulo' => 'financeiro',
            'origem_id' => $movement->id,
        ]);

        FiscalDocumentRequest::query()->create([
            'financial_entry_id' => $entry->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 50,
        ]);

        $this->expectException(ValidationException::class);

        app(DeleteSupplierPurchaseAction::class)->execute($purchase->fresh());
    }

    public function test_legacy_purchase_with_parallel_financial_entry_is_not_silently_destroyed(): void
    {
        [$purchase] = $this->createSupplierPurchase();

        $legacyEntry = FinancialEntry::query()->create([
            'data' => now()->toDateString(),
            'tipo' => 'despesa',
            'categoria' => 'Compras fornecedor',
            'descricao' => 'Entrada legacy paralela',
            'valor' => 50,
            'valor_pago' => 0,
            'valor_em_aberto' => 50,
            'estado' => 'pendente',
            'origem_tipo' => 'stock',
            'origem_id' => $purchase->id,
        ]);

        $purchase->update([
            'financial_entry_id' => $legacyEntry->id,
        ]);

        try {
            app(DeleteSupplierPurchaseAction::class)->execute($purchase->fresh());
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException) {
            // expected guard behavior
        }

        $this->assertDatabaseHas('supplier_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('financial_entries', ['id' => $legacyEntry->id]);
    }

    /**
     * @return array{0:SupplierPurchase,1:Product,2:Supplier,3:User}
     */
    private function createSupplierPurchase(): array
    {
        $actor = User::factory()->create();

        $supplier = Supplier::query()->create([
            'nome' => 'Fornecedor Lifecycle',
            'nif' => '509999990',
            'email' => 'supplier-lifecycle@example.test',
            'telefone' => '912345678',
            'categoria' => 'Equipamento',
            'ativo' => true,
        ]);

        $product = Product::query()->create([
            'codigo' => 'SP-LIFE-001',
            'nome' => 'Material Lifecycle',
            'categoria' => 'Equipamento',
            'preco' => 20,
            'stock' => 10,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'supplier_id' => $supplier->id,
            'ativo' => true,
        ]);

        $purchase = app(RegisterSupplierPurchaseAction::class)->execute([
            'supplier_id' => $supplier->id,
            'invoice_reference' => 'SUP-LIFE-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'centro_custo_id' => null,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => 5,
                'unit_cost' => 10,
            ]],
        ], $actor);

        return [$purchase->fresh(), $product->fresh(), $supplier, $actor];
    }

    private function buildUpdatePayload(Supplier $supplier, Product $product): array
    {
        return [
            'supplier_id' => $supplier->id,
            'invoice_reference' => 'SUP-LIFE-UPD-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'centro_custo_id' => null,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => 3,
                'unit_cost' => 11,
            ]],
        ];
    }
}

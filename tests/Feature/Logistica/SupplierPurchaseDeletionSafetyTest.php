<?php

namespace Tests\Feature\Logistica;

use App\Models\FinancialEntry;
use App\Models\Movement;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPurchase;
use App\Models\SupplierPurchaseDeletionAudit;
use App\Models\User;
use App\Services\Logistica\DeleteSupplierPurchaseAction;
use App\Services\Logistica\RegisterSupplierPurchaseAction;
use App\Services\Logistica\SupplierPurchaseFinancialGuardService;
use App\Services\Logistica\UpdateSupplierPurchaseAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SupplierPurchaseDeletionSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_unsettled_purchase_can_be_deleted_and_leaves_audit_tombstone(): void
    {
        [$purchase, $product, , $actor] = $this->createPurchase();
        $movementId = $purchase->financial_movement_id;

        app(DeleteSupplierPurchaseAction::class)->execute($purchase, $actor, 'Correção de compra introduzida por engano.');

        $this->assertDatabaseMissing('supplier_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('movements', ['id' => $movementId]);
        $this->assertSame(10, (int) $product->fresh()->stock);

        $audit = SupplierPurchaseDeletionAudit::query()->where('supplier_purchase_id', $purchase->id)->firstOrFail();
        $this->assertSame($actor->id, $audit->deleted_by);
        $this->assertSame('hard_delete_pre_settlement', $audit->deletion_mode);
        $this->assertSame('Correção de compra introduzida por engano.', $audit->reason);
        $this->assertSame($purchase->id, data_get($audit->payload, 'purchase.id'));
        $this->assertSame($movementId, data_get($audit->payload, 'financial_movement.id'));
        $this->assertCount(1, data_get($audit->payload, 'items', []));
    }

    public function test_deletion_is_blocked_when_current_stock_provenance_is_missing(): void
    {
        [$purchase, $product, , $actor] = $this->createPurchase();

        StockMovement::query()
            ->where('reference_type', 'supplier_purchase')
            ->where('reference_id', $purchase->id)
            ->delete();

        try {
            app(DeleteSupplierPurchaseAction::class)->execute($purchase->fresh(), $actor);
            $this->fail('Expected stock provenance validation to block deletion.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('histórico de stock', (string) data_get($exception->errors(), 'purchase.0'));
        }

        $this->assertDatabaseHas('supplier_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('movements', ['id' => $purchase->financial_movement_id]);
        $this->assertDatabaseMissing('supplier_purchase_deletion_audits', ['supplier_purchase_id' => $purchase->id]);
        $this->assertSame(15, (int) $product->fresh()->stock);
    }

    public function test_purchase_can_still_be_deleted_after_pre_settlement_update_using_current_stock_provenance(): void
    {
        [$purchase, $product, $supplier, $actor] = $this->createPurchase();

        app(UpdateSupplierPurchaseAction::class)->execute($purchase, [
            'supplier_id' => $supplier->id,
            'invoice_reference' => 'SUP-DELETE-UPD-001',
            'invoice_date' => '2026-09-08',
            'due_date' => '2026-09-15',
            'centro_custo_id' => null,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => 3,
                'unit_cost' => 11,
            ]],
        ], $actor);

        $purchase = $purchase->fresh('items');
        $this->assertSame(13, (int) $product->fresh()->stock);

        app(DeleteSupplierPurchaseAction::class)->execute($purchase, $actor);

        $this->assertSame(10, (int) $product->fresh()->stock);
        $this->assertDatabaseMissing('supplier_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseHas('supplier_purchase_deletion_audits', [
            'supplier_purchase_id' => $purchase->id,
            'deletion_mode' => 'hard_delete_pre_settlement',
        ]);
    }

    public function test_financial_guard_detects_legacy_entry_even_when_purchase_movement_reference_is_missing(): void
    {
        [$purchase] = $this->createPurchase();

        $purchase->update(['financial_movement_id' => null]);
        FinancialEntry::query()->create([
            'data' => '2026-09-08',
            'tipo' => 'despesa',
            'categoria' => 'Compras fornecedor legacy',
            'descricao' => 'Entrada legacy associada diretamente à compra',
            'valor' => 50,
            'valor_pago' => 0,
            'valor_em_aberto' => 50,
            'estado' => 'pendente',
            'origem_tipo' => 'supplier_purchase',
            'origem_modulo' => 'logistica',
            'origem_id' => $purchase->id,
        ]);

        $reasons = app(SupplierPurchaseFinancialGuardService::class)->blockingReasons($purchase->fresh());

        $this->assertContains('legacy_source_keyed_financial_entry', $reasons);
        $this->assertContains('movement_reference_missing_or_invalid', $reasons);
        $this->assertContains('unlinked_supplier_purchase_movement_exists', $reasons);
    }

    /**
     * @return array{0:SupplierPurchase,1:Product,2:Supplier,3:User}
     */
    private function createPurchase(): array
    {
        $actor = User::factory()->admin()->create();
        $supplier = Supplier::query()->create([
            'nome' => 'Fornecedor Delete Safety',
            'nif' => '509999992',
            'email' => 'delete-safety@example.test',
            'telefone' => '912345670',
            'categoria' => 'Equipamento',
            'ativo' => true,
        ]);
        $product = Product::query()->create([
            'codigo' => 'SP-DELETE-001',
            'nome' => 'Material Delete Safety',
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
            'invoice_reference' => 'SUP-DELETE-001',
            'invoice_date' => '2026-09-08',
            'due_date' => '2026-09-15',
            'centro_custo_id' => null,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => 5,
                'unit_cost' => 10,
            ]],
        ], $actor);

        return [$purchase->fresh('items'), $product->fresh(), $supplier, $actor];
    }
}

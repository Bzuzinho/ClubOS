<?php

namespace Tests\Feature\Logistica;

use App\Models\EquipmentLoan;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Logistica\CreateEquipmentLoanAction;
use App\Services\Logistica\DeleteEquipmentLoanAction;
use App\Services\Logistica\UpdateEquipmentLoanAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentLoanStockLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_equipment_loan_adjusts_stock_through_ledger_and_is_idempotent_for_same_execution(): void
    {
        $actor = User::factory()->create();
        $product = $this->product(['stock' => 6]);
        $loan = app(CreateEquipmentLoanAction::class)->execute($this->loanPayload($product, 2), $actor);

        app(UpdateEquipmentLoanAction::class)->execute($loan, $this->loanPayload($product, 3));
        app(UpdateEquipmentLoanAction::class)->execute($loan, $this->loanPayload($product, 3));

        $this->assertSame(3, (int) $product->fresh()->stock);
        $this->assertSame(1, StockMovement::query()
            ->where('article_id', $product->id)
            ->where('movement_type', 'exit')
            ->where('quantity', 1)
            ->where('reference_type', 'equipment_loan_update')
            ->where('reference_id', $loan->id)
            ->count());
    }

    public function test_delete_equipment_loan_returns_stock_through_ledger(): void
    {
        $actor = User::factory()->create();
        $product = $this->product(['stock' => 6]);
        $loan = app(CreateEquipmentLoanAction::class)->execute($this->loanPayload($product, 2), $actor);

        app(DeleteEquipmentLoanAction::class)->execute($loan);

        $this->assertDatabaseMissing('equipment_loans', ['id' => $loan->id]);
        $this->assertSame(6, (int) $product->fresh()->stock);
        $this->assertSame(1, StockMovement::query()
            ->where('article_id', $product->id)
            ->where('movement_type', 'return')
            ->where('quantity', 2)
            ->where('reference_type', 'equipment_loan_delete')
            ->where('reference_id', $loan->id)
            ->count());
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function product(array $overrides = []): Product
    {
        return Product::factory()->create(array_merge([
            'nome' => 'Material Emprestimo',
            'categoria' => 'Material',
            'area_armazenamento' => 'Armazem',
            'allow_sale' => true,
            'allow_request' => true,
            'allow_loan' => true,
            'track_stock' => true,
            'ativo' => true,
            'stock' => 0,
            'stock_reservado' => 0,
        ], $overrides));
    }

    /**
     * @return array<string,mixed>
     */
    private function loanPayload(Product $product, int $quantity): array
    {
        return [
            'borrower_user_id' => null,
            'borrower_name_snapshot' => 'Atleta teste',
            'article_id' => $product->id,
            'quantity' => $quantity,
            'loan_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
        ];
    }
}

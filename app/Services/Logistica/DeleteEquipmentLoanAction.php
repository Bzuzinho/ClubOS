<?php

namespace App\Services\Logistica;

use App\Models\EquipmentLoan;
use App\Models\Product;
use App\Services\Inventario\StockLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteEquipmentLoanAction
{
    public function __construct(
        private readonly StockLedgerService $stockLedger,
    ) {
    }

    public function execute(EquipmentLoan $loan): void
    {
        if (!in_array($loan->status, ['active', 'overdue'])) {
            throw ValidationException::withMessages([
                'status' => 'Só é possível apagar empréstimos ativos ou em atraso.',
            ]);
        }

        DB::transaction(function () use ($loan) {
            if ($loan->article_id) {
                $product = Product::query()->lockForUpdate()->find($loan->article_id);
                if ($product) {
                    $this->stockLedger->registerReturn($product, (int) $loan->quantity, [
                        'source_type' => 'equipment_loan_delete',
                        'source_id' => $loan->id,
                        'notes' => 'Reposição de stock por eliminação de empréstimo ativo',
                        'created_by' => $loan->created_by,
                        'occurred_at' => now(),
                        'idempotency_key' => 'equipment-loan-delete-'.$loan->id,
                    ]);
                }
            }

            $loan->delete();
        });
    }
}

<?php

namespace App\Services\Logistica;

use App\Models\EquipmentLoan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnEquipmentLoanAction
{
    public function __construct(
        private RegisterStockMovementAction $registerStockMovementAction
    ) {
    }

    public function execute(EquipmentLoan $loan, ?User $actor = null): EquipmentLoan
    {
        return DB::transaction(function () use ($loan, $actor) {
            $loan = EquipmentLoan::query()
                ->whereKey($loan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($loan->status === 'returned') {
                return $loan->fresh(['borrower', 'article']);
            }

            if (!in_array($loan->status, ['active', 'overdue'], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Só empréstimos ativos/atrasados podem ser devolvidos.',
                ]);
            }

            if (empty($loan->article_id)) {
                throw ValidationException::withMessages([
                    'article_id' => 'Este empréstimo não possui artigo associado.',
                ]);
            }

            $this->registerStockMovementAction->execute([
                'article_id' => $loan->article_id,
                'movement_type' => 'return',
                'quantity' => (int) $loan->quantity,
                'reference_type' => 'equipment_loan',
                'reference_id' => $loan->id,
                'notes' => 'Devolução de material emprestado',
                'idempotency_key' => 'equipment-loan-return-'.$loan->id,
            ], $actor);

            $loan->update([
                'return_date' => now()->toDateString(),
                'status' => 'returned',
            ]);

            return $loan->fresh(['borrower', 'article']);
        });
    }
}

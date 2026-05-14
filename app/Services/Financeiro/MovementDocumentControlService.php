<?php

namespace App\Services\Financeiro;

use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementDocumentRequirement;

class MovementDocumentControlService
{
    public function evaluate(Movement $movement): array
    {
        $movement->loadMissing('documents');

        $documents = $movement->documents
            ->filter(fn (MovementDocument $document): bool => !in_array($document->status, ['rejected', 'duplicate'], true))
            ->values();

        $requirement = $this->resolveRequirement($movement);
        $normalizedPaymentState = $this->normalizePaymentState($movement->estado_pagamento);
        $normalizedReconciliationState = $this->normalizeReconciliationState($movement->estado_conciliacao);

        $hasInvoice = $documents->contains(fn (MovementDocument $document): bool => in_array($document->document_type, ['invoice', 'invoice_receipt'], true));
        $hasReceipt = $documents->contains(fn (MovementDocument $document): bool => in_array($document->document_type, ['receipt', 'invoice_receipt'], true));
        $hasPaymentProof = $documents->contains(fn (MovementDocument $document): bool => in_array($document->document_type, ['payment_proof', 'bank_statement_line'], true));
        $hasPendingValidation = $documents->contains(fn (MovementDocument $document): bool => $document->status === 'pending_validation');

        $missing = [];
        if ($requirement['requires_invoice'] && ($normalizedPaymentState === 'pago' || $normalizedReconciliationState === 'conciliado') && !$hasInvoice) {
            $missing[] = 'invoice';
        }

        if ($requirement['requires_receipt'] && $normalizedPaymentState === 'pago' && !$hasReceipt) {
            $missing[] = 'receipt';
        }

        if ($requirement['requires_payment_proof'] && ($normalizedPaymentState === 'pago' || $normalizedReconciliationState === 'conciliado') && !$hasPaymentProof) {
            $missing[] = 'payment_proof';
        }

        $hasAmountMismatch = $this->hasAmountMismatch($movement, $documents);

        [$estadoDocumental, $documentControlStatus] = match (true) {
            $hasAmountMismatch => ['inconsistente', 'inconsistent'],
            in_array('invoice', $missing, true) => ['falta_fatura', 'pending_invoice'],
            in_array('receipt', $missing, true) => ['falta_recibo', 'pending_receipt'],
            in_array('payment_proof', $missing, true) => ['falta_comprovativo_pagamento', 'pending_payment_proof'],
            $hasPendingValidation && $documents->isNotEmpty() => ['pendente_validacao', 'pending_documents'],
            $documents->isEmpty() => ['sem_documentos', $requirement['requires_invoice'] || $requirement['requires_receipt'] || $requirement['requires_payment_proof'] ? 'pending_documents' : 'not_required'],
            default => ['completo', 'complete'],
        };

        return [
            'estado_documental' => $estadoDocumental,
            'document_control_status' => $documentControlStatus,
            'missing_documents' => $missing,
            'has_required_documents' => $missing === [] && !$hasAmountMismatch,
            'requirement' => $requirement,
            'has_amount_mismatch' => $hasAmountMismatch,
        ];
    }

    public function refresh(Movement $movement): void
    {
        $evaluation = $this->evaluate($movement);

        $movement->forceFill([
            'estado_documental' => $evaluation['estado_documental'],
            'document_control_status' => $evaluation['document_control_status'],
        ])->saveQuietly();
    }

    public function missingDocuments(Movement $movement): array
    {
        return $this->evaluate($movement)['missing_documents'];
    }

    public function hasRequiredDocuments(Movement $movement): bool
    {
        return $this->evaluate($movement)['has_required_documents'];
    }

    public function attachDocumentToMovement(MovementDocument $document, Movement $movement): void
    {
        $document->movement()->associate($movement);

        if (!$document->supplier_id && $movement->supplier_id) {
            $document->supplier_id = $movement->supplier_id;
        }

        $document->save();
        $this->refresh($movement->fresh());
    }

    private function resolveRequirement(Movement $movement): array
    {
        $rule = MovementDocumentRequirement::query()
            ->where('active', true)
            ->where(function ($query) use ($movement) {
                $query->whereNull('movement_classification')
                    ->orWhere('movement_classification', $movement->classificacao);
            })
            ->where(function ($query) use ($movement) {
                $query->whereNull('movement_type')
                    ->orWhere('movement_type', $movement->tipo);
            })
            ->where(function ($query) use ($movement) {
                $query->whereNull('category')
                    ->orWhere('category', $movement->categoria);
            })
            ->where(function ($query) use ($movement) {
                $query->whereNull('supplier_id')
                    ->orWhere('supplier_id', $movement->supplier_id);
            })
            ->orderByRaw('case when supplier_id is null then 1 else 0 end')
            ->orderByRaw('case when category is null then 1 else 0 end')
            ->orderByRaw('case when movement_type is null then 1 else 0 end')
            ->first();

        return [
            'requires_invoice' => $rule?->requires_invoice ?? true,
            'requires_receipt' => $rule?->requires_receipt ?? false,
            'requires_payment_proof' => $rule?->requires_payment_proof ?? true,
            'requires_bank_match' => $rule?->requires_bank_match ?? true,
        ];
    }

    private function hasAmountMismatch(Movement $movement, $documents): bool
    {
        $mainDocuments = $documents
            ->filter(fn (MovementDocument $document): bool => in_array($document->document_type, ['invoice', 'invoice_receipt', 'receipt'], true))
            ->filter(fn (MovementDocument $document): bool => $document->amount !== null);

        if ($mainDocuments->isEmpty()) {
            return false;
        }

        $movementAmount = round(abs((float) $movement->valor_total), 2);

        return $mainDocuments->contains(function (MovementDocument $document) use ($movementAmount): bool {
            return abs(round((float) $document->amount, 2) - $movementAmount) > 0.009;
        });
    }

    private function normalizePaymentState(?string $state): string
    {
        return match ($state) {
            'pago' => 'pago',
            'parcial', 'pago_parcial' => 'pago_parcial',
            'cancelado' => 'cancelado',
            default => 'por_pagar',
        };
    }

    private function normalizeReconciliationState(?string $state): string
    {
        return match ($state) {
            'conciliado' => 'conciliado',
            'sugerido' => 'sugerido',
            'divergente' => 'divergente',
            default => 'nao_conciliado',
        };
    }
}
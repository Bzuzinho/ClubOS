<?php

namespace App\Services\Financeiro;

use App\Models\BankStatement;
use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ManualExpenseService
{
    public function __construct(
        private readonly FinancialSettlementService $financialSettlementService,
        private readonly MovementDocumentControlService $movementDocumentControlService,
    ) {
    }

    public function createSimpleExpense(array $data, ?User $actor = null): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            $data = $this->prepareData($data);

            $movement = Movement::query()->create([
                'user_id' => null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'nome_manual' => $data['nome_manual'] ?? null,
                'nif_manual' => $data['nif_manual'] ?? null,
                'morada_manual' => $data['morada_manual'] ?? null,
                'classificacao' => 'despesa',
                'categoria' => $data['categoria'] ?? null,
                'data_emissao' => $data['data_emissao'],
                'data_vencimento' => $data['data_vencimento'],
                'valor_total' => -abs((float) $data['valor_total']),
                'estado_pagamento' => $data['estado_pagamento'],
                'estado_conciliacao' => $data['estado_conciliacao'] ?? 'nao_conciliado',
                'estado_documental' => 'sem_documentos',
                'document_control_status' => null,
                'numero_recibo' => $data['numero_recibo'] ?? null,
                'referencia_pagamento' => $data['document_number'] ?? ($data['referencia_pagamento'] ?? null),
                'metodo_pagamento' => $data['metodo_pagamento'] ?? null,
                'documento_original' => $data['stored_path'] ?? null,
                'centro_custo_id' => $data['centro_custo_id'],
                'tipo' => $data['tipo'] ?? 'servico',
                'origem_tipo' => $data['origem_tipo'] ?? 'manual',
                'origem_id' => $data['origem_id'] ?? null,
                'observacoes' => $data['observacoes'] ?? null,
            ]);

            $createdItems = [];
            foreach ($data['items'] as $item) {
                $createdItems[] = MovementItem::query()->create([
                    'movimento_id' => $movement->id,
                    'descricao' => $item['descricao'],
                    'quantidade' => (int) $item['quantidade'],
                    'valor_unitario' => (float) $item['valor_unitario'],
                    'imposto_percentual' => (float) ($item['imposto_percentual'] ?? 0),
                    'total_linha' => (float) $item['total_linha'],
                    'produto_id' => $item['produto_id'] ?? null,
                    'centro_custo_id' => $item['centro_custo_id'] ?? $data['centro_custo_id'],
                    'fatura_id' => $item['fatura_id'] ?? null,
                ]);
            }

            $financialEntry = $this->financialSettlementService->findOrCreateFinancialEntryForMovement($movement, [
                'categoria' => $data['categoria'] ?? 'Despesa manual',
                'description' => $this->resolveFinancialDescription($movement, $data),
                'reference' => $data['document_number'] ?? ($data['referencia_pagamento'] ?? null),
                'method' => $data['metodo_pagamento'] ?? null,
            ]);

            $this->syncFinancialEntryState($movement, $financialEntry, $data, $actor);

            $document = $this->createMovementDocument($movement, $data, $actor);
            $this->movementDocumentControlService->refresh($movement->fresh());

            return [
                'movement' => $movement->fresh(['items', 'documents']),
                'items' => $createdItems,
                'financial_entry' => $financialEntry->fresh(),
                'document' => $document?->fresh(),
            ];
        });
    }

    public function createExpenseFromBankStatement(BankStatement $bankStatement, array $data, ?User $actor = null): array
    {
        return DB::transaction(function () use ($bankStatement, $data, $actor): array {
            $data = $this->prepareData(array_merge($data, [
                'data_emissao' => $data['data_emissao'] ?? optional($bankStatement->data_movimento)?->toDateString() ?? now()->toDateString(),
                'data_vencimento' => $data['data_vencimento'] ?? optional($bankStatement->data_movimento)?->toDateString() ?? now()->toDateString(),
                'valor_total' => $data['valor_total'] ?? abs((float) $bankStatement->valor),
                'estado_pagamento' => 'por_pagar',
                'estado_conciliacao' => 'conciliado',
                'origem_tipo' => 'bank_statement',
                'origem_id' => $bankStatement->id,
                'tipo' => $data['tipo'] ?? 'servico',
                'metodo_pagamento' => $data['metodo_pagamento'] ?? 'transferencia',
            ]));

            $data['document_type'] = $data['document_type'] ?? 'bank_statement_line';
            $data['document_number'] = $data['document_number'] ?? $bankStatement->referencia;
            $data['observacoes'] = $data['observacoes'] ?? ($data['descricao'] ?? $bankStatement->descricao);
            $data['source_type'] = 'bank_import';
            $data['source_id'] = $bankStatement->id;
            $data['document_status'] = 'valid';

            $result = $this->createSimpleExpense($data, $actor);
            $movement = $result['movement'];

            $settlement = $this->financialSettlementService->settleMovement($movement, [
                'payment_date' => $data['paid_at'] ?? optional($bankStatement->data_movimento)?->toDateString() ?? now()->toDateString(),
                'method' => $data['metodo_pagamento'] ?? 'transferencia',
                'reference' => $bankStatement->referencia,
                'description' => $data['descricao'] ?? $bankStatement->descricao,
                'bank_statement_id' => $bankStatement->id,
                'created_by' => $actor?->id,
                'source' => 'bank_statement',
                'notes' => 'Despesa criada a partir de pagamento bancario.',
                'map_metadata' => [
                    'created_expense_from_bank_statement' => true,
                ],
            ]);

            $bankDocument = MovementDocument::query()->firstOrCreate([
                'movement_id' => $movement->id,
                'document_type' => 'bank_statement_line',
                'source_type' => 'bank_import',
                'source_id' => $bankStatement->id,
            ], [
                'supplier_id' => $movement->supplier_id,
                'document_number' => $bankStatement->referencia,
                'issue_date' => $bankStatement->data_movimento,
                'amount' => abs((float) $bankStatement->valor),
                'status' => 'valid',
                'notes' => $data['descricao'] ?? $bankStatement->descricao,
            ]);

            $this->movementDocumentControlService->refresh($movement->fresh());

            return array_merge($result, [
                'movement' => $movement->fresh(['items', 'documents']),
                'financial_entry' => $settlement['financial_entry']->fresh(),
                'payment' => $settlement['payment'],
                'bank_document' => $bankDocument->fresh(),
                'bank_statement' => $bankStatement->fresh(),
            ]);
        });
    }

    public function prepareData(array $data): array
    {
        if (!empty($data['supplier_id'])) {
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $data['nome_manual'] = $data['nome_manual'] ?? $supplier->nome;
            $data['nif_manual'] = $data['nif_manual'] ?? $supplier->nif;
            $data['morada_manual'] = $data['morada_manual'] ?? $supplier->morada;
        }

        $data['estado_pagamento'] = $this->normalizeMovementPaymentState($data['estado_pagamento'] ?? null);
        $data['estado_conciliacao'] = $this->normalizeMovementReconciliationState($data['estado_conciliacao'] ?? null);
        $data['valor_total'] = abs((float) ($data['valor_total'] ?? 0));
        $data['observacoes'] = $data['observacoes'] ?? ($data['notes'] ?? null);

        if (!empty($data['attachment']) && $data['attachment'] instanceof UploadedFile) {
            $data['original_filename'] = $data['attachment']->getClientOriginalName();
            $data['mime_type'] = $data['attachment']->getClientMimeType();
            $data['sha256_hash'] = hash_file('sha256', $data['attachment']->getRealPath());
            $data['stored_path'] = $data['attachment']->store('financeiro/movimentos/documentos', 'public');
        }

        return $data;
    }

    private function syncFinancialEntryState(Movement $movement, $financialEntry, array $data, ?User $actor): void
    {
        $normalizedState = $this->normalizeMovementPaymentState($movement->estado_pagamento);
        $entryAmount = abs((float) $movement->valor_total);

        if ($normalizedState === 'pago') {
            $this->financialSettlementService->settleFinancialEntry($financialEntry, [
                'numero_recibo' => $data['document_number'] ?? ($data['numero_recibo'] ?? null),
                'amount' => $entryAmount,
                'payment_amount' => $entryAmount,
                'payment_date' => $data['paid_at'] ?? $movement->data_emissao?->toDateString() ?? now()->toDateString(),
                'method' => $data['metodo_pagamento'] ?? null,
                'reference' => $data['document_number'] ?? ($data['referencia_pagamento'] ?? null),
                'description' => $this->resolveFinancialDescription($movement, $data),
                'created_by' => $actor?->id,
                'source' => 'manual',
                'notes' => 'Despesa manual criada ja como paga.',
            ]);

            return;
        }

        $financialEntry->forceFill([
            'categoria' => $data['categoria'] ?? $financialEntry->categoria,
            'descricao' => $this->resolveFinancialDescription($movement, $data),
            'documento_ref' => $data['document_number'] ?? ($data['referencia_pagamento'] ?? $financialEntry->documento_ref),
            'valor' => $entryAmount,
            'valor_pago' => 0,
            'valor_em_aberto' => $normalizedState === 'cancelado' ? 0 : $entryAmount,
            'estado' => match ($normalizedState) {
                'pago_parcial' => 'parcial',
                'cancelado' => 'cancelado',
                default => 'pendente',
            },
            'data_pagamento' => null,
            'metodo_pagamento' => $data['metodo_pagamento'] ?? $financialEntry->metodo_pagamento,
            'documento_original' => $data['stored_path'] ?? $financialEntry->documento_original,
        ])->save();
    }

    private function createMovementDocument(Movement $movement, array $data, ?User $actor): ?MovementDocument
    {
        $documentType = $data['document_type'] ?? null;
        $hasDocumentMetadata = $documentType || !empty($data['stored_path']) || !empty($data['document_number']);

        if (!$hasDocumentMetadata) {
            return null;
        }

        return MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'supplier_id' => $movement->supplier_id,
            'document_type' => $documentType ?? 'other',
            'source_type' => $data['source_type'] ?? (!empty($data['stored_path']) ? 'manual_upload' : 'system'),
            'source_id' => $data['source_id'] ?? null,
            'original_filename' => $data['original_filename'] ?? null,
            'stored_path' => $data['stored_path'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'sha256_hash' => $data['sha256_hash'] ?? null,
            'document_number' => $data['document_number'] ?? null,
            'issue_date' => $data['document_date'] ?? $movement->data_emissao,
            'due_date' => $data['due_date'] ?? $movement->data_vencimento,
            'amount' => $data['document_amount'] ?? abs((float) $movement->valor_total),
            'vat_amount' => $data['vat_amount'] ?? null,
            'status' => $data['document_status'] ?? 'pending_validation',
            'validated_at' => ($data['document_status'] ?? null) === 'valid' ? now() : null,
            'validated_by' => ($data['document_status'] ?? null) === 'valid' ? $actor?->id : null,
            'notes' => $data['document_notes'] ?? ($data['observacoes'] ?? null),
        ]);
    }

    private function resolveFinancialDescription(Movement $movement, array $data): string
    {
        return (string) ($data['financial_description']
            ?? $data['observacoes']
            ?? $data['categoria']
            ?? ('Despesa - ' . ($movement->nome_manual ?: 'Fornecedor')));
    }

    private function normalizeMovementPaymentState(?string $state): string
    {
        return match ($state) {
            'pago' => 'pago',
            'parcial', 'pago_parcial' => 'pago_parcial',
            'cancelado' => 'cancelado',
            default => 'por_pagar',
        };
    }

    private function normalizeMovementReconciliationState(?string $state): string
    {
        return match ($state) {
            'conciliado' => 'conciliado',
            'sugerido' => 'sugerido',
            'divergente' => 'divergente',
            default => 'nao_conciliado',
        };
    }
}

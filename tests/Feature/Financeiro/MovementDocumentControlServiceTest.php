<?php

namespace Tests\Feature\Financeiro;

use App\Models\Movement;
use App\Models\MovementDocument;
use App\Models\MovementDocumentRequirement;
use App\Services\Financeiro\MovementDocumentControlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MovementDocumentControlServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_receipt_satisfies_invoice_and_receipt_requirements(): void
    {
        $movement = Movement::query()->create([
            'nome_manual' => 'Piscina Municipal',
            'classificacao' => 'despesa',
            'categoria' => 'servicos',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 120.00,
            'estado_pagamento' => 'pago',
            'estado_conciliacao' => 'nao_conciliado',
            'tipo' => 'servico',
        ]);

        MovementDocumentRequirement::query()->create([
            'movement_classification' => 'despesa',
            'movement_type' => 'servico',
            'category' => 'servicos',
            'requires_invoice' => true,
            'requires_receipt' => true,
            'requires_payment_proof' => false,
        ]);

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'invoice_receipt',
            'status' => 'valid',
            'amount' => 120.00,
        ]);

        $movement->refresh();

        $this->assertSame('completo', $movement->estado_documental);
        $this->assertSame('complete', $movement->document_control_status);
        $this->assertTrue(app(MovementDocumentControlService::class)->hasRequiredDocuments($movement));
    }

    public function test_paid_movement_without_required_receipt_is_marked_missing_receipt(): void
    {
        $movement = Movement::query()->create([
            'nome_manual' => 'Contabilidade',
            'classificacao' => 'despesa',
            'categoria' => 'servicos',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 55.00,
            'estado_pagamento' => 'pago',
            'estado_conciliacao' => 'nao_conciliado',
            'tipo' => 'servico',
        ]);

        MovementDocumentRequirement::query()->create([
            'movement_classification' => 'despesa',
            'movement_type' => 'servico',
            'requires_invoice' => true,
            'requires_receipt' => true,
            'requires_payment_proof' => false,
        ]);

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'invoice',
            'status' => 'valid',
            'amount' => 55.00,
        ]);

        $movement->refresh();

        $this->assertSame('falta_recibo', $movement->estado_documental);
        $this->assertSame(['receipt'], app(MovementDocumentControlService::class)->missingDocuments($movement));
    }

    public function test_amount_divergence_marks_movement_as_inconsistent(): void
    {
        $movement = Movement::query()->create([
            'nome_manual' => 'Seguro',
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 90.00,
            'estado_pagamento' => 'por_pagar',
            'estado_conciliacao' => 'nao_conciliado',
            'tipo' => 'servico',
        ]);

        MovementDocument::query()->create([
            'movement_id' => $movement->id,
            'document_type' => 'invoice',
            'status' => 'valid',
            'amount' => 100.00,
        ]);

        $movement->refresh();

        $this->assertSame('inconsistente', $movement->estado_documental);
        $this->assertSame('inconsistent', $movement->document_control_status);
    }
}
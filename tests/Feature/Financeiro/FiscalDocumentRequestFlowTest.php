<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Services\Financeiro\FiscalDocumentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalDocumentRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_request_from_an_invoice(): void
    {
        $invoice = $this->createInvoice();

        $request = app(FiscalDocumentRequestService::class)->createFromInvoice($invoice, [
            'paid_at' => '2026-05-05',
        ]);

        $this->assertSame($invoice->id, $request->invoice_id);
        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $request->status);
        $this->assertSame('123456789', $request->customer_tax_number);
        $this->assertSame('Mensalidade maio', $request->description);
    }

    public function test_it_avoids_duplicates_for_the_same_invoice(): void
    {
        $invoice = $this->createInvoice();
        $service = app(FiscalDocumentRequestService::class);

        $first = $service->createFromInvoice($invoice);
        $second = $service->createFromInvoice($invoice);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('fiscal_document_requests', 1);
    }

    public function test_it_marks_a_request_as_issued(): void
    {
        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $user = User::factory()->admin()->create();

        $updated = app(FiscalDocumentRequestService::class)->markIssued($request, [
            'external_document_number' => 'RC 2026/15',
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'issued_at' => '2026-05-05 10:00:00',
        ], $user->id);

        $this->assertSame(FiscalDocumentRequest::STATUS_ISSUED, $updated->status);
        $this->assertSame('RC 2026/15', $updated->external_document_number);
        $this->assertSame($user->id, $updated->issued_by);
    }

    public function test_it_lists_pending_requests(): void
    {
        $user = User::factory()->admin()->create();

        FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_CANCELLED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $response = $this->actingAs($user)->getJson(route('financeiro.fiscal-document-requests.index', [
            'status' => FiscalDocumentRequest::STATUS_PENDING,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', FiscalDocumentRequest::STATUS_PENDING);
    }

    public function test_pending_request_can_be_marked_in_progress(): void
    {
        $user = User::factory()->admin()->create();

        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('financeiro.fiscal-document-requests.mark-in-progress', $request)
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', FiscalDocumentRequest::STATUS_IN_PROGRESS);

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'status' => FiscalDocumentRequest::STATUS_IN_PROGRESS,
            'handled_by' => $user->id,
        ]);
    }

    private function createInvoice(): Invoice
    {
        $user = User::factory()->create([
            'nome_completo' => 'Socio Fiscal',
            'nif' => '123456789',
            'morada' => 'Rua do Clube 10',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa',
            'email' => 'socio@example.com',
        ]);

        $costCenter = CostCenter::create([
            'codigo' => 'CC-FISCAL',
            'nome' => 'Centro Fiscal',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-05',
            'valor_total' => 55.00,
            'estado_pagamento' => 'pendente',
            'numero_recibo' => null,
            'referencia_pagamento' => 'REF-2026-05',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'mensalidade',
            'observacoes' => null,
        ]);

        InvoiceItem::create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Mensalidade maio',
            'quantidade' => 1,
            'valor_unitario' => 55.00,
            'imposto_percentual' => 0,
            'total_linha' => 55.00,
            'centro_custo_id' => $costCenter->id,
        ]);

        BankStatement::create([
            'conta' => 'PT50',
            'data_movimento' => '2026-05-05',
            'descricao' => 'Pagamento mensalidade',
            'valor' => 55.00,
            'saldo' => 100.00,
            'referencia' => 'TRX-55',
            'centro_custo_id' => $costCenter->id,
            'conciliado' => false,
        ]);

        return $invoice;
    }
}
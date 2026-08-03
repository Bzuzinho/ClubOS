<?php

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Financeiro\ManualInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ManualInvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_manual_invoice_items_and_decrements_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $costCenter = $this->createCostCenter();
        $this->createInvoiceTypes();

        $response = $this->actingAs($admin)->postJson(route('financeiro.store'), $this->payload($user, $costCenter, [
            'valor_total' => 30,
            'items' => [
                $this->item(['produto_id' => $product->id, 'quantidade' => 2, 'valor_unitario' => 15, 'total_linha' => 30]),
            ],
        ]));

        $response->assertOk()
            ->assertJsonPath('invoice.valor_total', '30.00')
            ->assertJsonPath('invoice.valor_pago', '0.00')
            ->assertJsonPath('invoice.valor_em_aberto', '30.00')
            ->assertJsonPath('invoice.origem_tipo', 'manual');

        $invoice = Invoice::query()->findOrFail($response->json('invoice.id'));
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(8, $product->fresh()->stock);
        $this->assertSame(1, StockMovement::query()
            ->where('article_id', $product->id)
            ->where('movement_type', 'exit')
            ->where('reference_type', 'manual_invoice_create')
            ->count());
    }

    public function test_store_rolls_back_when_item_or_stock_write_fails(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $costCenter = $this->createCostCenter();
        $this->createInvoiceTypes();

        try {
            app(ManualInvoiceService::class)->create($this->payload($user, $costCenter, [
                'valor_total' => 45,
                'items' => [
                    $this->item(['produto_id' => $product->id, 'quantidade' => 1, 'valor_unitario' => 15, 'total_linha' => 15]),
                    $this->item(['produto_id' => (string) Str::uuid(), 'quantidade' => 1, 'valor_unitario' => 30, 'total_linha' => 30]),
                ],
            ]));

            $this->fail('Expected manual invoice creation to fail.');
        } catch (\Throwable $exception) {
            $this->assertNotInstanceOf(ValidationException::class, $exception);
        }

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, InvoiceItem::query()->count());
        $this->assertSame(10, $product->fresh()->stock);
    }

    public function test_store_rejects_invoice_total_divergence(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createInvoiceTypes();

        $this->actingAs($admin)
            ->postJson(route('financeiro.store'), $this->payload(User::factory()->create(), $this->createCostCenter(), [
                'valor_total' => 31,
                'items' => [$this->item(['valor_unitario' => 30, 'total_linha' => 30])],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['valor_total']);
    }

    public function test_store_rejects_line_total_divergence(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createInvoiceTypes();

        $this->actingAs($admin)
            ->postJson(route('financeiro.store'), $this->payload(User::factory()->create(), $this->createCostCenter(), [
                'valor_total' => 29,
                'items' => [$this->item(['valor_unitario' => 30, 'total_linha' => 29])],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.total_linha']);
    }

    public function test_store_blocks_initial_paid_or_partial_state(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createInvoiceTypes();

        foreach (['pago', 'parcial'] as $status) {
            $this->actingAs($admin)
                ->postJson(route('financeiro.store'), $this->payload(User::factory()->create(), $this->createCostCenter(), [
                    'estado_pagamento' => $status,
                ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['estado_pagamento']);
        }
    }

    public function test_store_blocks_monthly_fee_type(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createInvoiceTypes();

        $this->actingAs($admin)
            ->postJson(route('financeiro.store'), $this->payload(User::factory()->create(), $this->createCostCenter(), [
                'tipo' => 'mensalidade',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo']);
    }

    public function test_update_pending_invoice_changes_items_stock_delta_and_open_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $costCenter = $this->createCostCenter();
        $product = Product::factory()->create(['stock' => 10]);
        $this->createInvoiceTypes();

        $invoice = app(ManualInvoiceService::class)->create($this->payload($user, $costCenter, [
            'valor_total' => 20,
            'items' => [$this->item(['produto_id' => $product->id, 'quantidade' => 1, 'valor_unitario' => 20, 'total_linha' => 20])],
        ]));

        $this->assertSame(9, $product->fresh()->stock);

        $response = $this->actingAs($admin)->putJson(route('financeiro.update', $invoice), $this->payload($user, $costCenter, [
            'valor_total' => 45,
            'items' => [$this->item(['produto_id' => $product->id, 'quantidade' => 3, 'valor_unitario' => 15, 'total_linha' => 45])],
        ]));

        $response->assertOk()
            ->assertJsonPath('invoice.valor_total', '45.00')
            ->assertJsonPath('invoice.valor_pago', '0.00')
            ->assertJsonPath('invoice.valor_em_aberto', '45.00');

        $this->assertSame(7, $product->fresh()->stock);
        $this->assertSame(1, $invoice->fresh()->items()->count());
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'manual_invoice_update_reversal')->count());
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'manual_invoice_update_exit')->count());
    }

    public function test_update_rolls_back_previous_invoice_items_and_stock_on_failure(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $costCenter = $this->createCostCenter();
        $this->createInvoiceTypes();

        $invoice = app(ManualInvoiceService::class)->create($this->payload($user, $costCenter, [
            'valor_total' => 15,
            'items' => [$this->item(['produto_id' => $product->id, 'quantidade' => 1, 'valor_unitario' => 15, 'total_linha' => 15])],
        ]));

        try {
            app(ManualInvoiceService::class)->update($invoice, $this->payload($user, $costCenter, [
                'valor_total' => 45,
                'items' => [
                    $this->item(['produto_id' => $product->id, 'quantidade' => 2, 'valor_unitario' => 15, 'total_linha' => 30]),
                    $this->item(['produto_id' => (string) Str::uuid(), 'quantidade' => 1, 'valor_unitario' => 15, 'total_linha' => 15]),
                ],
            ]));

            $this->fail('Expected manual invoice update to fail.');
        } catch (\Throwable $exception) {
            $this->assertNotInstanceOf(ValidationException::class, $exception);
        }

        $invoice->refresh();
        $this->assertSame('15.00', $invoice->valor_total);
        $this->assertSame(1, $invoice->items()->count());
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_update_with_payment_allocation_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createManualInvoice();
        $this->attachPaymentAllocation($invoice);

        $this->actingAs($admin)
            ->putJson(route('financeiro.update', $invoice), $this->payload($invoice->user, $invoice->costCenter, ['valor_total' => 30]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);
    }

    public function test_update_with_fiscal_document_request_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createManualInvoice();

        FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 15,
        ]);

        $this->actingAs($admin)
            ->putJson(route('financeiro.update', $invoice), $this->payload($invoice->user, $invoice->costCenter, ['valor_total' => 30]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);
    }

    public function test_update_and_destroy_with_soft_deleted_fiscal_document_request_are_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createManualInvoice();

        $request = FiscalDocumentRequest::query()->create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 15,
        ]);
        $request->delete();

        $this->actingAs($admin)
            ->putJson(route('financeiro.update', $invoice), $this->payload($invoice->user, $invoice->costCenter, ['valor_total' => 30]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);

        $this->actingAs($admin)
            ->postJson(route('financeiro.destroy.post', $invoice))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertSame(1, $invoice->fresh()->items()->count());
    }

    public function test_update_with_receipt_or_import_trace_is_blocked(): void
    {
        $admin = User::factory()->admin()->create();
        $invoice = $this->createManualInvoice();
        $invoice->forceFill(['numero_recibo' => 'RC-100'])->save();

        $this->actingAs($admin)
            ->putJson(route('financeiro.update', $invoice), $this->payload($invoice->user, $invoice->costCenter, ['valor_total' => 30]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);
    }

    public function test_destroy_clean_invoice_deletes_items_and_restores_stock(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $invoice = $this->createManualInvoiceWithProduct($product, 2, 30);

        $this->assertSame(8, $product->fresh()->stock);

        $this->actingAs($admin)
            ->postJson(route('financeiro.destroy.post', $invoice))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('invoice_items', ['fatura_id' => $invoice->id]);
        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(1, StockMovement::query()->where('reference_type', 'manual_invoice_delete')->count());
    }

    public function test_destroy_with_financial_trail_is_blocked_without_stock_change(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $invoice = $this->createManualInvoiceWithProduct($product, 2, 30);
        $this->attachPaymentAllocation($invoice);

        $this->actingAs($admin)
            ->postJson(route('financeiro.destroy.post', $invoice))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice']);

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_destroy_monthly_invoice_with_stock_is_blocked_without_stock_change(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['stock' => 10]);
        $invoice = $this->createManualInvoiceWithProduct($product, 2, 30);
        $invoice->forceFill(['tipo' => 'mensalidade'])->save();

        $this->assertSame(8, $product->fresh()->stock);

        $this->actingAs($admin)
            ->postJson(route('financeiro.destroy.post', $invoice))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['invoice'])
            ->assertJsonPath('errors.invoice.0', 'A mensalidade tem artigos de stock associados e nao pode ser apagada por este fluxo.');

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'tipo' => 'mensalidade']);
        $this->assertDatabaseHas('invoice_items', ['fatura_id' => $invoice->id, 'produto_id' => $product->id]);
        $this->assertSame(8, $product->fresh()->stock);
    }

    public function test_current_account_includes_pending_manual_invoice_and_excludes_cancelled(): void
    {
        $user = User::factory()->create();
        $costCenter = $this->createCostCenter();
        $this->createInvoiceTypes();

        app(ManualInvoiceService::class)->create($this->payload($user, $costCenter, ['valor_total' => 30]));
        app(ManualInvoiceService::class)->create($this->payload($user, $costCenter, [
            'valor_total' => 40,
            'estado_pagamento' => 'cancelado',
            'items' => [$this->item(['valor_unitario' => 40, 'total_linha' => 40])],
        ]));

        $summary = app(CurrentAccountService::class)->summarize(['user_id' => $user->id, 'reference_date' => '2026-05-20']);

        $this->assertSame(30.0, (float) $summary['gross_debt']);
        $this->assertSame(1, $summary['open_invoice_count']);
        $this->assertSame(30.0, (float) $summary['breakdown']['invoices'][0]['valor_em_aberto']);
    }

    private function createManualInvoice(array $overrides = []): Invoice
    {
        $user = User::factory()->create();
        $costCenter = $this->createCostCenter();
        $this->createInvoiceTypes();

        return app(ManualInvoiceService::class)->create($this->payload($user, $costCenter, $overrides));
    }

    private function createManualInvoiceWithProduct(Product $product, int $quantity, float $total): Invoice
    {
        $user = User::factory()->create();
        $costCenter = $this->createCostCenter();
        $this->createInvoiceTypes();

        return app(ManualInvoiceService::class)->create($this->payload($user, $costCenter, [
            'valor_total' => $total,
            'items' => [$this->item([
                'produto_id' => $product->id,
                'quantidade' => $quantity,
                'valor_unitario' => $total / $quantity,
                'total_linha' => $total,
            ])],
        ]));
    }

    private function attachPaymentAllocation(Invoice $invoice): void
    {
        $payment = Payment::query()->create([
            'amount' => 5,
            'allocated_amount' => 5,
            'unallocated_amount' => 0,
            'payment_date' => '2026-05-20',
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 5,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);
    }

    private function payload(User $user, CostCenter $costCenter, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $user->id,
            'data_emissao' => '2026-05-20',
            'data_vencimento' => '2026-05-30',
            'data_fatura' => '2026-05-20',
            'tipo' => 'material',
            'valor_total' => 30,
            'estado_pagamento' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'items' => [$this->item(['centro_custo_id' => $costCenter->id])],
        ], $overrides);
    }

    private function item(array $overrides = []): array
    {
        return array_merge([
            'descricao' => 'Touca',
            'quantidade' => 1,
            'valor_unitario' => 30,
            'imposto_percentual' => 0,
            'total_linha' => 30,
            'produto_id' => null,
            'centro_custo_id' => null,
        ], $overrides);
    }

    private function createInvoiceTypes(): void
    {
        foreach ([
            ['codigo' => 'mensalidade', 'nome' => 'Mensalidade'],
            ['codigo' => 'material', 'nome' => 'Material'],
        ] as $invoiceType) {
            InvoiceType::query()->firstOrCreate(
                ['codigo' => $invoiceType['codigo']],
                ['nome' => $invoiceType['nome'], 'descricao' => $invoiceType['nome'], 'ativo' => true],
            );
        }
    }

    private function createCostCenter(): CostCenter
    {
        return CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-MANUAL'],
            ['nome' => 'Centro Manual', 'tipo' => 'departamento', 'ativo' => true],
        );
    }
}

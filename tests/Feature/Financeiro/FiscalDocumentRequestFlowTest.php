<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\DadosPessoais;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\User;
use App\Services\Financeiro\FiscalDocumentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
        $this->assertNull($request->due_at);
    }

    public function test_it_only_sets_an_internal_due_date_when_explicitly_requested(): void
    {
        $invoice = $this->createInvoice();

        $request = app(FiscalDocumentRequestService::class)->createFromInvoice($invoice, [
            'paid_at' => '2026-05-05',
            'due_at' => '2026-05-10',
        ]);

        $this->assertSame('2026-05-05', $request->paid_at?->toDateString());
        $this->assertSame('2026-05-10', $request->due_at?->toDateString());
        $this->assertTrue($request->metadata['internal_due_at_explicit'] ?? false);
    }

    public function test_overdue_scope_ignores_legacy_invoice_due_dates_without_explicit_marker(): void
    {
        $legacyRequest = app(FiscalDocumentRequestService::class)->createFromInvoice($this->createInvoice());
        $legacyRequest->forceFill([
            'due_at' => now()->subDays(5)->toDateString(),
        ])->save();

        $explicitRequest = app(FiscalDocumentRequestService::class)->createFromInvoice($this->createInvoice(), [
            'due_at' => now()->subDay()->toDateString(),
        ]);

        $overdueIds = FiscalDocumentRequest::query()->overdue()->pluck('id');

        $this->assertFalse($legacyRequest->fresh()->isOverdue());
        $this->assertTrue($explicitRequest->fresh()->isOverdue());
        $this->assertFalse($overdueIds->contains($legacyRequest->id));
        $this->assertTrue($overdueIds->contains($explicitRequest->id));
    }

    public function test_create_from_invoice_uses_dados_pessoais_fiscal_data_before_users_legacy_data(): void
    {
        $invoice = $this->createInvoice();
        $user = $invoice->user;

        $user->forceFill([
            'nome_completo' => 'Nome Legacy User',
            'name' => 'Legacy Name Fallback',
            'nif' => '299999999',
            'morada' => 'Rua Legacy 1',
            'codigo_postal' => '9000-900',
            'localidade' => 'Porto Legacy',
        ])->save();

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Nome Canonico Dados Pessoais',
            'nif' => '211111111',
            'morada' => 'Rua Canonica 123',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa Canonica',
        ]);

        $request = app(FiscalDocumentRequestService::class)->createFromInvoice($invoice->fresh(['user', 'items']));

        $this->assertSame('Nome Canonico Dados Pessoais', $request->customer_name);
        $this->assertSame('211111111', $request->customer_tax_number);
        $this->assertSame("Rua Canonica 123\n1000-100 Lisboa Canonica", $request->customer_address);
        $this->assertNotSame(FiscalDocumentRequest::STATUS_ERROR_DATA, $request->status);
    }

    public function test_create_from_invoice_falls_back_to_users_legacy_fiscal_data(): void
    {
        $invoice = $this->createInvoice();
        $user = $invoice->user;

        DadosPessoais::query()->where('user_id', $user->id)->delete();

        $user->forceFill([
            'nome_completo' => 'Nome Legacy Prioritario',
            'nif' => '233333333',
            'morada' => 'Rua Legacy Fallback 10',
            'codigo_postal' => '2000-200',
            'localidade' => 'Santarém',
        ])->save();

        $request = app(FiscalDocumentRequestService::class)->createFromInvoice($invoice->fresh(['user', 'items']));

        $this->assertSame('Nome Legacy Prioritario', $request->customer_name);
        $this->assertSame('233333333', $request->customer_tax_number);
        $this->assertSame("Rua Legacy Fallback 10\n2000-200 Santarém", $request->customer_address);
        $this->assertNotSame(FiscalDocumentRequest::STATUS_ERROR_DATA, $request->status);
    }

    public function test_create_from_invoice_reports_missing_nif_using_resolver_result(): void
    {
        $invoice = $this->createInvoice();
        $user = $invoice->user;

        $user->forceFill([
            'nome_completo' => 'Socio Sem NIF',
            'nif' => null,
            'morada' => 'Rua Sem NIF 10',
            'codigo_postal' => '3000-300',
            'localidade' => 'Coimbra',
        ])->save();

        DadosPessoais::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'nome_completo' => 'Socio Sem NIF Canonico',
                'nif' => null,
                'morada' => 'Rua Sem NIF Canonica 20',
                'codigo_postal' => '4000-400',
                'localidade' => 'Porto',
            ],
        );

        $request = app(FiscalDocumentRequestService::class)->createFromInvoice($invoice->fresh(['user', 'items']));

        $this->assertSame('Socio Sem NIF Canonico', $request->customer_name);
        $this->assertNull($request->customer_tax_number);
        $this->assertSame(FiscalDocumentRequest::STATUS_ERROR_DATA, $request->status);
        $this->assertStringContainsString('NIF do cliente em falta.', (string) $request->last_error);
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

    public function test_it_serializes_real_invoice_lines_in_fiscal_payload_metadata(): void
    {
        $invoice = $this->createInvoiceWithItems([
            ['descricao' => 'Mensalidade maio', 'valor' => 30.00],
            ['descricao' => 'Desconto/Correcao 10%', 'valor' => -3.00],
        ]);

        $request = app(FiscalDocumentRequestService::class)->createFromInvoice($invoice);

        $this->assertSame('27.00', $request->amount);
        $this->assertSame('Mensalidade maio; Desconto/Correcao 10%', $request->description);
        $this->assertCount(2, $request->metadata['line_items'] ?? []);
        $this->assertSame('Mensalidade maio', $request->metadata['line_items'][0]['description'] ?? null);
        $this->assertEquals(30.0, $request->metadata['line_items'][0]['line_total'] ?? null);
        $this->assertSame('Desconto/Correcao 10%', $request->metadata['line_items'][1]['description'] ?? null);
        $this->assertEquals(-3.0, $request->metadata['line_items'][1]['line_total'] ?? null);
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
        $this->assertSame($user->id, $updated->handled_by);
        $this->assertNotNull($updated->handled_at);
    }

    public function test_marking_request_as_issued_syncs_invoice_receipt_number(): void
    {
        $invoice = $this->createInvoice('pago');

        $request = FiscalDocumentRequest::create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        app(FiscalDocumentRequestService::class)->markIssued($request, [
            'external_document_number' => 'RC 2026/31',
            'issued_at' => '2026-05-06 10:30:00',
        ]);

        $invoice->refresh();

        $this->assertSame('RC 2026/31', $invoice->numero_recibo);
        $this->assertSame('2026-05-06', optional($invoice->recibo_emitido_em)->toDateString());
    }

    public function test_invoice_status_change_to_paid_creates_pending_fiscal_request(): void
    {
        $invoice = $this->createInvoice('pendente');

        $invoice->update([
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-05-05',
        ]);

        $this->assertDatabaseHas('fiscal_document_requests', [
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'external_document_number' => null,
        ]);
    }

    public function test_paid_invoice_reversion_without_external_document_number_soft_deletes_fiscal_request(): void
    {
        $invoice = $this->createInvoice('pendente');

        $invoice->update([
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-05-05',
        ]);

        $request = FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $invoice->update([
            'estado_pagamento' => 'pendente',
            'data_pagamento' => null,
        ]);

        $this->assertSoftDeleted('fiscal_document_requests', [
            'id' => $request->id,
        ]);
    }

    public function test_paid_invoice_reversion_with_external_document_number_is_blocked(): void
    {
        $invoice = $this->createInvoice('pendente');

        $invoice->update([
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-05-05',
        ]);

        $request = FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->firstOrFail();

        app(FiscalDocumentRequestService::class)->markIssued($request, [
            'external_document_number' => 'RC 2026/99',
            'issued_at' => '2026-05-05 10:00:00',
        ]);

        try {
            $invoice->update([
                'estado_pagamento' => 'pendente',
                'data_pagamento' => null,
            ]);

            $this->fail('Expected invoice status change to be blocked.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                FiscalDocumentRequestService::INVOICE_STATUS_CHANGE_BLOCK_MESSAGE,
                $exception->errors()['estado_pagamento'][0] ?? null,
            );
        }

        $invoice->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'external_document_number' => 'RC 2026/99',
        ]);
    }

    public function test_pending_request_generic_update_allows_notes_and_priority_only(): void
    {
        $user = User::factory()->admin()->create();
        $request = $this->createFiscalRequest([
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'notes' => 'Inicial',
        ]);

        $response = $this->actingAs($user)->patchJson(
            route('financeiro.fiscal-document-requests.update', $request),
            [
                'priority' => FiscalDocumentRequest::PRIORITY_HIGH,
                'notes' => 'Validar antes de emitir',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.priority', FiscalDocumentRequest::PRIORITY_HIGH)
            ->assertJsonPath('data.notes', 'Validar antes de emitir');

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'priority' => FiscalDocumentRequest::PRIORITY_HIGH,
            'notes' => 'Validar antes de emitir',
        ]);
    }

    public function test_pending_request_generic_update_blocks_document_identity(): void
    {
        $user = User::factory()->admin()->create();
        $request = $this->createFiscalRequest();

        $this->actingAs($user)
            ->patchJson(route('financeiro.fiscal-document-requests.update', $request), [
                'external_document_number' => 'RC 2026/500',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['external_document_number']);

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'external_document_number' => null,
        ]);
    }

    public function test_pending_request_generic_update_blocks_status_transition(): void
    {
        $user = User::factory()->admin()->create();
        $request = $this->createFiscalRequest();

        $this->actingAs($user)
            ->patchJson(route('financeiro.fiscal-document-requests.update', $request), [
                'status' => FiscalDocumentRequest::STATUS_ISSUED,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
        ]);
    }

    public function test_pending_request_generic_update_blocks_structural_fields(): void
    {
        $user = User::factory()->admin()->create();
        $request = $this->createFiscalRequest([
            'amount' => 55,
            'customer_name' => 'Socio Fiscal',
        ]);

        foreach (['amount' => 60, 'customer_name' => 'Outro Socio', 'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE] as $field => $value) {
            $this->actingAs($user)
                ->patchJson(route('financeiro.fiscal-document-requests.update', $request), [$field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'amount' => 55,
            'customer_name' => 'Socio Fiscal',
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
        ]);
    }

    public function test_issued_request_generic_update_blocks_structural_status_and_priority_fields(): void
    {
        $user = User::factory()->admin()->create();
        $request = $this->createFiscalRequest([
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'RC 2026/501',
            'amount' => 55,
            'customer_name' => 'Socio Fiscal',
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        foreach ([
            'amount' => 60,
            'customer_name' => 'Outro Socio',
            'status' => FiscalDocumentRequest::STATUS_CANCELLED,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_INVOICE,
            'provider' => 'outro',
            'priority' => FiscalDocumentRequest::PRIORITY_HIGH,
        ] as $field => $value) {
            $this->actingAs($user)
                ->patchJson(route('financeiro.fiscal-document-requests.update', $request), [$field => $value])
                ->assertStatus(422)
                ->assertJsonValidationErrors([$field]);
        }

        $request->refresh();
        $this->assertSame('55.00', $request->amount);
        $this->assertSame('Socio Fiscal', $request->customer_name);
        $this->assertSame(FiscalDocumentRequest::STATUS_ISSUED, $request->status);
        $this->assertSame(FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT, $request->document_type);
        $this->assertSame(FiscalDocumentRequest::PROVIDER_WINTOUCH, $request->provider);
        $this->assertSame(FiscalDocumentRequest::PRIORITY_NORMAL, $request->priority);
    }

    public function test_issued_request_generic_update_allows_notes_only(): void
    {
        $user = User::factory()->admin()->create();
        $request = $this->createFiscalRequest([
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'RC 2026/502',
            'notes' => 'Antes',
        ]);

        $response = $this->actingAs($user)->patchJson(
            route('financeiro.fiscal-document-requests.update', $request),
            ['notes' => 'Nota operacional']
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.notes', 'Nota operacional')
            ->assertJsonPath('data.external_document_number', 'RC 2026/502');
    }

    public function test_mark_issued_blocks_invoice_receipt_conflict_without_partial_request_update(): void
    {
        $invoice = $this->createInvoice('pago');
        $invoice->forceFill(['numero_recibo' => 'RC 2026/OLD'])->save();

        $request = $this->createFiscalRequest([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'external_document_number' => null,
        ]);

        try {
            app(FiscalDocumentRequestService::class)->markIssued($request, [
                'external_document_number' => 'RC 2026/NEW',
                'issued_at' => '2026-05-07 10:00:00',
            ]);

            $this->fail('Expected receipt number conflict to block markIssued.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'A fatura ja tem um numero de recibo diferente registado.',
                $exception->errors()['external_document_number'][0] ?? null,
            );
        }

        $request->refresh();
        $invoice->refresh();

        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $request->status);
        $this->assertNull($request->external_document_number);
        $this->assertNull($request->issued_at);
        $this->assertSame('RC 2026/OLD', $invoice->numero_recibo);
    }

    public function test_mark_issued_blocks_different_external_document_number_when_already_registered(): void
    {
        $request = $this->createFiscalRequest([
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'RC 2026/OLD',
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(FiscalDocumentRequestService::class)->markIssued($request, [
                'external_document_number' => 'RC 2026/NEW',
            ]);
        } catch (ValidationException $exception) {
            $this->assertSame(
                'O pedido fiscal ja tem um numero de documento externo diferente registado.',
                $exception->errors()['external_document_number'][0] ?? null,
            );

            throw $exception;
        }
    }

    public function test_cancelled_request_with_external_document_cannot_be_deleted_via_http(): void
    {
        $user = User::factory()->admin()->create();
        $request = $this->createFiscalRequest([
            'status' => FiscalDocumentRequest::STATUS_CANCELLED,
            'external_document_number' => 'RC 2026/503',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('financeiro.fiscal-document-requests.destroy', $request))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['request']);

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'status' => FiscalDocumentRequest::STATUS_CANCELLED,
            'external_document_number' => 'RC 2026/503',
            'deleted_at' => null,
        ]);
    }

    public function test_paid_invoice_reversion_with_external_document_id_is_blocked(): void
    {
        $invoice = $this->createInvoice('pendente');

        $invoice->update([
            'estado_pagamento' => 'pago',
            'data_pagamento' => '2026-05-05',
        ]);

        $request = FiscalDocumentRequest::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $request->forceFill([
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_id' => 'WT-ID-123',
        ])->save();

        try {
            $invoice->update([
                'estado_pagamento' => 'pendente',
                'data_pagamento' => null,
            ]);

            $this->fail('Expected invoice status change to be blocked.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                FiscalDocumentRequestService::INVOICE_STATUS_CHANGE_BLOCK_MESSAGE,
                $exception->errors()['estado_pagamento'][0] ?? null,
            );
        }

        $this->assertSame('pago', $invoice->fresh()->estado_pagamento);
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

    public function test_request_can_be_marked_as_issued_via_http(): void
    {
        $user = User::factory()->admin()->create();

        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_IN_PROGRESS,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('financeiro.fiscal-document-requests.mark-issued', $request),
            [
                'external_document_number' => 'RC 2026/25',
                'issued_at' => '2026-05-05',
                'notes' => 'Emitido manualmente',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', FiscalDocumentRequest::STATUS_ISSUED)
            ->assertJsonPath('data.external_document_number', 'RC 2026/25');

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'external_document_number' => 'RC 2026/25',
            'issued_by' => $user->id,
            'handled_by' => $user->id,
        ]);
    }

    public function test_mark_issued_still_requires_external_document_number_via_http(): void
    {
        $user = User::factory()->admin()->create();

        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_IN_PROGRESS,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('financeiro.fiscal-document-requests.mark-issued', $request),
            [
                'issued_at' => '2026-05-05',
                'notes' => 'Sem numero externo',
            ]
        );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('external_document_number');

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'status' => FiscalDocumentRequest::STATUS_IN_PROGRESS,
            'external_document_number' => null,
        ]);
    }

    public function test_request_can_be_marked_with_data_error_via_http(): void
    {
        $user = User::factory()->admin()->create();

        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('financeiro.fiscal-document-requests.mark-error-data', $request),
            [
                'last_error' => 'NIF em falta',
                'notes' => 'Validar ficha do cliente',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', FiscalDocumentRequest::STATUS_ERROR_DATA)
            ->assertJsonPath('data.last_error', 'NIF em falta');

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'status' => FiscalDocumentRequest::STATUS_ERROR_DATA,
            'last_error' => 'NIF em falta',
            'notes' => 'Validar ficha do cliente',
        ]);
    }

    public function test_request_without_external_document_can_be_deleted_via_http(): void
    {
        $user = User::factory()->admin()->create();

        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $response = $this->actingAs($user)->deleteJson(
            route('financeiro.fiscal-document-requests.destroy', $request)
        );

        $response
            ->assertNoContent();

        $this->assertSoftDeleted('fiscal_document_requests', [
            'id' => $request->id,
        ]);
    }

    public function test_request_with_external_document_cannot_be_deleted_via_http(): void
    {
        $user = User::factory()->admin()->create();

        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'external_document_number' => 'RC 2026/50',
        ]);

        $response = $this->actingAs($user)->deleteJson(
            route('financeiro.fiscal-document-requests.destroy', $request)
        );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('request');

        $this->assertSame(
            FiscalDocumentRequestService::DELETE_WITH_DOCUMENT_MESSAGE,
            $response->json('errors.request.0')
        );

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'external_document_number' => 'RC 2026/50',
        ]);
    }

    public function test_request_without_external_document_cannot_be_cancelled_via_http(): void
    {
        $user = User::factory()->admin()->create();

        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('financeiro.fiscal-document-requests.mark-cancelled', $request),
            [
                'reason' => 'Pedido anulado pelo operador',
            ]
        );

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors('request');

        $this->assertSame(
            FiscalDocumentRequestService::CANCEL_WITHOUT_DOCUMENT_MESSAGE,
            $response->json('errors.request.0')
        );
    }

    public function test_request_with_external_document_can_be_cancelled_via_http_and_keeps_document_number(): void
    {
        $user = User::factory()->admin()->create();

        $request = FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_ISSUED,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'external_document_number' => 'RC 2026/51',
            'external_series' => 'A',
            'issued_at' => '2026-05-05 10:00:00',
        ]);

        $response = $this->actingAs($user)->postJson(
            route('financeiro.fiscal-document-requests.mark-cancelled', $request),
            [
                'reason' => 'Documento anulado no provider fiscal externo',
            ]
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.status', FiscalDocumentRequest::STATUS_CANCELLED)
            ->assertJsonPath('data.last_error', 'Documento anulado no provider fiscal externo')
            ->assertJsonPath('data.external_document_number', 'RC 2026/51');

        $this->assertDatabaseHas('fiscal_document_requests', [
            'id' => $request->id,
            'status' => FiscalDocumentRequest::STATUS_CANCELLED,
            'last_error' => 'Documento anulado no provider fiscal externo',
            'external_document_number' => 'RC 2026/51',
            'handled_by' => $user->id,
        ]);
    }

    public function test_it_can_search_pending_requests(): void
    {
        $user = User::factory()->admin()->create();

        FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'customer_name' => 'Cliente Alfa',
            'internal_reference' => 'FISC-ALFA-1',
        ]);

        FiscalDocumentRequest::create([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'customer_name' => 'Cliente Beta',
            'internal_reference' => 'FISC-BETA-1',
        ]);

        $response = $this->actingAs($user)->getJson(route('financeiro.fiscal-document-requests.index', [
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'search' => 'ALFA',
            'per_page' => 10,
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.internal_reference', 'FISC-ALFA-1');
    }

    public function test_it_creates_manual_request_for_paid_invoice(): void
    {
        $user = User::factory()->admin()->create();
        $invoice = Invoice::withoutEvents(fn () => $this->createInvoice('pago'));

        $response = $this->actingAs($user)->postJson(
            route('financeiro.invoices.fiscal-document-request.store', $invoice)
        );

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Pedido fiscal criado com sucesso.')
            ->assertJsonPath('data.invoice_id', $invoice->id)
            ->assertJsonPath('data.provider', FiscalDocumentRequest::PROVIDER_WINTOUCH)
            ->assertJsonPath('data.document_type', FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT);

        $this->assertDatabaseHas('fiscal_document_requests', [
            'invoice_id' => $invoice->id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
        ]);
    }

    public function test_it_does_not_create_manual_request_for_unpaid_invoice(): void
    {
        $user = User::factory()->admin()->create();
        $invoice = $this->createInvoice('pendente');

        $response = $this->actingAs($user)->postJson(
            route('financeiro.invoices.fiscal-document-request.store', $invoice)
        );

        $response
            ->assertStatus(422)
            ->assertJsonPath('message', 'So e possivel criar pedido fiscal para faturas pagas.');

        $this->assertDatabaseMissing('fiscal_document_requests', [
            'invoice_id' => $invoice->id,
        ]);
    }

    public function test_it_returns_existing_manual_request_for_the_same_paid_invoice(): void
    {
        $user = User::factory()->admin()->create();
        $invoice = Invoice::withoutEvents(fn () => $this->createInvoice('pago'));

        $existing = FiscalDocumentRequest::create([
            'invoice_id' => $invoice->id,
            'user_id' => $invoice->user_id,
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
        ]);

        $response = $this->actingAs($user)->postJson(
            route('financeiro.invoices.fiscal-document-request.store', $invoice)
        );

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Ja existe pedido fiscal para esta fatura.')
            ->assertJsonPath('data.id', $existing->id);

        $this->assertDatabaseCount('fiscal_document_requests', 1);
    }

    private function createInvoice(string $estadoPagamento = 'pendente'): Invoice
    {
        $this->createInvoiceType();

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
            'estado_pagamento' => $estadoPagamento,
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

    private function createFiscalRequest(array $overrides = []): FiscalDocumentRequest
    {
        return FiscalDocumentRequest::query()->create(array_merge([
            'provider' => FiscalDocumentRequest::PROVIDER_WINTOUCH,
            'document_type' => FiscalDocumentRequest::DOCUMENT_TYPE_RECEIPT,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
            'priority' => FiscalDocumentRequest::PRIORITY_NORMAL,
            'amount' => 55,
            'customer_name' => 'Socio Fiscal',
        ], $overrides));
    }

    private function createInvoiceWithItems(array $items, string $estadoPagamento = 'pendente'): Invoice
    {
        $this->createInvoiceType();

        $user = User::factory()->create([
            'nome_completo' => 'Socio Fiscal',
            'nif' => '123456789',
            'morada' => 'Rua do Clube 10',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa',
            'email' => 'socio@example.com',
        ]);

        $costCenter = CostCenter::create([
            'codigo' => 'CC-FISCAL-' . uniqid(),
            'nome' => 'Centro Fiscal',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $total = round(collect($items)->sum('valor'), 2);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-05',
            'valor_total' => $total,
            'estado_pagamento' => $estadoPagamento,
            'numero_recibo' => null,
            'referencia_pagamento' => 'REF-2026-05-' . uniqid(),
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'mensalidade',
            'observacoes' => null,
        ]);

        foreach ($items as $item) {
            InvoiceItem::create([
                'fatura_id' => $invoice->id,
                'descricao' => $item['descricao'],
                'quantidade' => 1,
                'valor_unitario' => $item['valor'],
                'imposto_percentual' => 0,
                'total_linha' => $item['valor'],
                'centro_custo_id' => $costCenter->id,
            ]);
        }

        return $invoice->fresh('items');
    }

    private function createInvoiceType(): void
    {
        InvoiceType::query()->firstOrCreate(
            ['codigo' => 'mensalidade'],
            [
                'nome' => 'Mensalidade',
                'descricao' => 'Mensalidade',
                'ativo' => true,
            ],
        );
    }
}

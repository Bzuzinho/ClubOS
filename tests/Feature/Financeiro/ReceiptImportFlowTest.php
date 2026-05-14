<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankReconciliationAlias;
use App\Models\BankStatement;
use App\Models\BankTransactionAllocation;
use App\Models\CostCenter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ReceiptImportItem;
use App\Models\User;
use App\Services\AccessControl\UserTypeAccessControlService;
use App\Services\Financeiro\ReceiptCommitService;
use App\Services\Financeiro\ReceiptImportService;
use App\Services\Financeiro\ReceiptMatchingService;
use App\Services\Financeiro\ReceiptPdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ReceiptImportFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_a_pdf_and_matches_user_by_nif_without_changing_financial_state_before_commit(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([50.00], '123456789');

        $this->bindExtractor([
            'nif-match.pdf' => "Recibo N RC-001\nData 05/05/2026\nNome Socio Pagamentos\nNIF 123456789\nValor 50,00 EUR\nPeriodo 2026-05",
        ]);

        $batch = app(ReceiptImportService::class)->createBatchFromPayloads([
            ['name' => 'nif-match.pdf', 'contents' => 'PDF-RAW-1'],
        ], $admin);

        $item = $batch->items()->first();
        $invoice->refresh();

        $this->assertNotNull($item);
        $this->assertSame(ReceiptImportItem::STATUS_MATCHED, $item->status);
        $this->assertSame($invoice->user_id, $item->user_id);
        $this->assertSame($invoice->id, $item->invoice_id);
        $this->assertSame('pendente', $invoice->estado_pagamento);
        $this->assertNull($invoice->numero_recibo);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('bank_transaction_allocations', 0);
    }

    public function test_it_marks_duplicate_items_by_file_hash_and_receipt_number(): void
    {
        $admin = User::factory()->admin()->create();
        $this->createInvoicesForUser([50.00], '123456789');

        $this->bindExtractor([
            'first.pdf' => "Recibo N RC-100\nNIF 123456789\nValor 50,00 EUR\nData 05/05/2026",
            'same-hash.pdf' => "Recibo N RC-999\nNIF 123456789\nValor 50,00 EUR\nData 06/05/2026",
            'same-number.pdf' => "Recibo N RC-100\nNIF 123456789\nValor 50,00 EUR\nData 07/05/2026",
        ]);

        $firstBatch = app(ReceiptImportService::class)->createBatchFromPayloads([
            ['name' => 'first.pdf', 'contents' => 'DUPLICATE-HASH-CONTENT'],
        ], $admin);

        $hashBatch = app(ReceiptImportService::class)->createBatchFromPayloads([
            ['name' => 'same-hash.pdf', 'contents' => 'DUPLICATE-HASH-CONTENT'],
        ], $admin);
        $numberBatch = app(ReceiptImportService::class)->createBatchFromPayloads([
            ['name' => 'same-number.pdf', 'contents' => 'DIFFERENT-CONTENT'],
        ], $admin);

        $this->assertSame(ReceiptImportItem::STATUS_MATCHED, $firstBatch->items()->first()->status);
        $this->assertSame(ReceiptImportItem::STATUS_DUPLICATE, $hashBatch->items()->first()->status);
        $this->assertSame(ReceiptImportItem::STATUS_DUPLICATE, $numberBatch->items()->first()->status);
    }

    public function test_it_commits_an_imported_receipt_and_marks_the_invoice_paid_with_pdf_path_and_receipt_number(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([50.00], '123456789');
        $statement = $this->createBankStatement(50.00, 'TRF SEPA RECEBIDA DE ANTONIO MANUEL SILVA REF 12345');

        $this->bindExtractor([
            'commit.pdf' => "Recibo N RC-200\nData 05/05/2026\nNome Socio Pagamentos\nNIF 123456789\nValor 50,00 EUR\nPeriodo 2026-05",
        ]);

        $batch = app(ReceiptImportService::class)->createBatchFromPayloads([
            ['name' => 'commit.pdf', 'contents' => 'COMMIT-CONTENT'],
        ], $admin);
        $item = $batch->items()->first();

        app(ReceiptMatchingService::class)->rematchItem($item, [
            'bank_statement_id' => $statement->id,
        ]);

        app(ReceiptCommitService::class)->commitItems($batch->fresh(), [$item->id], $admin);

        $invoice->refresh();
        $statement->refresh();

        $this->assertSame('pago', $invoice->estado_pagamento);
        $this->assertSame('RC-200', $invoice->numero_recibo);
        $this->assertNotNull($invoice->recibo_pdf_path);
        $this->assertSame('0.00', $invoice->valor_em_aberto);
        $this->assertSame('reconciled', $statement->conciliacao_status);
        $this->assertDatabaseHas('bank_transaction_allocations', [
            'bank_statement_id' => $statement->id,
            'invoice_id' => $invoice->id,
            'valor_alocado' => 50.00,
            'origem' => 'importacao_recibos',
        ]);
        $this->assertDatabaseHas('bank_reconciliation_aliases', [
            'user_id' => $invoice->user_id,
            'raw_description' => 'TRF SEPA RECEBIDA DE ANTONIO MANUEL SILVA REF 12345',
            'extracted_after_de' => 'ANTONIO MANUEL SILVA',
            'normalized_alias' => 'antonio manuel silva',
            'source' => 'receipt_import',
        ]);
    }

    public function test_it_keeps_a_bank_statement_partially_reconciled_when_only_part_of_the_amount_is_allocated(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([30.00], '123456789');
        $statement = $this->createBankStatement(70.00, 'TRF SEPA RECEBIDA DE MARIA SILVA REF 100');

        $this->bindExtractor([
            'partial.pdf' => "Recibo N RC-300\nData 05/05/2026\nNIF 123456789\nValor 30,00 EUR\nPeriodo 2026-05",
        ]);

        $batch = app(ReceiptImportService::class)->createBatchFromPayloads([
            ['name' => 'partial.pdf', 'contents' => 'PARTIAL-CONTENT'],
        ], $admin);
        $item = $batch->items()->first();

        app(ReceiptMatchingService::class)->rematchItem($item, ['bank_statement_id' => $statement->id]);
        app(ReceiptCommitService::class)->commitItems($batch->fresh(), [$item->id], $admin);

        $statement->refresh();

        $this->assertSame('partial', $statement->conciliacao_status);
        $this->assertSame('30.00', $statement->valor_conciliado);
        $this->assertSame('40.00', $statement->valor_por_conciliar);
    }

    public function test_it_reconciles_a_bank_statement_when_allocations_exhaust_the_available_amount(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoiceA, $invoiceB] = $this->createInvoicesForUser([30.00, 40.00], '123456789');
        $statement = $this->createBankStatement(70.00, 'TRF SEPA RECEBIDA DE PEDRO COSTA REF 200');

        $this->bindExtractor([
            'first-full.pdf' => "Recibo N RC-400\nData 05/05/2026\nNIF 123456789\nValor 30,00 EUR\nPeriodo 2026-05",
            'second-full.pdf' => "Recibo N RC-401\nData 06/05/2026\nNIF 123456789\nValor 40,00 EUR\nPeriodo 2026-06",
        ]);

        $batch = app(ReceiptImportService::class)->createBatchFromPayloads([
            ['name' => 'first-full.pdf', 'contents' => 'FULL-ONE'],
            ['name' => 'second-full.pdf', 'contents' => 'FULL-TWO'],
        ], $admin);

        foreach ($batch->items as $item) {
            app(ReceiptMatchingService::class)->rematchItem($item, ['bank_statement_id' => $statement->id]);
        }

        app(ReceiptCommitService::class)->commitItems($batch->fresh(), $batch->items->pluck('id')->all(), $admin);

        $statement->refresh();

        $this->assertSame('reconciled', $statement->conciliacao_status);
        $this->assertSame('70.00', $statement->valor_conciliado);
        $this->assertSame('0.00', $statement->valor_por_conciliar);
        $this->assertDatabaseHas('bank_transaction_allocations', ['invoice_id' => $invoiceA->id, 'valor_alocado' => 30.00]);
        $this->assertDatabaseHas('bank_transaction_allocations', ['invoice_id' => $invoiceB->id, 'valor_alocado' => 40.00]);
    }

    public function test_it_blocks_commit_when_the_allocation_exceeds_the_bank_statement_available_amount(): void
    {
        $admin = User::factory()->admin()->create();
        [$invoice] = $this->createInvoicesForUser([60.00], '123456789');
        $statement = $this->createBankStatement(40.00, 'TRF SEPA RECEBIDA DE JOAO COSTA REF 300');

        $this->bindExtractor([
            'overflow.pdf' => "Recibo N RC-500\nData 05/05/2026\nNIF 123456789\nValor 60,00 EUR\nPeriodo 2026-05",
        ]);

        $batch = app(ReceiptImportService::class)->createBatchFromPayloads([
            ['name' => 'overflow.pdf', 'contents' => 'OVERFLOW-CONTENT'],
        ], $admin);
        $item = $batch->items()->first();

        app(ReceiptMatchingService::class)->rematchItem($item, ['bank_statement_id' => $statement->id]);

        try {
            app(ReceiptCommitService::class)->commitItems($batch->fresh(), [$item->id], $admin);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'A alocacao excede o valor disponivel do movimento bancario.',
                $exception->errors()['bank_statement_id'][0] ?? null,
            );
        } finally {
            $invoice->refresh();
            $statement->refresh();
            $this->assertSame('pendente', $invoice->estado_pagamento);
            $this->assertSame('unreconciled', $statement->conciliacao_status ?? 'unreconciled');
        }
    }

    public function test_receipt_import_routes_require_the_receipt_import_permission(): void
    {
        $user = User::factory()->create();
        $access = Mockery::mock(UserTypeAccessControlService::class);
        $access->shouldReceive('canAccessModule')->andReturn(true);
        $access->shouldReceive('canAccessPermission')->andReturn(false);
        $access->shouldReceive('canBypassOwnMemberProfileView')->andReturn(false);
        $access->shouldReceive('getCurrentUserAccess')->andReturn([
            'visibleMenuModules' => ['financeiro'],
            'permissions' => [],
        ]);
        $this->instance(UserTypeAccessControlService::class, $access);

        $this->actingAs($user)
            ->getJson(route('financeiro.receipt-imports.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->postJson(route('financeiro.receipt-imports.store'), [
                'use_pending_directory' => true,
                'pending_directory' => 'private/imports/receipts/pending',
            ])
            ->assertForbidden();
    }

    /**
     * @return array<int, Invoice>
     */
    private function createInvoicesForUser(array $amounts, string $nif): array
    {
        $user = User::factory()->create([
            'nome_completo' => 'Socio Pagamentos',
            'numero_socio' => '1234',
            'nif' => $nif,
            'email' => 'pagamentos@example.com',
        ]);

        $costCenter = CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-RECEIPTS'],
            [
                'nome' => 'Centro Recibos',
                'tipo' => 'departamento',
                'ativo' => true,
            ],
        );

        return collect($amounts)->values()->map(function (float $amount, int $index) use ($user, $costCenter) {
            $month = sprintf('2026-%02d', $index + 5);
            $invoice = Invoice::query()->create([
                'user_id' => $user->id,
                'data_fatura' => $month.'-01',
                'mes' => $month,
                'data_emissao' => $month.'-01',
                'data_vencimento' => $month.'-10',
                'valor_total' => $amount,
                'valor_pago' => 0,
                'valor_em_aberto' => $amount,
                'estado_pagamento' => 'pendente',
                'centro_custo_id' => $costCenter->id,
                'tipo' => 'mensalidade',
            ]);

            InvoiceItem::query()->create([
                'fatura_id' => $invoice->id,
                'descricao' => 'Mensalidade',
                'quantidade' => 1,
                'valor_unitario' => $amount,
                'imposto_percentual' => 0,
                'total_linha' => $amount,
                'centro_custo_id' => $costCenter->id,
            ]);

            return $invoice;
        })->all();
    }

    private function createBankStatement(float $amount, string $description): BankStatement
    {
        return BankStatement::query()->create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-05',
            'descricao' => $description,
            'valor' => $amount,
            'saldo' => 1000.00,
            'referencia' => 'TRX-IMPORT',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => $amount,
            'conciliacao_status' => 'unreconciled',
        ]);
    }

    /**
     * @param  array<string, string>  $map
     */
    private function bindExtractor(array $map): void
    {
        $this->app->instance(ReceiptPdfTextExtractor::class, new class($map) extends ReceiptPdfTextExtractor {
            public function __construct(private readonly array $map)
            {
            }

            public function extract(string $absolutePath): string
            {
                $baseName = pathinfo(basename($absolutePath), PATHINFO_FILENAME);

                foreach ($this->map as $fileName => $text) {
                    $expectedPrefix = pathinfo($fileName, PATHINFO_FILENAME).'-';
                    if (str_starts_with($baseName, $expectedPrefix)) {
                        return $text;
                    }
                }

                throw new \RuntimeException('Missing fake PDF text.');
            }
        });
    }
}
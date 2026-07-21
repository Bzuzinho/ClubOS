<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\Familia;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Movement;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\FinancialSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BankReconciliationAuditEndpointTest extends TestCase
{
    use RefreshDatabase;

    private static int $financeUserNumber = 900000;

    public function test_audit_endpoint_lists_paginated_bank_statements(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Auditoria Base']);

        for ($index = 1; $index <= 12; $index++) {
            $statement = $this->createBankStatement(
                amount: 10.00 + $index,
                description: 'Linha auditoria ' . $index,
                date: sprintf('2026-06-%02d', min($index, 28)),
            );

            if ($index <= 4) {
                $invoice = $this->createInvoice($user, 10.00 + $index, '2026-06-10');
                $this->settleInvoiceWithStatement($admin, $invoice, $statement, 10.00 + $index);
            }
        }

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'per_page' => 5,
                'page' => 2,
            ]))
            ->assertOk();

        $this->assertCount(5, $response->json('rows'));
        $this->assertSame(2, (int) $response->json('meta.current_page'));
        $this->assertSame(5, (int) $response->json('meta.per_page'));
        $this->assertSame(12, (int) $response->json('meta.total'));
    }

    public function test_state_filter_conciliado_returns_only_reconciled_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Filtro Conciliado']);

        $reconciled = $this->createBankStatement(30.00, 'Conciliado', '2026-06-02');
        $partial = $this->createBankStatement(40.00, 'Parcial', '2026-06-03');
        $open = $this->createBankStatement(50.00, 'Aberto', '2026-06-04');

        $invoiceReconciled = $this->createInvoice($user, 30.00, '2026-06-10');
        $this->settleInvoiceWithStatement($admin, $invoiceReconciled, $reconciled, 30.00);

        $invoicePartial = $this->createInvoice($user, 25.00, '2026-06-10');
        $this->settleInvoiceWithStatement($admin, $invoicePartial, $partial, 25.00);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'estado' => 'conciliado',
            ]))
            ->assertOk();

        $rows = collect($response->json('rows'));

        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn (array $row) => ($row['estado_conciliacao'] ?? null) === 'conciliado'));
        $this->assertFalse($rows->contains(fn (array $row) => ($row['bank_statement_id'] ?? null) === $open->id));
    }

    public function test_state_filter_parcial_returns_only_partial_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Filtro Parcial']);

        $partial = $this->createBankStatement(80.00, 'Linha parcial', '2026-06-05');
        $reconciled = $this->createBankStatement(30.00, 'Linha reconciliada', '2026-06-06');

        $invoicePartial = $this->createInvoice($user, 20.00, '2026-06-10');
        $this->settleInvoiceWithStatement($admin, $invoicePartial, $partial, 20.00);

        $invoiceReconciled = $this->createInvoice($user, 30.00, '2026-06-10');
        $this->settleInvoiceWithStatement($admin, $invoiceReconciled, $reconciled, 30.00);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'estado' => 'parcial',
            ]))
            ->assertOk();

        $rows = collect($response->json('rows'));

        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn (array $row) => ($row['estado_conciliacao'] ?? null) === 'parcial'));
    }

    public function test_state_filter_por_conciliar_returns_only_unreconciled_rows(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Filtro Aberto']);

        $open = $this->createBankStatement(40.00, 'Linha aberta', '2026-06-07');
        $partial = $this->createBankStatement(90.00, 'Linha parcial', '2026-06-08');

        $invoicePartial = $this->createInvoice($user, 30.00, '2026-06-10');
        $this->settleInvoiceWithStatement($admin, $invoicePartial, $partial, 30.00);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'estado' => 'por_conciliar',
            ]))
            ->assertOk();

        $rows = collect($response->json('rows'));

        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn (array $row) => ($row['estado_conciliacao'] ?? null) === 'por_conciliar'));
        $this->assertTrue($rows->contains(fn (array $row) => ($row['bank_statement_id'] ?? null) === $open->id));
    }

    public function test_date_range_filter_works(): void
    {
        $admin = User::factory()->admin()->create();

        $inside = $this->createBankStatement(20.00, 'Dentro do periodo', '2026-06-12');
        $outside = $this->createBankStatement(20.00, 'Fora do periodo', '2026-05-12');

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk();

        $rows = collect($response->json('rows'));

        $this->assertTrue($rows->contains(fn (array $row) => ($row['bank_statement_id'] ?? null) === $inside->id));
        $this->assertFalse($rows->contains(fn (array $row) => ($row['bank_statement_id'] ?? null) === $outside->id));
    }

    public function test_search_by_description_or_reference_works(): void
    {
        $admin = User::factory()->admin()->create();

        $target = $this->createBankStatement(45.00, 'Transferencia Familia Aurora', '2026-06-13', 'REF-AUD-999');
        $this->createBankStatement(45.00, 'Outra descricao', '2026-06-13', 'REF-AUD-111');

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'search' => 'Aurora',
            ]))
            ->assertOk();

        $rows = collect($response->json('rows'));
        $this->assertTrue($rows->contains(fn (array $row) => ($row['bank_statement_id'] ?? null) === $target->id));

        $responseByReference = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'search' => '999',
            ]))
            ->assertOk();

        $rowsByReference = collect($responseByReference->json('rows'));
        $this->assertTrue($rowsByReference->contains(fn (array $row) => ($row['bank_statement_id'] ?? null) === $target->id));
    }

    public function test_invoice_allocation_is_present_in_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Detalhe Fatura']);

        $statement = $this->createBankStatement(60.00, 'Pagamento mensalidade detalhe', '2026-06-14');
        $invoice = $this->createInvoice($user, 60.00, '2026-06-10');

        $this->settleInvoiceWithStatement($admin, $invoice, $statement, 60.00);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'search' => 'mensalidade detalhe',
            ]))
            ->assertOk();

        $row = collect($response->json('rows'))->firstWhere('bank_statement_id', $statement->id);

        $this->assertNotNull($row);
        $this->assertTrue(collect($row['allocations'] ?? [])->contains(fn (array $allocation) => ($allocation['tipo'] ?? null) === 'invoice'));
    }

    public function test_movement_allocation_is_present_in_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->createFinanceUser(['nome_completo' => 'Detalhe Movimento']);

        $statement = $this->createBankStatement(80.00, 'Pagamento movimento detalhe', '2026-06-15');
        $movement = $this->createMovement($member, 80.00);

        app(FinancialSettlementService::class)->settleMovement($movement, [
            'payment_date' => '2026-06-15',
            'method' => 'transferencia',
            'reference' => 'MOV-DET-001',
            'bank_statement_id' => $statement->id,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'search' => 'movimento detalhe',
            ]))
            ->assertOk();

        $row = collect($response->json('rows'))->firstWhere('bank_statement_id', $statement->id);

        $this->assertNotNull($row);
        $this->assertTrue(collect($row['allocations'] ?? [])->contains(fn (array $allocation) => ($allocation['tipo'] ?? null) === 'movement'));
    }

    public function test_credit_created_is_present_in_summary_and_detail(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Credito Auditoria']);

        $statement = $this->createBankStatement(70.00, 'Pagamento com credito', '2026-06-16');
        $invoice = $this->createInvoice($user, 50.00, '2026-06-10');

        $this->actingAs($admin)
            ->postJson(route('financeiro.payments.allocate'), [
                'bank_statement_id' => $statement->id,
                'create_credit' => true,
                'allocations' => [
                    ['invoice_id' => $invoice->id, 'amount' => 50.00],
                ],
            ])
            ->assertOk();

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'search' => 'Pagamento com credito',
            ]))
            ->assertOk();

        $summaryCredit = (float) $response->json('summary.total_credito_criado');
        $row = collect($response->json('rows'))->firstWhere('bank_statement_id', $statement->id);

        $this->assertGreaterThan(0, $summaryCredit);
        $this->assertNotNull($row);
        $this->assertGreaterThan(0, (float) data_get($row, 'target_summary.valor_credito_criado', 0));
        $this->assertTrue(collect($row['allocations'] ?? [])->contains(fn (array $allocation) => ($allocation['tipo'] ?? null) === 'credit'));
    }

    public function test_pagination_meta_is_returned_correctly(): void
    {
        $admin = User::factory()->admin()->create();

        for ($index = 1; $index <= 11; $index++) {
            $this->createBankStatement(
                amount: 12.00 + $index,
                description: 'Meta paginacao ' . $index,
                date: sprintf('2026-06-%02d', min($index, 28)),
            );
        }

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'per_page' => 10,
                'page' => 2,
            ]))
            ->assertOk();

        $this->assertSame(2, (int) $response->json('meta.current_page'));
        $this->assertSame(10, (int) $response->json('meta.per_page'));
        $this->assertSame(11, (int) $response->json('meta.total'));
        $this->assertSame(2, (int) $response->json('meta.last_page'));
    }

    public function test_user_without_permissions_cannot_access_audit_endpoint(): void
    {
        UserType::create([
            'codigo' => 'user',
            'nome' => 'user',
            'descricao' => 'Utilizador sem acesso',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);

        $user = User::factory()->create(['perfil' => 'user']);

        $response = $this->actingAs($user)
            ->getJson(route('financeiro.bank-reconciliation-audit.index'));

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_audit_endpoint_does_not_change_payments_allocations_or_statement_status(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->createFinanceUser(['nome_completo' => 'Sem Mutacao']);

        $statement = $this->createBankStatement(33.00, 'Sem mutacao', '2026-06-17');
        $invoice = $this->createInvoice($member, 33.00, '2026-06-10');
        $this->settleInvoiceWithStatement($admin, $invoice, $statement, 33.00);

        $paymentsBefore = (int) DB::table('payments')->count();
        $allocationsBefore = (int) DB::table('payment_allocations')->count();
        $statusBefore = (string) BankStatement::query()->findOrFail($statement->id)->conciliacao_status;

        $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-audit.index', [
                'search' => 'Sem mutacao',
            ]))
            ->assertOk();

        $paymentsAfter = (int) DB::table('payments')->count();
        $allocationsAfter = (int) DB::table('payment_allocations')->count();
        $statusAfter = (string) BankStatement::query()->findOrFail($statement->id)->conciliacao_status;

        $this->assertSame($paymentsBefore, $paymentsAfter);
        $this->assertSame($allocationsBefore, $allocationsAfter);
        $this->assertSame($statusBefore, $statusAfter);
    }

    public function test_csv_export_respects_state_filter(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Export Estado']);

        $reconciled = $this->createBankStatement(55.00, 'Export reconciliado', '2026-06-05');
        $open = $this->createBankStatement(45.00, 'Export aberto', '2026-06-06');

        $invoice = $this->createInvoice($user, 55.00, '2026-06-10');
        $this->settleInvoiceWithStatement($admin, $invoice, $reconciled, 55.00);

        $response = $this->actingAs($admin)
            ->get(route('financeiro.bank-reconciliation-audit.export', [
                'format' => 'csv',
                'estado' => 'conciliado',
            ]))
            ->assertOk();

        $rows = $this->readCsvBody($response);
        $this->assertNotEmpty($rows);
        $this->assertTrue(collect($rows)->every(fn (array $row) => ($row['Estado de conciliacao'] ?? null) === 'conciliado'));
        $this->assertFalse(collect($rows)->contains(fn (array $row) => ($row['Descricao'] ?? null) === $open->descricao));
    }

    public function test_csv_export_respects_date_range_filter(): void
    {
        $admin = User::factory()->admin()->create();

        $inside = $this->createBankStatement(22.00, 'Export data dentro', '2026-06-12');
        $outside = $this->createBankStatement(22.00, 'Export data fora', '2026-05-12');

        $response = $this->actingAs($admin)
            ->get(route('financeiro.bank-reconciliation-audit.export', [
                'format' => 'csv',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk();

        $rows = collect($this->readCsvBody($response));

        $this->assertTrue($rows->contains(fn (array $row) => ($row['Descricao'] ?? null) === $inside->descricao));
        $this->assertFalse($rows->contains(fn (array $row) => ($row['Descricao'] ?? null) === $outside->descricao));
    }

    public function test_csv_export_respects_search_filter(): void
    {
        $admin = User::factory()->admin()->create();

        $target = $this->createBankStatement(31.00, 'Export pesquisa aurora', '2026-06-10', 'EXP-REF-777');
        $this->createBankStatement(31.00, 'Export pesquisa outro', '2026-06-10', 'EXP-REF-111');

        $response = $this->actingAs($admin)
            ->get(route('financeiro.bank-reconciliation-audit.export', [
                'format' => 'csv',
                'search' => 'aurora',
            ]))
            ->assertOk();

        $rows = collect($this->readCsvBody($response));

        $this->assertTrue($rows->contains(fn (array $row) => ($row['Descricao'] ?? null) === $target->descricao));
        $this->assertFalse($rows->contains(fn (array $row) => ($row['Referencia'] ?? null) === 'EXP-REF-111'));
    }

    public function test_csv_export_ignores_pagination_and_exports_all_filtered_rows(): void
    {
        $admin = User::factory()->admin()->create();

        for ($index = 1; $index <= 18; $index++) {
            $this->createBankStatement(
                amount: 20.00 + $index,
                description: 'Export full set ' . $index,
                date: sprintf('2026-06-%02d', min($index, 28)),
            );
        }

        $response = $this->actingAs($admin)
            ->get(route('financeiro.bank-reconciliation-audit.export', [
                'format' => 'csv',
                'search' => 'Export full set',
                'per_page' => 5,
                'page' => 2,
            ]))
            ->assertOk();

        $rows = $this->readCsvBody($response);

        $this->assertCount(18, $rows);
    }

    public function test_csv_export_includes_main_fields_and_csv_headers(): void
    {
        $admin = User::factory()->admin()->create();

        $this->createBankStatement(27.00, 'Export cabecalhos', '2026-06-18', 'EXP-HDR-1');

        $response = $this->actingAs($admin)
            ->get(route('financeiro.bank-reconciliation-audit.export', [
                'format' => 'csv',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = $this->readCsvBody($response);
        $this->assertNotEmpty($rows);

        $firstRow = $rows[0];
        $this->assertArrayHasKey('Data do movimento', $firstRow);
        $this->assertArrayHasKey('Descricao', $firstRow);
        $this->assertArrayHasKey('Referencia', $firstRow);
        $this->assertArrayHasKey('Valor', $firstRow);
        $this->assertArrayHasKey('Estado de conciliacao', $firstRow);
        $this->assertArrayHasKey('Valor alocado', $firstRow);
        $this->assertArrayHasKey('Valor por alocar', $firstRow);
        $this->assertArrayHasKey('Metodo de conciliacao', $firstRow);
        $this->assertArrayHasKey('Alvo / utilizador / familia', $firstRow);
        $this->assertArrayHasKey('Conciliado por', $firstRow);
        $this->assertArrayHasKey('Conciliado em', $firstRow);
        $this->assertArrayHasKey('Mensalidades/Faturas liquidadas', $firstRow);
        $this->assertArrayHasKey('Movimentos liquidados', $firstRow);
        $this->assertArrayHasKey('Credito criado', $firstRow);
        $this->assertArrayHasKey('Documento fiscal emitido', $firstRow);
        $this->assertArrayHasKey('Bloqueado para desconciliar', $firstRow);
        $this->assertArrayHasKey('Historico de desconciliacao / observacao', $firstRow);
    }

    public function test_csv_summary_export_returns_operational_totals(): void
    {
        $admin = User::factory()->admin()->create();

        $this->createBankStatement(40.00, 'Export resumo 1', '2026-06-11');
        $this->createBankStatement(41.00, 'Export resumo 2', '2026-06-12');

        $response = $this->actingAs($admin)
            ->get(route('financeiro.bank-reconciliation-audit.export-summary', [
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-30',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $summaryRows = collect($this->readCsvPairs($response));

        $this->assertNotEmpty($summaryRows);
        $this->assertTrue($summaryRows->contains(fn (array $row) => ($row['Metrica'] ?? null) === 'Total de linhas no filtro'));
        $this->assertTrue($summaryRows->contains(fn (array $row) => ($row['Metrica'] ?? null) === 'Total por conciliar'));
        $this->assertTrue($summaryRows->contains(fn (array $row) => ($row['Metrica'] ?? null) === 'Total alocado'));
        $this->assertTrue($summaryRows->contains(fn (array $row) => ($row['Metrica'] ?? null) === 'Data/hora da exportacao'));
        $this->assertTrue($summaryRows->contains(fn (array $row) => ($row['Metrica'] ?? null) === 'Utilizador que exportou'));
    }

    public function test_user_without_permissions_cannot_export_audit_csv(): void
    {
        UserType::create([
            'codigo' => 'user',
            'nome' => 'user',
            'descricao' => 'Utilizador sem acesso export',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);

        $user = User::factory()->create(['perfil' => 'user']);

        $response = $this->actingAs($user)
            ->get(route('financeiro.bank-reconciliation-audit.export', [
                'format' => 'csv',
            ]));

        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_csv_export_does_not_change_payments_allocations_or_statement_status(): void
    {
        $admin = User::factory()->admin()->create();
        $member = $this->createFinanceUser(['nome_completo' => 'Sem Mutacao Export']);

        $statement = $this->createBankStatement(35.00, 'Sem mutacao export', '2026-06-18');
        $invoice = $this->createInvoice($member, 35.00, '2026-06-10');
        $this->settleInvoiceWithStatement($admin, $invoice, $statement, 35.00);

        $paymentsBefore = (int) DB::table('payments')->count();
        $allocationsBefore = (int) DB::table('payment_allocations')->count();
        $statusBefore = (string) BankStatement::query()->findOrFail($statement->id)->conciliacao_status;

        $this->actingAs($admin)
            ->get(route('financeiro.bank-reconciliation-audit.export', [
                'format' => 'csv',
                'search' => 'Sem mutacao export',
            ]))
            ->assertOk();

        $paymentsAfter = (int) DB::table('payments')->count();
        $allocationsAfter = (int) DB::table('payment_allocations')->count();
        $statusAfter = (string) BankStatement::query()->findOrFail($statement->id)->conciliacao_status;

        $this->assertSame($paymentsBefore, $paymentsAfter);
        $this->assertSame($allocationsBefore, $allocationsAfter);
        $this->assertSame($statusBefore, $statusAfter);
    }

    private function readCsvBody(TestResponse $response): array
    {
        $pairs = $this->readCsv($response);
        if ($pairs === []) {
            return [];
        }

        $headers = array_shift($pairs);
        if (!is_array($headers) || $headers === []) {
            return [];
        }

        $records = [];
        foreach ($pairs as $line) {
            if (!is_array($line)) {
                continue;
            }

            $records[] = array_combine($headers, array_pad($line, count($headers), '')) ?: [];
        }

        return $records;
    }

    private function readCsvPairs(TestResponse $response): array
    {
        $pairs = $this->readCsv($response);
        if ($pairs === []) {
            return [];
        }

        $headers = array_shift($pairs);
        if (!is_array($headers) || count($headers) !== 2) {
            return [];
        }

        $records = [];
        foreach ($pairs as $line) {
            if (!is_array($line)) {
                continue;
            }

            $records[] = [
                $headers[0] => (string) ($line[0] ?? ''),
                $headers[1] => (string) ($line[1] ?? ''),
            ];
        }

        return $records;
    }

    private function readCsv(TestResponse $response): array
    {
        $content = $response->streamedContent();
        if (str_starts_with((string) $content, "\xEF\xBB\xBF")) {
            $content = substr((string) $content, 3);
        }
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $content) ?: []));

        return array_values(array_map(static fn (string $line) => str_getcsv($line, ';'), $lines));
    }

    private function settleInvoiceWithStatement(User $admin, Invoice $invoice, BankStatement $statement, float $amount): void
    {
        $this->actingAs($admin)
            ->postJson(route('financeiro.payments.allocate'), [
                'bank_statement_id' => $statement->id,
                'create_credit' => false,
                'allocations' => [
                    [
                        'invoice_id' => $invoice->id,
                        'amount' => $amount,
                    ],
                ],
            ])
            ->assertOk();
    }

    private function createFinanceUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'nome_completo' => 'Finance User Audit',
            'numero_socio' => ++self::$financeUserNumber,
            'nif' => '123456780',
            'morada' => 'Rua Auditoria 1',
            'codigo_postal' => '1000-120',
            'localidade' => 'Lisboa',
            'email' => 'audit-' . uniqid() . '@example.com',
        ], $overrides));

        $family = Familia::create([
            'nome' => 'Familia ' . ($user->numero_socio ?? 'A'),
            'responsavel_user_id' => $user->id,
            'ativo' => true,
        ]);

        $family->members()->attach($user->id, [
            'papel_na_familia' => 'responsavel',
            'pode_editar' => true,
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
        ]);

        return $user->fresh('families');
    }

    private function createInvoice(User $user, float $amount, string $dueDate): Invoice
    {
        $costCenter = CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-AUDIT-INV'],
            [
                'nome' => 'Centro Auditoria Invoices',
                'tipo' => 'departamento',
                'ativo' => true,
            ],
        );

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => '2026-06-01',
            'data_emissao' => '2026-06-01',
            'data_vencimento' => $dueDate,
            'valor_total' => $amount,
            'estado_pagamento' => 'pendente',
            'numero_recibo' => null,
            'referencia_pagamento' => 'AUD-REF-' . uniqid(),
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        InvoiceItem::create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Mensalidade',
            'quantidade' => 1,
            'valor_unitario' => $amount,
            'imposto_percentual' => 0,
            'total_linha' => $amount,
            'centro_custo_id' => $costCenter->id,
        ]);

        return $invoice;
    }

    private function createMovement(User $user, float $amount): Movement
    {
        $costCenter = CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-AUDIT-MOV'],
            [
                'nome' => 'Centro Auditoria Movimentos',
                'tipo' => 'departamento',
                'ativo' => true,
            ],
        );

        return Movement::create([
            'user_id' => $user->id,
            'nome_manual' => 'Movimento detalhe auditoria',
            'classificacao' => 'receita',
            'categoria' => 'mensalidade',
            'data_emissao' => '2026-06-15',
            'data_vencimento' => '2026-06-15',
            'valor_total' => $amount,
            'estado_pagamento' => 'pendente',
            'estado_conciliacao' => 'nao_conciliado',
            'estado_documental' => 'pendente',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'servico',
            'origem_tipo' => 'manual',
            'observacoes' => 'Movimento detalhe auditoria',
        ]);
    }

    private function createBankStatement(float $amount, string $description, string $date, ?string $reference = null): BankStatement
    {
        return BankStatement::create([
            'conta' => 'PT50-0009',
            'data_movimento' => $date,
            'descricao' => $description,
            'valor' => $amount,
            'saldo' => 1000.00,
            'referencia' => $reference ?? 'AUD-' . str_replace('.', '', number_format($amount, 2, '.', '')) . '-' . uniqid(),
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => $amount,
            'conciliacao_status' => 'unreconciled',
        ]);
    }
}

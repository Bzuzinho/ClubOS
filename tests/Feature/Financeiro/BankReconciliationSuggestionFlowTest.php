<?php

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankReconciliationAlias;
use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\Familia;
use App\Models\FiscalDocumentRequest;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MapaConciliacao;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\BankReconciliationSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankReconciliationSuggestionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_suggestion_for_exact_open_invoice_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 25.00, 'mensalidade', '2026-05-10');
        $statement = $this->createBankStatement(25.00, 'Pagamento John Exact');

        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response->assertOk();

        $matchingSuggestion = collect($response->json('suggestions'))
            ->first(function (array $suggestion) use ($user, $invoice) {
                return ($suggestion['user_id'] ?? null) === $user->id
                    && collect($suggestion['suggested_allocations'] ?? [])->contains(function (array $allocation) use ($invoice) {
                        return ($allocation['invoice_id'] ?? null) === $invoice->id
                            && (float) ($allocation['amount'] ?? 0) === 25.0;
                    });
            });

        $this->assertNotNull($matchingSuggestion);
    }

    public function test_it_generates_suggestion_for_multiple_monthly_invoices_of_same_user(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Maria Combo']);
        $invoiceA = $this->createInvoice($user, 20.00, 'mensalidade', '2026-05-05');
        $invoiceB = $this->createInvoice($user, 30.00, 'mensalidade', '2026-05-10');
        $statement = $this->createBankStatement(50.00, 'Transferencia Maria Combo');

        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response->assertOk();

        $suggestion = $this->generateSuggestion($admin, $statement, [$invoiceA, $invoiceB]);
        $allocations = collect($suggestion->suggested_allocations)->pluck('invoice_id')->all();

        $this->assertEqualsCanonicalizing([$invoiceA->id, $invoiceB->id], $allocations);
    }

    public function test_it_generates_family_suggestion_for_children_when_statement_matches_guardian(): void
    {
        $admin = User::factory()->admin()->create();
        $guardian = $this->createFinanceUser([
            'nome_completo' => 'Ricardo Ferreira',
            'email' => 'ricardo.ferreira@example.com',
        ]);
        $family = $guardian->families->firstOrFail();
        $childA = $this->createFamilyMember($family, [
            'nome_completo' => 'Filho Ferreira A',
            'email' => 'filho-a@example.com',
            'numero_socio' => '5101',
        ]);
        $childB = $this->createFamilyMember($family, [
            'nome_completo' => 'Filho Ferreira B',
            'email' => 'filho-b@example.com',
            'numero_socio' => '5102',
        ]);

        $invoiceA = $this->createInvoice($childA, 40.00, 'mensalidade', '2026-05-05');
        $invoiceB = $this->createInvoice($childB, 40.00, 'mensalidade', '2026-05-05');
        $statement = $this->createBankStatement(80.00, 'Transferencia Ricardo Ferreira');

        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));
        $response->assertOk();

        $matchingSuggestion = collect($response->json('suggestions'))
            ->first(function (array $suggestion) use ($family, $invoiceA, $invoiceB) {
                $allocations = collect($suggestion['suggested_allocations'] ?? [])->pluck('invoice_id')->all();

                return ($suggestion['family_id'] ?? null) === $family->id
                    && collect($allocations)->sort()->values()->all() === collect([$invoiceA->id, $invoiceB->id])->sort()->values()->all();
            });

        $this->assertNotNull($matchingSuggestion);
    }

    public function test_confirmed_alias_increases_suggestion_score(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Alias Match']);
        $this->createInvoice($user, 35.00);
        $statement = $this->createBankStatement(35.00, 'TRF Alias Match');

        $withoutAlias = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk()
            ->json('suggestions.0.score');

        BankReconciliationAlias::create([
            'user_id' => $user->id,
            'family_id' => null,
            'type' => 'description_text',
            'value' => 'Alias Match',
            'normalized_value' => 'ALIAS MATCH',
            'is_confirmed' => true,
            'confidence' => 90,
            'source' => 'manual',
            'match_count' => 2,
        ]);

        $withAliasResponse = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $withAlias = $withAliasResponse->json('suggestions.0.score');

        $this->assertGreaterThanOrEqual($withoutAlias, $withAlias);
        $this->assertContains('confirmed_alias', $withAliasResponse->json('suggestions.0.matched_rules'));
    }

    public function test_it_matches_user_by_name_nif_and_member_number_in_statement_description(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Pedro Signals',
            'numero_socio' => '7001',
            'nif' => '987654321',
        ]);
        $this->createInvoice($user, 40.00);
        $statement = $this->createBankStatement(40.00, 'Pedro Signals socio 7001', 'REF 987654321');

        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response
            ->assertOk()
            ->assertJsonPath('suggestions.0.user_id', $user->id);

        $this->assertContains('matched_name', $response->json('suggestions.0.matched_rules'));
        $this->assertContains('matched_nif', $response->json('suggestions.0.matched_rules'));
        $this->assertContains('matched_member_number', $response->json('suggestions.0.matched_rules'));
    }

    public function test_it_falls_back_to_amount_matching_when_name_query_returns_only_weak_candidates(): void
    {
        $admin = User::factory()->admin()->create();

        User::factory()->create(['nome_completo' => 'Beatriz Silva da Conceicao']);
        User::factory()->create(['nome_completo' => 'Ana Luisa Silva Rodrigues']);
        User::factory()->create(['nome_completo' => 'Beatriz Silva Santos']);

        $user = $this->createFinanceUser([
            'nome_completo' => 'Ines da Silva Guerra Figueiredo',
            'email' => 'ines-' . uniqid() . '@example.com',
        ]);
        $invoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-03-11');
        $statement = $this->createBankStatement(30.00, 'TRF CR INTRAB 264 DE INES DA SILVA GUERRA FIGUEIREDO');

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response->assertOk();

        $matchingSuggestion = collect($response->json('suggestions'))
            ->first(function (array $suggestion) use ($user, $invoice) {
                return ($suggestion['user_id'] ?? null) === $user->id
                    && collect($suggestion['suggested_allocations'] ?? [])->contains(function (array $allocation) use ($invoice) {
                        return ($allocation['invoice_id'] ?? null) === $invoice->id
                            && (float) ($allocation['amount'] ?? 0) === 30.0;
                    });
            });

        $this->assertNotNull($matchingSuggestion);
    }

    public function test_monthly_invoice_from_same_movement_month_gets_higher_score(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Ricardo Mes Score',
            'email' => 'ricardo-mes-score@example.com',
        ]);

        $marchInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-03-10', [
            'data_fatura' => '2026-03-01',
            'data_emissao' => '2026-03-01',
            'mes' => '2026-03',
        ]);
        $januaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-03-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
        ]);
        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-03-12',
            'descricao' => 'TRF Ricardo Mes Score',
            'valor' => 30.00,
            'saldo' => 1000.00,
            'referencia' => 'TRX-3000',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 30.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $service = app(BankReconciliationSuggestionService::class);

        $marchScore = $service->calculateScore($statement, [[
            'invoice' => $marchInvoice->fresh(),
            'open_amount' => 30.00,
            'amount' => 30.00,
        ]], []);
        $januaryScore = $service->calculateScore($statement, [[
            'invoice' => $januaryInvoice->fresh(),
            'open_amount' => 30.00,
            'amount' => 30.00,
        ]], []);

        $this->assertGreaterThan($januaryScore['score'], $marchScore['score']);
        $this->assertContains('matching_invoice_period', $marchScore['matched_rules']);
        $this->assertContains('stale_invoice_period', $januaryScore['matched_rules']);
    }

    public function test_same_month_monthly_fee_ranks_ahead_of_older_monthly_combination(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Carla Prioridade Mes',
            'email' => 'carla-prioridade@example.com',
        ]);

        $januaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
        ]);
        $marchInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-03-10', [
            'data_fatura' => '2026-03-01',
            'data_emissao' => '2026-03-01',
            'mes' => '2026-03',
        ]);
        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-03-12',
            'descricao' => 'TRF Carla Prioridade Mes',
            'valor' => 60.00,
            'saldo' => 1000.00,
            'referencia' => 'TRX-6000',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 60.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $suggestions = collect($response->json('suggestions'));
        $topSuggestionInvoiceIds = collect($suggestions->first()['suggested_allocations'] ?? [])->pluck('invoice_id')->all();

        $this->assertContains($marchInvoice->id, $topSuggestionInvoiceIds);
        $this->assertNotContains($januaryInvoice->id, $topSuggestionInvoiceIds);
    }

    public function test_confirming_suggestion_creates_payment_and_allocations(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 45.00);
        $suggestion = $this->generateSuggestion($admin, $this->createBankStatement(45.00, 'Pagamento ' . $user->nome_completo), [$invoice]);

        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion));

        $response
            ->assertOk()
            ->assertJsonPath('summary.suggestion_confirmed', true);

        $this->assertDatabaseHas('payments', [
            'bank_statement_id' => $suggestion->bank_statement_id,
            'status' => Payment::STATUS_CONFIRMED,
            'source' => Payment::SOURCE_RECONCILIATION,
        ]);
        $this->assertDatabaseHas('payment_allocations', [
            'invoice_id' => $invoice->id,
            'amount' => 45.00,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
        ]);
    }

    public function test_confirming_suggestion_marks_statement_reconciled_when_fully_treated(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 50.00);
        $statement = $this->createBankStatement(50.00, 'Pagamento total ' . $user->nome_completo);
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion))
            ->assertOk();

        $statement->refresh();

        $this->assertTrue($statement->conciliado);
        $this->assertSame('reconciled', $statement->conciliacao_status);
    }

    public function test_confirming_multi_invoice_suggestion_creates_multiple_allocations(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Ana Multi']);
        $invoiceA = $this->createInvoice($user, 15.00, 'mensalidade', '2026-05-05');
        $invoiceB = $this->createInvoice($user, 25.00, 'mensalidade', '2026-05-10');
        $suggestion = $this->generateSuggestion(
            $admin,
            $this->createBankStatement(40.00, 'Transferencia Ana Multi'),
            [$invoiceA, $invoiceB]
        );

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion))
            ->assertOk();

        $this->assertDatabaseCount('payment_allocations', 2);
    }

    public function test_partial_confirmation_keeps_invoice_partial_without_fiscal_request(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 100.00);
        $statement = $this->createBankStatement(40.00, 'Pagamento parcial ' . $user->nome_completo);
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion))
            ->assertOk()
            ->assertJsonPath('summary.has_partial_invoice', true);

        $invoice->refresh();

        $this->assertSame('parcial', $invoice->estado_pagamento);
        $this->assertDatabaseCount('fiscal_document_requests', 0);
    }

    public function test_full_confirmation_creates_fiscal_document_request(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 60.00);
        $suggestion = $this->generateSuggestion(
            $admin,
            $this->createBankStatement(60.00, 'Pagamento fiscal ' . $user->nome_completo),
            [$invoice]
        );

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion))
            ->assertOk();

        $this->assertDatabaseHas('fiscal_document_requests', [
            'invoice_id' => $invoice->id,
            'status' => FiscalDocumentRequest::STATUS_PENDING,
        ]);
    }

    public function test_suggestion_uses_discounted_invoice_total_instead_of_base_amount(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Socio Desconto']);
        $invoice = $this->createInvoiceWithItems($user, [
            ['descricao' => 'Mensalidade', 'valor' => 30.00],
            ['descricao' => 'Desconto/Correcao 10%', 'valor' => -3.00],
        ]);
        $statement = $this->createBankStatement(27.00, 'Pagamento Socio Desconto');

        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response->assertOk();

        $matchingSuggestion = collect($response->json('suggestions'))
            ->first(function (array $suggestion) use ($user, $invoice) {
                return ($suggestion['user_id'] ?? null) === $user->id
                    && collect($suggestion['suggested_allocations'] ?? [])->contains(function (array $allocation) use ($invoice) {
                        return ($allocation['invoice_id'] ?? null) === $invoice->id
                            && (float) ($allocation['amount'] ?? 0) === 27.0;
                    });
            });

        $this->assertNotNull($matchingSuggestion);
    }

    public function test_hidden_future_monthly_invoice_is_excluded_from_reconciliation_suggestions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Futuro Oculto']);
        $visibleInvoice = $this->createInvoice($user, 27.00, 'mensalidade', '2026-05-10');
        $hiddenFutureInvoice = $this->createInvoice($user, 27.00, 'mensalidade', '2026-06-10', [
            'oculta' => true,
            'data_fatura' => '2026-06-01',
            'data_emissao' => '2026-06-01',
        ]);
        $statement = $this->createBankStatement(27.00, 'Pagamento Futuro Oculto');

        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response->assertOk();

        $allocationIds = collect($response->json('suggestions'))
            ->flatMap(fn (array $suggestion) => collect($suggestion['suggested_allocations'] ?? [])->pluck('invoice_id'))
            ->all();

        $this->assertContains($visibleInvoice->id, $allocationIds);
        $this->assertNotContains($hiddenFutureInvoice->id, $allocationIds);
    }

    public function test_confirmation_with_overpayment_can_create_account_credit(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 30.00);
        $statement = $this->createBankStatement(50.00, 'Pagamento com excedente ' . $user->nome_completo);
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion), [
                'create_credit' => true,
            ])
            ->assertOk()
            ->assertJsonPath('summary.created_credit', true);

        $this->assertDatabaseHas('account_credits', [
            'amount' => 20.00,
            'status' => AccountCredit::STATUS_AVAILABLE,
        ]);
    }

    public function test_rejecting_suggestion_changes_status_to_rejected(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 20.00);
        $suggestion = $this->generateSuggestion(
            $admin,
            $this->createBankStatement(20.00, 'Pagamento rejeitado ' . $user->nome_completo),
            [$invoice]
        );

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.reject', $suggestion), [
                'reason' => 'Falso positivo',
            ])
            ->assertOk();

        $suggestion->refresh();

        $this->assertSame(BankReconciliationSuggestion::STATUS_REJECTED, $suggestion->status);
        $this->assertSame('Falso positivo', $suggestion->rejection_reason);
    }

    public function test_it_does_not_confirm_suggestion_when_statement_is_already_reconciled(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 20.00);
        $statement = $this->createBankStatement(20.00, 'Pagamento unico ' . $user->nome_completo);
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion))
            ->assertOk();

        $secondInvoice = $this->createInvoice($user, 10.00, 'mensalidade', '2026-06-10');
        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response
            ->assertOk()
            ->assertJsonPath('generated_count', 0);

        $this->assertDatabaseMissing('bank_reconciliation_suggestions', [
            'bank_statement_id' => $statement->id,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
        ]);
    }

    public function test_it_does_not_create_duplicate_suggestions_for_same_statement_and_allocations(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Duplicados']);
        $invoice = $this->createInvoice($user, 25.00, 'mensalidade', '2026-05-10');
        $statement = $this->createBankStatement(25.00, 'Pagamento Duplicados');

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $this->assertSame(
            1,
            BankReconciliationSuggestion::query()
                ->where('bank_statement_id', $statement->id)
                ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
                ->count()
        );
    }

    public function test_manual_allocation_blocks_amount_above_statement_remaining(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser();
        $invoice = $this->createInvoice($user, 60.00);
        $statement = $this->createBankStatement(50.00, 'Manual bloqueado ' . $user->nome_completo);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.allocate', $statement), [
                'allocations' => [
                    ['invoice_id' => $invoice->id, 'amount' => 60.00],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('allocations');
    }

    public function test_alias_is_learned_after_confirming_suggestion(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Alias Learn']);
        $invoice = $this->createInvoice($user, 28.00);
        $statement = $this->createBankStatement(28.00, 'Alias Learn pagamento', 'ALIAS-LEARN-REF');
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion))
            ->assertOk();

        $this->assertDatabaseHas('bank_reconciliation_aliases', [
            'user_id' => $user->id,
            'type' => 'description_text',
            'source' => 'learned_from_reconciliation',
        ]);
    }

    public function test_user_without_permission_cannot_access_reconciliation_endpoints(): void
    {
        UserType::create([
            'codigo' => 'user',
            'nome' => 'user',
            'descricao' => 'Utilizador sem acesso financeiro',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);

        $user = User::factory()->create(['perfil' => 'user']);
        $statement = $this->createBankStatement(25.00, 'Sem permissao');

        $response = $this->actingAs($user)->getJson(route('financeiro.bank-reconciliation-suggestions.index'));

        $this->assertContains($response->status(), [403, 404]);

        $response = $this->actingAs($user)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $this->assertContains($response->status(), [403, 404]);
    }

    private function generateSuggestion(User $admin, BankStatement $statement, array $invoices): BankReconciliationSuggestion
    {
        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));
        $response->assertOk();

        $suggestions = collect($response->json('suggestions') ?? []);
        $invoiceIds = collect($invoices)->pluck('id')->sort()->values()->all();
        $suggestionPayload = $suggestions
            ->first(function (array $suggestion) use ($invoiceIds) {
                return collect($suggestion['suggested_allocations'] ?? [])
                    ->pluck('invoice_id')
                    ->sort()
                    ->values()
                    ->all() === $invoiceIds;
            }) ?? $suggestions->first();

        $this->assertNotNull(
            $suggestionPayload,
            'Expected at least one reconciliation suggestion. Response: ' . json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        return BankReconciliationSuggestion::query()->findOrFail($suggestionPayload['id']);
    }

    private function createFinanceUser(array $overrides = []): User
    {
        $defaults = [
            'nome_completo' => 'John Exact',
            'numero_socio' => '5001',
            'nif' => '123456789',
            'morada' => 'Rua Financeira 1',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa',
            'email' => 'finance-' . uniqid() . '@example.com',
        ];

        $user = User::factory()->create(array_merge($defaults, $overrides));
        $family = Familia::create([
            'nome' => 'Familia ' . ($user->numero_socio ?? 'X'),
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

    private function createFamilyMember(Familia $family, array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'nome_completo' => 'Membro Familia',
            'numero_socio' => '5900',
            'nif' => '123123123',
            'morada' => 'Rua da Familia 1',
            'codigo_postal' => '1000-200',
            'localidade' => 'Lisboa',
            'email' => 'family-' . uniqid() . '@example.com',
        ], $overrides));

        $family->members()->attach($user->id, [
            'papel_na_familia' => 'educando',
            'pode_editar' => false,
            'pode_ver_financeiro' => true,
            'pode_ver_desportivo' => true,
            'pode_ver_documentos' => true,
            'pode_ver_comunicacoes' => true,
        ]);

        return $user->fresh('families');
    }

    private function createInvoice(User $user, float $amount, string $type = 'mensalidade', string $dueDate = '2026-05-10', array $overrides = []): Invoice
    {
        $costCenter = CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-RECON'],
            [
                'nome' => 'Centro Reconciliacao',
                'tipo' => 'departamento',
                'ativo' => true,
            ],
        );

        $invoice = Invoice::create(array_merge([
            'user_id' => $user->id,
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => $dueDate,
            'valor_total' => $amount,
            'estado_pagamento' => 'pendente',
            'numero_recibo' => null,
            'referencia_pagamento' => 'REF-' . uniqid(),
            'centro_custo_id' => $costCenter->id,
            'tipo' => $type,
            'oculta' => false,
        ], $overrides));

        InvoiceItem::create([
            'fatura_id' => $invoice->id,
            'descricao' => ucfirst($type),
            'quantidade' => 1,
            'valor_unitario' => $amount,
            'imposto_percentual' => 0,
            'total_linha' => $amount,
            'centro_custo_id' => $costCenter->id,
        ]);

        return $invoice;
    }

    private function createInvoiceWithItems(User $user, array $items, string $dueDate = '2026-05-10', string $type = 'mensalidade'): Invoice
    {
        $costCenter = CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-RECON'],
            [
                'nome' => 'Centro Reconciliacao',
                'tipo' => 'departamento',
                'ativo' => true,
            ],
        );

        $total = round(collect($items)->sum('valor'), 2);

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => $dueDate,
            'valor_total' => $total,
            'estado_pagamento' => 'pendente',
            'numero_recibo' => null,
            'referencia_pagamento' => 'REF-' . uniqid(),
            'centro_custo_id' => $costCenter->id,
            'tipo' => $type,
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

        return $invoice;
    }

    private function createBankStatement(float $amount, string $description, ?string $reference = null): BankStatement
    {
        return BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-05',
            'descricao' => $description,
            'valor' => $amount,
            'saldo' => 1000.00,
            'referencia' => $reference ?? ('TRX-' . str_replace('.', '', number_format($amount, 2, '.', ''))),
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => $amount,
            'conciliacao_status' => 'unreconciled',
        ]);
    }
}
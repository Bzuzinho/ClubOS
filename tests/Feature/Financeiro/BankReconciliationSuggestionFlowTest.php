<?php

namespace Tests\Feature\Financeiro;

use App\Models\AccountCredit;
use App\Models\BankReconciliationAlias;
use App\Models\BankReconciliationRepository;
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
use Illuminate\Support\Facades\Schema;
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

    public function test_it_gracefully_skips_repository_lookup_when_repository_table_is_missing(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Repo Missing Table']);
        $invoice = $this->createInvoice($user, 25.00, 'mensalidade', '2026-05-10');
        $statement = $this->createBankStatement(25.00, 'Pagamento Repo Missing Table');

        Schema::drop('bank_reconciliation_repositories');

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

    public function test_it_generates_suggestion_when_guardian_has_legacy_educandos_attribute_values(): void
    {
        $admin = User::factory()->admin()->create();
        $guardian = $this->createFinanceUser([
            'nome_completo' => 'Ricardo Jorge Vitorino Ferreira',
        ]);
        $family = $guardian->families->firstOrFail();
        $child = $this->createFamilyMember($family, [
            'nome_completo' => 'Vania Raquel Leao',
            'numero_socio' => '5302',
            'email' => 'vania-raquel@example.com',
        ]);
        $guardian->educandos()->attach($child->id);
        $guardian->forceFill(['educandos' => [$child->id]])->save();

        $invoice = $this->createInvoice($child, 22.50, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
            'estado_pagamento' => 'vencido',
        ]);
        $statement = $this->createBankStatement(22.50, 'TRF CR INTRAB 189 DE RICARDO JORGE VITORINO FERREIRA');

        $response = $this->actingAs($admin)->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response->assertOk();

        $matchingSuggestion = collect($response->json('suggestions'))
            ->first(function (array $suggestion) use ($family, $invoice) {
                return ($suggestion['family_id'] ?? null) === $family->id
                    && collect($suggestion['suggested_allocations'] ?? [])->contains(function (array $allocation) use ($invoice) {
                        return ($allocation['invoice_id'] ?? null) === $invoice->id;
                    });
            });

        $this->assertNotNull($matchingSuggestion);
    }

    public function test_it_generates_family_suggestion_when_reference_matches_family_name(): void
    {
        $admin = User::factory()->admin()->create();
        $guardian = $this->createFinanceUser([
            'nome_completo' => 'Helena Costa',
            'email' => 'helena.costa@example.com',
            'numero_socio' => '5201',
        ]);
        $family = $guardian->families->firstOrFail();
        $family->update(['nome' => 'Familia Costa']);

        $child = $this->createFamilyMember($family, [
            'nome_completo' => 'Filho Costa',
            'email' => 'filho-costa@example.com',
            'numero_socio' => '5202',
        ]);

        $invoice = $this->createInvoice($child, 35.00, 'mensalidade', '2026-05-05');
        $statement = $this->createBankStatement(35.00, 'TRF MB', 'Pagamento Familia Costa');

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $matchingSuggestion = collect($response->json('suggestions'))
            ->first(function (array $suggestion) use ($family, $invoice) {
                return ($suggestion['family_id'] ?? null) === $family->id
                    && collect($suggestion['suggested_allocations'] ?? [])->contains(function (array $allocation) use ($invoice) {
                        return ($allocation['invoice_id'] ?? null) === $invoice->id;
                    });
            });

        $this->assertNotNull($matchingSuggestion);
    }

    public function test_confirmed_alias_increases_suggestion_score(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Alias Match',
            'numero_socio' => '8801',
            'nif' => '888888880',
        ]);
        $this->createInvoice($user, 35.00);
        $statement = $this->createBankStatement(35.00, 'TRF Alias Match 8801', 'REF 888888880');

        $withoutAlias = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk()
            ->json('suggestions');

        $withoutAliasBestScore = (int) collect($withoutAlias ?? [])
            ->max(fn (array $suggestion) => (int) ($suggestion['score'] ?? 0));

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
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement), [
                'force_regeneration' => true,
            ])
            ->assertOk();

        $withAliasBestScore = (int) collect($withAliasResponse->json('suggestions') ?? [])
            ->max(fn (array $suggestion) => (int) ($suggestion['score'] ?? 0));

        $this->assertGreaterThanOrEqual($withAliasBestScore, $withoutAliasBestScore);
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

    public function test_history_prioritizes_oldest_current_and_overdue_monthly_fees(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Carla Historico Mensal',
            'email' => 'carla-historico@example.com',
        ]);

        $this->createConfirmedPaymentHistory($user, 30.00, '2025-12-10');

        $januaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
        ]);
        $februaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-02-10', [
            'data_fatura' => '2026-02-01',
            'data_emissao' => '2026-02-01',
            'mes' => '2026-02',
        ]);
        $marchInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-03-10', [
            'data_fatura' => '2026-03-01',
            'data_emissao' => '2026-03-01',
            'mes' => '2026-03',
        ]);
        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-03-15',
            'descricao' => 'TRF Carla Historico Mensal',
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

        $this->assertEqualsCanonicalizing([
            $januaryInvoice->id,
            $februaryInvoice->id,
        ], $topSuggestionInvoiceIds);
        $this->assertNotContains($marchInvoice->id, $topSuggestionInvoiceIds);
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
        $this->assertDatabaseHas('bank_reconciliation_repositories', [
            'primary_user_id' => $user->id,
            'family_id' => $user->families->first()?->id,
        ]);
    }

    public function test_repository_match_prefers_sum_of_due_monthly_fees_over_future_monthly_value_match(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Repo Mensalidades',
            'numero_socio' => '7101',
            'email' => 'repo-mensalidades@example.com',
        ]);
        $familyId = $user->families->first()?->id;

        BankReconciliationRepository::create([
            'signature' => $this->makeRepositorySignature('PT50-0001', 'Transferencia Repo Mensalidades', 'REPO-REF-01'),
            'conta' => 'PT50-0001',
            'descricao' => 'Transferencia Repo Mensalidades',
            'referencia' => 'REPO-REF-01',
            'normalized_description' => 'TRANSFERENCIA REPO MENSALIDADES',
            'normalized_reference' => 'REPO REF 01',
            'primary_user_id' => $user->id,
            'family_id' => $familyId,
            'matched_user_ids' => [$user->id],
            'match_count' => 3,
            'last_reconciled_at' => now(),
        ]);

        $januaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
            'estado_pagamento' => 'vencido',
        ]);
        $februaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-02-10', [
            'data_fatura' => '2026-02-01',
            'data_emissao' => '2026-02-01',
            'mes' => '2026-02',
            'estado_pagamento' => 'vencido',
        ]);
        $aprilInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-04-10', [
            'data_fatura' => '2026-04-01',
            'data_emissao' => '2026-04-01',
            'mes' => '2026-04',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-03-15',
            'descricao' => 'Transferencia Repo Mensalidades',
            'valor' => 60.00,
            'saldo' => 1000.00,
            'referencia' => 'REPO-REF-01',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 60.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $topSuggestionInvoiceIds = collect($response->json('suggestions.0.suggested_allocations') ?? [])
            ->pluck('invoice_id')
            ->all();

        $this->assertEqualsCanonicalizing([$januaryInvoice->id, $februaryInvoice->id], $topSuggestionInvoiceIds);
        $this->assertNotContains($aprilInvoice->id, $topSuggestionInvoiceIds);
        $this->assertContains('repository_match', $response->json('suggestions.0.matched_rules'));
    }

    public function test_repository_match_falls_back_to_oldest_due_monthly_fee_when_total_due_does_not_match(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Repo Mais Antiga',
            'numero_socio' => '7102',
            'email' => 'repo-mais-antiga@example.com',
        ]);
        $familyId = $user->families->first()?->id;

        BankReconciliationRepository::create([
            'signature' => $this->makeRepositorySignature('PT50-0001', 'Transferencia Repo Mais Antiga', 'REPO-REF-02'),
            'conta' => 'PT50-0001',
            'descricao' => 'Transferencia Repo Mais Antiga',
            'referencia' => 'REPO-REF-02',
            'normalized_description' => 'TRANSFERENCIA REPO MAIS ANTIGA',
            'normalized_reference' => 'REPO REF 02',
            'primary_user_id' => $user->id,
            'family_id' => $familyId,
            'matched_user_ids' => [$user->id],
            'match_count' => 2,
            'last_reconciled_at' => now(),
        ]);

        $januaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
            'estado_pagamento' => 'vencido',
        ]);
        $this->createInvoice($user, 30.00, 'mensalidade', '2026-02-10', [
            'data_fatura' => '2026-02-01',
            'data_emissao' => '2026-02-01',
            'mes' => '2026-02',
            'estado_pagamento' => 'vencido',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-03-15',
            'descricao' => 'Transferencia Repo Mais Antiga',
            'valor' => 30.00,
            'saldo' => 1000.00,
            'referencia' => 'REPO-REF-02',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 30.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $topSuggestionInvoiceIds = collect($response->json('suggestions.0.suggested_allocations') ?? [])
            ->pluck('invoice_id')
            ->all();

        $this->assertSame([$januaryInvoice->id], $topSuggestionInvoiceIds);
    }

    public function test_reference_month_sequence_covers_open_monthly_invoices_until_reference_month(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Ana Referencia Abril',
            'numero_socio' => '7110',
            'email' => 'ana-referencia-abril@example.com',
        ]);

        $januaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
            'estado_pagamento' => 'vencido',
        ]);
        $februaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-02-10', [
            'data_fatura' => '2026-02-01',
            'data_emissao' => '2026-02-01',
            'mes' => '2026-02',
            'estado_pagamento' => 'vencido',
        ]);
        $marchInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-03-10', [
            'data_fatura' => '2026-03-01',
            'data_emissao' => '2026-03-01',
            'mes' => '2026-03',
            'estado_pagamento' => 'vencido',
        ]);
        $aprilInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-04-10', [
            'data_fatura' => '2026-04-01',
            'data_emissao' => '2026-04-01',
            'mes' => '2026-04',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-15',
            'descricao' => 'Transferencia Ana Referencia Abril',
            'valor' => 120.00,
            'saldo' => 1000.00,
            'referencia' => 'Mensalidade abril 2026',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 120.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $topSuggestionInvoiceIds = collect($response->json('suggestions.0.suggested_allocations') ?? [])
            ->pluck('invoice_id')
            ->all();

        $this->assertEqualsCanonicalizing([
            $januaryInvoice->id,
            $februaryInvoice->id,
            $marchInvoice->id,
            $aprilInvoice->id,
        ], $topSuggestionInvoiceIds);
        $this->assertStringContainsString('abril de 2026', (string) ($response->json('suggestions.0.explanation') ?? ''));
    }

    public function test_reference_month_sequence_partial_coverage_adds_clear_explanation(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Bruno Referencia Parcial',
            'numero_socio' => '7111',
            'email' => 'bruno-referencia-parcial@example.com',
        ]);

        $januaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
            'estado_pagamento' => 'vencido',
        ]);
        $this->createInvoice($user, 30.00, 'mensalidade', '2026-02-10', [
            'data_fatura' => '2026-02-01',
            'data_emissao' => '2026-02-01',
            'mes' => '2026-02',
            'estado_pagamento' => 'vencido',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-15',
            'descricao' => 'Transferencia Bruno Referencia Parcial',
            'valor' => 30.00,
            'saldo' => 1000.00,
            'referencia' => 'Mensalidade abril 2026',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 30.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $topSuggestionAllocations = collect($response->json('suggestions.0.suggested_allocations') ?? []);
        $this->assertSame([$januaryInvoice->id], $topSuggestionAllocations->pluck('invoice_id')->all());
        $this->assertStringContainsString('so cobre 1 mensalidade', (string) ($response->json('suggestions.0.explanation') ?? ''));
    }

    public function test_reference_month_sequence_uses_open_amount_for_partial_invoice_and_two_months(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Carla Referencia Parcial Dois Meses',
            'numero_socio' => '7112',
            'email' => 'carla-referencia-parcial-2@example.com',
        ]);

        $januaryPartial = $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
            'estado_pagamento' => 'parcial',
            'valor_pago' => 10.00,
        ]);
        $februaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-02-10', [
            'data_fatura' => '2026-02-01',
            'data_emissao' => '2026-02-01',
            'mes' => '2026-02',
            'estado_pagamento' => 'vencido',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-03-20',
            'descricao' => 'Transferencia Carla Referencia Parcial Dois Meses',
            'valor' => 50.00,
            'saldo' => 1000.00,
            'referencia' => 'Mensalidade fevereiro 2026',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 50.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $allocations = collect($response->json('suggestions.0.suggested_allocations') ?? []);

        $this->assertSame([$januaryPartial->id, $februaryInvoice->id], $allocations->pluck('invoice_id')->all());
        $this->assertSame(20.0, (float) ($allocations->firstWhere('invoice_id', $januaryPartial->id)['amount'] ?? 0));
        $this->assertSame(30.0, (float) ($allocations->firstWhere('invoice_id', $februaryInvoice->id)['amount'] ?? 0));
    }

    public function test_reference_month_sequence_excludes_future_and_hidden_monthly_invoices(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Duarte Referencia Filtros',
            'numero_socio' => '7113',
            'email' => 'duarte-referencia-filtros@example.com',
        ]);

        $januaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
            'estado_pagamento' => 'vencido',
        ]);
        $februaryInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-02-10', [
            'data_fatura' => '2026-02-01',
            'data_emissao' => '2026-02-01',
            'mes' => '2026-02',
            'estado_pagamento' => 'vencido',
        ]);
        $hiddenMarchInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-03-10', [
            'data_fatura' => '2026-03-01',
            'data_emissao' => '2026-03-01',
            'mes' => '2026-03',
            'estado_pagamento' => 'vencido',
            'oculta' => true,
        ]);
        $futureMayInvoice = $this->createInvoice($user, 30.00, 'mensalidade', '2026-05-10', [
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'mes' => '2026-05',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-04-20',
            'descricao' => 'Transferencia Duarte Referencia Filtros',
            'valor' => 60.00,
            'saldo' => 1000.00,
            'referencia' => 'Mensalidade abril 2026',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 60.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $allocationIds = collect($response->json('suggestions.0.suggested_allocations') ?? [])
            ->pluck('invoice_id')
            ->all();

        $this->assertSame([$januaryInvoice->id, $februaryInvoice->id], $allocationIds);
        $this->assertNotContains($hiddenMarchInvoice->id, $allocationIds);
        $this->assertNotContains($futureMayInvoice->id, $allocationIds);
    }

    public function test_reference_month_sequence_without_safe_identity_does_not_reach_high_confidence(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Sem Identidade Segura',
            'numero_socio' => '7114',
            'email' => 'sem-identidade-segura@example.com',
        ]);

        $this->createInvoice($user, 30.00, 'mensalidade', '2026-01-10', [
            'data_fatura' => '2026-01-01',
            'data_emissao' => '2026-01-01',
            'mes' => '2026-01',
            'estado_pagamento' => 'vencido',
        ]);
        $this->createInvoice($user, 30.00, 'mensalidade', '2026-02-10', [
            'data_fatura' => '2026-02-01',
            'data_emissao' => '2026-02-01',
            'mes' => '2026-02',
            'estado_pagamento' => 'vencido',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-04-20',
            'descricao' => 'TRF MBWAY',
            'valor' => 60.00,
            'saldo' => 1000.00,
            'referencia' => 'Mensalidade abril 2026',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 60.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $topScore = (int) ($response->json('suggestions.0.score') ?? 0);
        $this->assertLessThan(80, $topScore);
    }

    public function test_future_monthly_invoice_without_repository_match_does_not_reach_high_confidence(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Futuro Sem Repositorio',
            'numero_socio' => '7103',
            'email' => 'futuro-sem-repositorio@example.com',
        ]);
        $this->createInvoice($user, 30.00, 'mensalidade', '2026-04-10', [
            'data_fatura' => '2026-04-01',
            'data_emissao' => '2026-04-01',
            'mes' => '2026-04',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-03-15',
            'descricao' => 'Transferencia Futuro Sem Repositorio',
            'valor' => 30.00,
            'saldo' => 1000.00,
            'referencia' => 'FUT-REF-01',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 30.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $topScore = (int) ($response->json('suggestions.0.score') ?? 0);

        $this->assertLessThan(80, $topScore);
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
        $this->createConfirmedPaymentHistory($user, 30.00);
        $invoice = $this->createInvoice($user, 100.00);
        $statement = $this->createBankStatement(40.00, 'Pagamento parcial ' . $user->nome_completo);
        $suggestion = BankReconciliationSuggestion::create([
            'bank_statement_id' => $statement->id,
            'user_id' => $user->id,
            'family_id' => $user->families->first()?->id,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
            'score' => 85,
            'confidence_label' => BankReconciliationSuggestion::CONFIDENCE_HIGH,
            'total_bank_amount' => 40.00,
            'total_allocated_amount' => 40.00,
            'unallocated_amount' => 0,
            'suggested_allocations' => [[
                'invoice_id' => $invoice->id,
                'amount' => 40.00,
                'reason' => 'pagamento parcial manualmente semeado para teste',
            ]],
            'matched_rules' => ['manual_partial_seed'],
            'explanation' => 'Sugestao parcial semeada para validar a confirmacao.',
            'metadata' => [
                'allocation_signature' => $this->makeTestAllocationSignature($invoice->id, 40.00),
                'candidate_invoice_ids' => [$invoice->id],
            ],
        ]);

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
        $this->createConfirmedPaymentHistory($user, 30.00);
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

    public function test_rejected_suggestion_does_not_reappear_in_normal_regeneration(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Rejeicao Persistente']);
        $invoice = $this->createInvoice($user, 20.00);
        $statement = $this->createBankStatement(20.00, 'Pagamento Rejeicao Persistente');
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.reject', $suggestion), [
                'reason' => 'Nao deve voltar a aparecer',
            ])
            ->assertOk();

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $response->assertJsonPath('generated_count', 0);
        $this->assertSame(0, BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $statement->id)
            ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
            ->count());
    }

    public function test_rejected_suggestion_can_only_return_with_force_regeneration(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Rejeicao Forcada']);
        $invoice = $this->createInvoice($user, 22.00);
        $statement = $this->createBankStatement(22.00, 'Pagamento Rejeicao Forcada');
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.reject', $suggestion), [
                'reason' => 'Rejeicao inicial',
            ])
            ->assertOk();

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement), [
                'force_regeneration' => true,
            ])
            ->assertOk();

        $this->assertGreaterThanOrEqual(1, (int) ($response->json('generated_count') ?? 0));
        $this->assertGreaterThanOrEqual(1, BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $statement->id)
            ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
            ->count());
    }

    public function test_rejected_suggestion_cannot_be_confirmed(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Confirmacao Bloqueada']);
        $invoice = $this->createInvoice($user, 24.00);
        $statement = $this->createBankStatement(24.00, 'Pagamento Confirmacao Bloqueada');
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.reject', $suggestion), [
                'reason' => 'Rejeitada antes da confirmacao',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion))
            ->assertStatus(422)
            ->assertJsonValidationErrors('suggestion');
    }

    public function test_confirming_suggestion_remains_blocked_when_statement_is_already_reconciled(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Extrato Conciliado']);
        $invoice = $this->createInvoice($user, 26.00);
        $statement = $this->createBankStatement(26.00, 'Pagamento Extrato Conciliado');
        $suggestion = BankReconciliationSuggestion::create([
            'bank_statement_id' => $statement->id,
            'user_id' => $user->id,
            'family_id' => $user->families->first()?->id,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
            'score' => 90,
            'confidence_label' => BankReconciliationSuggestion::CONFIDENCE_VERY_HIGH,
            'total_bank_amount' => 26.00,
            'total_allocated_amount' => 26.00,
            'unallocated_amount' => 0,
            'suggested_allocations' => [[
                'invoice_id' => $invoice->id,
                'amount' => 26.00,
                'reason' => 'Teste de confirmacao bloqueada',
            ]],
            'matched_rules' => ['manual_seed'],
            'explanation' => 'Sugestao semeada para validar bloqueio por extrato conciliado.',
            'metadata' => [
                'allocation_signature' => $this->makeTestAllocationSignature($invoice->id, 26.00),
                'candidate_invoice_ids' => [$invoice->id],
            ],
        ]);

        $statement->forceFill([
            'conciliado' => true,
            'valor_conciliado' => 26.00,
            'valor_por_conciliar' => 0.00,
            'conciliacao_status' => 'reconciled',
        ])->save();

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.confirm', $suggestion))
            ->assertStatus(422)
            ->assertJsonValidationErrors('bank_statement');
    }

    public function test_alias_without_clear_target_does_not_reach_high_confidence(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Alias Sem Alvo']);
        $invoice = $this->createInvoice($user, 33.00, 'material', '2026-08-10');
        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-05',
            'descricao' => 'Transferencia generica sem pistas suficientes',
            'valor' => 33.00,
            'saldo' => 1000.00,
            'referencia' => 'GEN-33',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 33.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $service = app(BankReconciliationSuggestionService::class);

        $score = $service->calculateScore($statement, [[
            'invoice' => $invoice->fresh(),
            'open_amount' => 33.00,
            'amount' => 33.00,
        ]], [
            'statement_amount' => 33.00,
            'normalized_text' => 'TRANSFERENCIA GENERICA SEM PISTAS SUFICIENTES',
            'history_profile' => ['has_records' => false, 'preferred_origins' => []],
            'alias_match' => true,
            'alias_confirmed' => false,
            'matched_name' => false,
            'matched_nif' => false,
            'matched_member_number' => false,
            'matched_email_or_phone' => false,
            'repository_match' => false,
            'conflict_count' => 0,
        ]);

        $this->assertLessThan(75, $score['score']);
        $this->assertContains('alias_without_clear_target', $score['matched_rules']);
    }

    public function test_suggestion_payload_exposes_score_confidence_and_reasoning(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Contrato UI']);
        $invoice = $this->createInvoice($user, 29.00);
        $statement = $this->createBankStatement(29.00, 'Pagamento Contrato UI');

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $response->assertJsonStructure([
            'generated_count',
            'suggestions' => [[
                'id',
                'score',
                'confidence_label',
                'matched_rules',
                'explanation',
            ]],
        ]);

        $this->assertNotNull($response->json('suggestions.0.score'));
        $this->assertNotNull($response->json('suggestions.0.confidence_label'));
        $this->assertNotEmpty($response->json('suggestions.0.explanation'));
    }

    public function test_low_score_fallback_suggestions_without_history_are_ignored(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Sem Historico Sugestao Fraca']);
        $statement = $this->createBankStatement(40.00, 'Pagamento parcial sem historico');
        $invoice = $this->createInvoice($user, 100.00, 'mensalidade', '2026-05-10');

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement));

        $response
            ->assertOk()
            ->assertJsonPath('generated_count', 0)
            ->assertJsonPath('suggestions', []);

        $this->assertDatabaseMissing('bank_reconciliation_suggestions', [
            'bank_statement_id' => $statement->id,
            'user_id' => $user->id,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
        ]);
    }

    public function test_named_match_below_80_score_is_ignored_and_returns_no_suggestions(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser([
            'nome_completo' => 'Nome Fraco',
            'numero_socio' => '5401',
            'email' => 'nome.fraco@example.com',
        ]);
        $this->createInvoice($user, 30.00, 'mensalidade', '2026-07-10', [
            'data_fatura' => '2026-07-01',
            'data_emissao' => '2026-07-01',
            'mes' => '2026-07',
        ]);

        $statement = BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-05',
            'descricao' => 'Transferencia Nome Fraco',
            'valor' => 30.00,
            'saldo' => 1000.00,
            'referencia' => 'REF-NOME-FRACO',
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => 30.00,
            'conciliacao_status' => 'unreconciled',
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $response
            ->assertJsonPath('generated_count', 0)
            ->assertJsonPath('suggestions', []);
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

    public function test_repeated_generation_reuses_existing_scored_suggestions_without_revalidating(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Reutiliza Score']);
        $invoice = $this->createInvoice($user, 25.00, 'mensalidade', '2026-05-10');
        $statement = $this->createBankStatement(25.00, 'Pagamento Reutiliza Score');

        $firstResponse = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $firstSuggestionId = $firstResponse->json('suggestions.0.id');
        $firstSuggestion = BankReconciliationSuggestion::query()->findOrFail($firstSuggestionId);
        $firstUpdatedAt = $firstSuggestion->updated_at?->copy();

        $this->travel(2)->seconds();

        $secondResponse = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $this->assertSame($firstSuggestionId, $secondResponse->json('suggestions.0.id'));
        $this->assertSame(1, BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $statement->id)
            ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
            ->count());

        $this->assertTrue($firstSuggestion->fresh()->updated_at?->equalTo($firstUpdatedAt));
    }

    public function test_it_revalidates_existing_suggestion_without_score(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Sem Score']);
        $invoice = $this->createInvoice($user, 25.00, 'mensalidade', '2026-05-10');
        $statement = $this->createBankStatement(25.00, 'Pagamento Sem Score');
        $familyId = $user->families->first()?->id;

        $legacySuggestion = BankReconciliationSuggestion::create([
            'bank_statement_id' => $statement->id,
            'user_id' => $user->id,
            'family_id' => $familyId,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
            'score' => 0,
            'confidence_label' => BankReconciliationSuggestion::CONFIDENCE_LOW,
            'total_bank_amount' => 25.00,
            'total_allocated_amount' => 25.00,
            'unallocated_amount' => 0,
            'suggested_allocations' => [[
                'invoice_id' => $invoice->id,
                'amount' => 25.00,
                'reason' => 'sugestao antiga sem score',
            ]],
            'matched_rules' => [],
            'explanation' => 'Sugestao antiga sem score.',
            'metadata' => [
                'allocation_signature' => $this->makeTestAllocationSignature($invoice->id, 25.00),
                'candidate_invoice_ids' => [$invoice->id],
            ],
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk();

        $response->assertJsonPath('suggestions.0.id', $legacySuggestion->id);

        $legacySuggestion->refresh();

        $this->assertGreaterThanOrEqual(80, $legacySuggestion->score);
        $this->assertContains($legacySuggestion->confidence_label, [
            BankReconciliationSuggestion::CONFIDENCE_HIGH,
            BankReconciliationSuggestion::CONFIDENCE_VERY_HIGH,
        ]);
    }

    public function test_batch_generation_ignores_statement_with_existing_scored_suggestion(): void
    {
        $admin = User::factory()->admin()->create();
        $userA = $this->createFinanceUser([
            'nome_completo' => 'Batch Ignora',
            'numero_socio' => '8001',
            'email' => 'batch-ignora@example.com',
        ]);
        $invoiceA = $this->createInvoice($userA, 25.00, 'mensalidade', '2026-05-10');
        $statementA = $this->createBankStatement(25.00, 'Pagamento Batch Ignora');

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statementA))
            ->assertOk();

        $existingSuggestion = BankReconciliationSuggestion::query()
            ->where('bank_statement_id', $statementA->id)
            ->where('status', BankReconciliationSuggestion::STATUS_SUGGESTED)
            ->firstOrFail();
        $existingUpdatedAt = $existingSuggestion->updated_at?->copy();

        $userB = $this->createFinanceUser([
            'nome_completo' => 'Batch Novo',
            'numero_socio' => '8002',
            'email' => 'batch-novo@example.com',
        ]);
        $this->createInvoice($userB, 30.00, 'mensalidade', '2026-05-05');
        $statementB = $this->createBankStatement(30.00, 'Pagamento Batch Novo');

        $this->travel(2)->seconds();

        $response = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.generate'));

        $response
            ->assertOk()
            ->assertJsonPath('generated_count', 1)
            ->assertJsonPath('summary.analyzed_count', 1);

        $this->assertTrue($existingSuggestion->fresh()->updated_at?->equalTo($existingUpdatedAt));
        $this->assertDatabaseHas('bank_reconciliation_suggestions', [
            'bank_statement_id' => $statementB->id,
            'status' => BankReconciliationSuggestion::STATUS_SUGGESTED,
        ]);
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
            'numero_socio' => fake()->unique()->numerify('5###'),
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
            'numero_socio' => fake()->unique()->numerify('59##'),
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

    private function createConfirmedPaymentHistory(User $user, float $amount, string $paymentDate = '2026-04-05'): Payment
    {
        $invoice = $this->createInvoice($user, $amount, 'mensalidade', $paymentDate, [
            'data_fatura' => substr($paymentDate, 0, 8) . '01',
            'data_emissao' => substr($paymentDate, 0, 8) . '01',
            'mes' => substr($paymentDate, 0, 7),
            'estado_pagamento' => 'pendente',
        ]);

        $payment = Payment::create([
            'user_id' => $user->id,
            'family_id' => $user->families->first()?->id,
            'amount' => $amount,
            'allocated_amount' => $amount,
            'unallocated_amount' => 0,
            'payment_date' => $paymentDate,
            'method' => 'transferencia',
            'reference' => 'HIST-' . uniqid(),
            'description' => 'Historico mensalidade',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => $paymentDate,
            'notes' => 'Historico para sugestao',
        ]);

        return $payment;
    }

    private function makeTestAllocationSignature(string $invoiceId, float $amount): string
    {
        return $invoiceId . ':' . number_format($amount, 2, '.', '');
    }

    private function makeRepositorySignature(string $account, string $description, ?string $reference = null): string
    {
        $normalizer = app(\App\Services\Financeiro\BankAliasNormalizer::class);

        return hash('sha256', implode('|', array_values(array_filter([
            trim($account),
            $normalizer->normalize($description),
            $normalizer->normalize($reference),
        ], static fn (?string $value): bool => (string) $value !== ''))));
    }
}
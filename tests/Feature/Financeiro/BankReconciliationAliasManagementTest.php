<?php

namespace Tests\Feature\Financeiro;

use App\Models\BankReconciliationAlias;
use App\Models\BankReconciliationSuggestion;
use App\Models\BankStatement;
use App\Models\CostCenter;
use App\Models\Familia;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\ReconciliationAliasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BankReconciliationAliasManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_alias_index_lists_active_and_inactive_aliases(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Alias Operacional']);

        BankReconciliationAlias::create([
            'user_id' => $user->id,
            'family_id' => null,
            'type' => 'description_text',
            'value' => 'OPERACIONAL ATIVO',
            'normalized_value' => 'OPERACIONAL ATIVO',
            'is_confirmed' => true,
            'confidence' => 90,
            'source' => 'manual',
            'match_count' => 2,
        ]);

        BankReconciliationAlias::create([
            'user_id' => $user->id,
            'family_id' => null,
            'type' => 'description_text',
            'value' => 'OPERACIONAL INATIVO',
            'normalized_value' => 'OPERACIONAL INATIVO',
            'is_confirmed' => false,
            'confidence' => 50,
            'source' => ReconciliationAliasService::DISABLED_SOURCE_PREFIX . 'manual',
            'match_count' => 1,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-aliases.index'))
            ->assertOk();

        $rows = collect($response->json('aliases'));

        $this->assertTrue($rows->contains(fn (array $alias) => ($alias['active'] ?? null) === true));
        $this->assertTrue($rows->contains(fn (array $alias) => ($alias['active'] ?? null) === false));
    }

    public function test_alias_index_supports_server_side_pagination_metadata(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Alias Paginacao']);

        for ($index = 1; $index <= 12; $index++) {
            BankReconciliationAlias::create([
                'user_id' => $user->id,
                'family_id' => null,
                'type' => 'description_text',
                'value' => 'PAGINACAO ' . $index,
                'normalized_value' => 'PAGINACAO ' . $index,
                'is_confirmed' => $index % 2 === 0,
                'confidence' => 50,
                'source' => 'manual',
                'match_count' => 1,
            ]);
        }

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-aliases.index', [
                'per_page' => 5,
                'page' => 2,
            ]))
            ->assertOk();

        $this->assertCount(5, $response->json('aliases'));
        $this->assertSame(2, (int) $response->json('meta.current_page'));
        $this->assertSame(5, (int) $response->json('meta.per_page'));
        $this->assertGreaterThanOrEqual(3, (int) $response->json('meta.last_page'));
        $this->assertSame(12, (int) $response->json('meta.total'));
    }

    public function test_deactivate_alias_prevents_new_suggestion_matching_and_reactivate_restores_it(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Sem Match Direto']);

        $this->createInvoice($user, 40.00);

        $alias = BankReconciliationAlias::create([
            'user_id' => $user->id,
            'family_id' => null,
            'type' => 'description_text',
            'value' => 'QWX TARGET',
            'normalized_value' => 'QWX TARGET',
            'is_confirmed' => true,
            'confidence' => 90,
            'source' => 'manual',
            'match_count' => 3,
        ]);

        $statementWithActiveAlias = $this->createBankStatement(40.00, 'Transferencia QWX TARGET');

        $activeResponse = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statementWithActiveAlias))
            ->assertOk();

        $activeRules = collect($activeResponse->json('suggestions'))
            ->flatMap(fn (array $suggestion) => (array) ($suggestion['matched_rules'] ?? []));

        $this->assertTrue($activeRules->contains(fn (string $rule) => str_contains($rule, 'alias')));

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-aliases.deactivate', $alias))
            ->assertOk()
            ->assertJsonPath('alias.active', false);

        $statementWithInactiveAlias = $this->createBankStatement(40.00, 'Transferencia QWX TARGET');

        $inactiveResponse = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statementWithInactiveAlias))
            ->assertOk();

        $inactiveRules = collect($inactiveResponse->json('suggestions'))
            ->flatMap(fn (array $suggestion) => (array) ($suggestion['matched_rules'] ?? []));

        $this->assertFalse($inactiveRules->contains(fn (string $rule) => str_contains($rule, 'alias')));

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-aliases.reactivate', $alias))
            ->assertOk()
            ->assertJsonPath('alias.active', true);

        $statementAfterReactivation = $this->createBankStatement(40.00, 'Transferencia QWX TARGET');

        $reactivatedResponse = $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statementAfterReactivation))
            ->assertOk();

        $reactivatedRules = collect($reactivatedResponse->json('suggestions'))
            ->flatMap(fn (array $suggestion) => (array) ($suggestion['matched_rules'] ?? []));

        $this->assertTrue($reactivatedRules->contains(fn (string $rule) => str_contains($rule, 'alias')));
    }

    public function test_rejected_suggestions_index_includes_bank_statement_context(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Contexto Rejeicao']);
        $invoice = $this->createInvoice($user, 25.00);
        $statement = $this->createBankStatement(25.00, 'Pagamento Contexto Rejeicao');
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.reject', $suggestion), [
                'reason' => 'Rejeicao para teste',
            ])
            ->assertOk();

        $response = $this->actingAs($admin)
            ->getJson(route('financeiro.bank-reconciliation-suggestions.index', [
                'status' => BankReconciliationSuggestion::STATUS_REJECTED,
                'per_page' => 10,
            ]))
            ->assertOk();

        $entry = collect($response->json('data'))->firstWhere('id', $suggestion->id);

        $this->assertNotNull($entry);
        $this->assertSame($statement->id, $entry['bank_statement_id'] ?? null);
        $this->assertSame('Pagamento Contexto Rejeicao', data_get($entry, 'bank_statement.descricao'));
        $this->assertSame(25.0, (float) data_get($entry, 'bank_statement.valor'));
    }

    public function test_clearing_rejection_allows_normal_regeneration(): void
    {
        $admin = User::factory()->admin()->create();
        $user = $this->createFinanceUser(['nome_completo' => 'Limpar Rejeicao']);
        $invoice = $this->createInvoice($user, 30.00);
        $statement = $this->createBankStatement(30.00, 'Pagamento Limpar Rejeicao');
        $suggestion = $this->generateSuggestion($admin, $statement, [$invoice]);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.reject', $suggestion), [
                'reason' => 'Falso positivo',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk()
            ->assertJsonPath('generated_count', 0);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.clear-rejection', $suggestion), [
                'reason' => 'Pode voltar a sugerir',
            ])
            ->assertOk()
            ->assertJsonPath('rejection_cleared', true);

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-statements.generate-suggestions', $statement))
            ->assertOk()
            ->assertJsonPath('generated_count', 1);
    }

    public function test_cannot_deactivate_or_reactivate_nonexistent_alias(): void
    {
        $admin = User::factory()->admin()->create();
        $missingId = '00000000-0000-0000-0000-000000000001';

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-aliases.deactivate', $missingId))
            ->assertNotFound();

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-aliases.reactivate', $missingId))
            ->assertNotFound();
    }

    public function test_cannot_clear_nonexistent_rejection(): void
    {
        $admin = User::factory()->admin()->create();
        $missingId = '00000000-0000-0000-0000-000000000002';

        $this->actingAs($admin)
            ->postJson(route('financeiro.bank-reconciliation-suggestions.clear-rejection', $missingId), [
                'reason' => 'Nao existe',
            ])
            ->assertNotFound();
    }

    public function test_user_without_permissions_cannot_access_configuracoes_or_alias_management_endpoints(): void
    {
        UserType::create([
            'codigo' => 'user',
            'nome' => 'user',
            'descricao' => 'Utilizador sem acesso',
            'ativo' => true,
            'menu_visibility_configured' => true,
        ]);

        $user = User::factory()->create(['perfil' => 'user']);

        $configResponse = $this->actingAs($user)->get(route('configuracoes'));
        $this->assertContains($configResponse->status(), [403, 404]);

        $aliasesResponse = $this->actingAs($user)->getJson(route('financeiro.bank-aliases.index'));
        $this->assertContains($aliasesResponse->status(), [403, 404]);
    }

    private function generateSuggestion(User $admin, BankStatement $statement, array $invoices): BankReconciliationSuggestion
    {
        $invoiceUser = collect($invoices)
            ->map(fn (Invoice $invoice): ?User => $invoice->user()->with('families:id')->first())
            ->filter()
            ->unique('id')
            ->sole();

        app(ReconciliationAliasService::class)->learnFromConfirmedReconciliation(
            $statement,
            $invoiceUser->id,
            $invoiceUser->families->first()?->id,
            $admin->id,
        );

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

        $this->assertNotNull($suggestionPayload);

        return BankReconciliationSuggestion::query()->findOrFail($suggestionPayload['id']);
    }

    private function createFinanceUser(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'nome_completo' => 'Finance User',
            'numero_socio' => fake()->unique()->numerify('7###'),
            'nif' => '123456789',
            'morada' => 'Rua Financeira 1',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa',
            'email' => 'finance-' . uniqid() . '@example.com',
        ], $overrides));

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

    private function createInvoice(User $user, float $amount): Invoice
    {
        $costCenter = CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-RECON-MGMT'],
            [
                'nome' => 'Centro Reconciliacao Gestao',
                'tipo' => 'departamento',
                'ativo' => true,
            ],
        );

        $invoice = Invoice::create([
            'user_id' => $user->id,
            'data_fatura' => '2026-05-01',
            'data_emissao' => '2026-05-01',
            'data_vencimento' => '2026-05-10',
            'valor_total' => $amount,
            'estado_pagamento' => 'pendente',
            'numero_recibo' => null,
            'referencia_pagamento' => 'REF-' . uniqid(),
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

    private function createBankStatement(float $amount, string $description): BankStatement
    {
        return BankStatement::create([
            'conta' => 'PT50-0001',
            'data_movimento' => '2026-05-05',
            'descricao' => $description,
            'valor' => $amount,
            'saldo' => 1000.00,
            'referencia' => 'TRX-' . str_replace('.', '', number_format($amount, 2, '.', '')) . '-' . uniqid(),
            'conciliado' => false,
            'valor_conciliado' => 0,
            'valor_por_conciliar' => $amount,
            'conciliacao_status' => 'unreconciled',
        ]);
    }
}

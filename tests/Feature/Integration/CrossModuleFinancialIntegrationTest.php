<?php

namespace Tests\Feature\Integration;

use App\Models\Competition;
use App\Models\CompetitionRegistration;
use App\Models\ConvocationGroup;
use App\Models\CostCenter;
use App\Models\DadosFinanceiros;
use App\Models\Event;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\LogisticsRequest;
use App\Models\Movement;
use App\Models\MonthlyFee;
use App\Models\PaymentAllocation;
use App\Models\Product;
use App\Models\Prova;
use App\Models\Sale;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\SponsorshipIntegration;
use App\Models\SponsorshipMoneyItem;
use App\Models\Supplier;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Models\UserType;
use App\Services\Eventos\SyncConvocationGroupFinancialMovementAction;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Financeiro\FinanceDashboardService;
use App\Services\Financeiro\FinanceReportService;
use App\Services\Financeiro\FinancialReportingFactService;
use App\Services\Financeiro\FinancialSettlementService;
use App\Services\Financeiro\LegacySaleAuditService;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use App\Services\Logistica\DeleteSupplierPurchaseAction;
use App\Services\Logistica\RegisterSupplierPurchaseAction;
use App\Services\Logistica\UpdateSupplierPurchaseAction;
use App\Services\Patrocinios\SponsorshipIntegrationService;
use App\Services\Patrocinios\SponsorshipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrossModuleFinancialIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createUserTypeIfMissing('atleta', 'Atleta');
    }

    public function test_cross_module_financial_flows_report_paid_facts_once_with_canonical_semantics(): void
    {
        $admin = User::factory()->admin()->create();
        $monthlyPlan = $this->createMonthlyPlan(40.00);

        $monthlyMember = $this->createEligibleUser($monthlyPlan, [
            'nome_completo' => 'Atleta Mensalidade XFIN9',
            'email' => 'monthly-xfin9@example.test',
            'data_inscricao' => now()->startOfMonth()->toDateString(),
        ]);

        $monthlyInvoice = app(MonthlyFeeGenerationService::class)
            ->generateForUser($monthlyMember, now()->copy()->startOfMonth(), now()->copy()->startOfMonth(), [
                'today' => now()->copy()->startOfMonth(),
            ])
            ->sole();

        $this->assertSame('mensalidade', $monthlyInvoice->tipo);
        $this->assertSame(40.0, (float) $monthlyInvoice->valor_total);

        $monthlySummary = app(CurrentAccountService::class)->summarize(['user_id' => $monthlyMember->id]);
        $this->assertSame(40.0, (float) $monthlySummary['gross_debt']);
        $this->assertSame(40.0, (float) $monthlySummary['net_debt']);
        $this->assertCount(0, $this->paidFacts()->where('source_kind', 'invoice')->where('source_id', (string) $monthlyInvoice->id));

        app(FinancialSettlementService::class)->settleInvoices([
            ['invoice_id' => $monthlyInvoice->id, 'amount' => 15.00],
        ], [
            'amount' => 15.00,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'user_id' => $monthlyMember->id,
        ]);

        $monthlyInvoice->refresh();
        $this->assertSame('parcial', $monthlyInvoice->estado_pagamento);
        $this->assertSame(15.0, (float) $monthlyInvoice->valor_pago);
        $this->assertSame(25.0, (float) $monthlyInvoice->valor_em_aberto);

        $monthlySummary = app(CurrentAccountService::class)->summarize(['user_id' => $monthlyMember->id]);
        $this->assertSame(25.0, (float) $monthlySummary['gross_debt']);
        $this->assertSame(25.0, (float) $monthlySummary['net_debt']);

        app(FinancialSettlementService::class)->settleInvoices([
            ['invoice_id' => $monthlyInvoice->id, 'amount' => 25.00],
        ], [
            'amount' => 25.00,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'user_id' => $monthlyMember->id,
        ]);

        $monthlyInvoice->refresh();
        $this->assertSame('pago', $monthlyInvoice->estado_pagamento);
        $this->assertSame(0.0, (float) $monthlyInvoice->valor_em_aberto);

        $monthlySummary = app(CurrentAccountService::class)->summarize(['user_id' => $monthlyMember->id]);
        $this->assertSame(0.0, (float) $monthlySummary['gross_debt']);
        $this->assertSame(0.0, (float) $monthlySummary['net_debt']);

        $this->assertSingleInvoiceFact($monthlyInvoice, 'receita', 40.00);

        $competitionAthlete = User::factory()->create([
            'nome_completo' => 'Atleta Competicao XFIN9',
            'email' => 'competition-xfin9@example.test',
            'estado' => 'ativo',
        ]);
        $prova = $this->createProvaWithEventFee(27.50);

        $registrationResponse = $this->actingAs($admin)->postJson('/api/desportivo/competition-registrations', [
            'prova_id' => $prova->id,
            'user_id' => $competitionAthlete->id,
            'estado' => 'inscrito',
        ]);

        $registrationId = (string) $registrationResponse->assertCreated()->json('id');
        $registration = CompetitionRegistration::query()->findOrFail($registrationId);
        $competitionInvoice = Invoice::query()->findOrFail($registration->fatura_id);

        $this->assertSame('competition_registration', $competitionInvoice->origem_tipo);
        $this->assertSame((string) $registration->id, (string) $competitionInvoice->origem_id);
        $this->assertSame(1, $competitionInvoice->items()->count());
        $this->assertSame(0, FinancialEntry::query()->where('fatura_id', $competitionInvoice->id)->count());

        $competitionSummary = app(CurrentAccountService::class)->summarize(['user_id' => $competitionAthlete->id]);
        $this->assertSame(27.5, (float) $competitionSummary['gross_debt']);
        $this->assertSame(27.5, (float) $competitionSummary['net_debt']);

        app(FinancialSettlementService::class)->settleInvoices([
            ['invoice_id' => $competitionInvoice->id, 'amount' => 27.50],
        ], [
            'amount' => 27.50,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'user_id' => $competitionAthlete->id,
        ]);

        $this->assertSingleInvoiceFact($competitionInvoice->fresh(), 'receita', 27.50);
        $this->actingAs($admin)
            ->deleteJson('/api/desportivo/competition-registrations/'.$registration->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('competition_registration');
        $this->assertDatabaseHas('competition_registrations', ['id' => $registration->id]);

        $buyer = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
            'nome_completo' => 'Comprador Loja XFIN9',
            'email' => 'store-xfin9@example.test',
        ]);
        $storeProduct = Product::query()->create([
            'codigo' => 'LOJA-XFIN9-001',
            'slug' => 'produto-xfin9',
            'nome' => 'Produto Loja XFIN9',
            'preco' => 30,
            'preco_venda' => 35,
            'stock' => 10,
            'stock_reservado' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
            'track_stock' => true,
        ]);

        $this->actingAs($buyer)->postJson('/api/loja/carrinho/itens', [
            'article_id' => $storeProduct->id,
            'quantidade' => 2,
        ])->assertCreated();

        $storeOrderId = (string) $this->actingAs($buyer)
            ->postJson('/api/loja/carrinho/submeter', [])
            ->assertCreated()
            ->json('encomenda_id');
        $storeInvoice = Invoice::query()
            ->where('origem_tipo', 'store_order')
            ->where('origem_id', $storeOrderId)
            ->firstOrFail();

        $this->assertSame(0, Movement::query()->where('origem_tipo', 'stock')->where('origem_id', $storeOrderId)->count());
        $this->assertSame($buyer->id, $storeInvoice->user_id);
        $this->assertSame(70.0, (float) $storeInvoice->valor_total);
        $this->assertSame(1, $storeInvoice->items()->count());
        $this->assertSame(0, FinancialEntry::query()->where('fatura_id', $storeInvoice->id)->count());

        $this->actingAs($admin)
            ->patchJson('/api/admin/loja/encomendas/'.$storeOrderId.'/estado', ['estado' => 'entregue'])
            ->assertOk();

        $this->assertSame(0, Movement::query()->where('origem_tipo', 'stock')->where('origem_id', $storeOrderId)->count());
        $this->assertSame(0, Sale::query()->count());
        $this->assertSame(0, FinancialEntry::query()->where('fatura_id', $storeInvoice->id)->count());

        app(FinancialSettlementService::class)->settleInvoices([
            ['invoice_id' => $storeInvoice->id, 'amount' => 70.00],
        ], [
            'amount' => 70.00,
            'method' => 'dinheiro',
            'payment_date' => now()->toDateString(),
            'user_id' => $buyer->id,
        ]);

        $storeInvoice->refresh();
        $this->assertSame('pago', $storeInvoice->estado_pagamento);
        $this->assertSame(0.0, (float) $storeInvoice->valor_em_aberto);
        $this->assertSame(1, PaymentAllocation::query()->where('invoice_id', $storeInvoice->id)->confirmed()->count());
        $this->assertSingleInvoiceFact($storeInvoice, 'receita', 70.00);

        [$logisticsAdmin, $logisticsRequest, $logisticsProduct] = $this->createInvoicedRequest();
        $logisticsInvoice = Invoice::query()->findOrFail($logisticsRequest->financial_invoice_id);

        $this->assertSame('logistics_request', $logisticsInvoice->origem_tipo);
        $this->assertSame((string) $logisticsRequest->id, (string) $logisticsInvoice->origem_id);
        $this->assertSame(40.0, (float) $logisticsInvoice->valor_total);

        $originalLogisticsInvoiceId = (string) $logisticsInvoice->id;
        $this->actingAs($logisticsAdmin)
            ->put(route('logistica.requisicoes.update', $logisticsRequest->id), $this->updateLogisticsPayload($logisticsRequest, $logisticsProduct, 3))
            ->assertRedirect(route('logistica.index'));

        $logisticsInvoice = Invoice::query()->findOrFail($logisticsRequest->fresh()->financial_invoice_id);
        $this->assertSame($originalLogisticsInvoiceId, (string) $logisticsInvoice->id);
        $this->assertSame(30.0, (float) $logisticsInvoice->valor_total);
        $this->assertSame(0.0, (float) $logisticsInvoice->valor_pago);
        $this->assertSame(30.0, (float) $logisticsInvoice->valor_em_aberto);
        $this->assertSame(1, $logisticsInvoice->items()->count());

        app(FinancialSettlementService::class)->settleInvoices([
            ['invoice_id' => $logisticsInvoice->id, 'amount' => 30.00],
        ], [
            'amount' => 30.00,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'user_id' => $logisticsRequest->requester_user_id,
        ]);

        $this->assertSingleInvoiceFact($logisticsInvoice->fresh(), 'receita', 30.00);
        $this->actingAs($logisticsAdmin)
            ->put(route('logistica.requisicoes.update', $logisticsRequest->id), $this->updateLogisticsPayload($logisticsRequest->fresh(), $logisticsProduct, 2))
            ->assertStatus(302)
            ->assertSessionHasErrors('request');
        $this->actingAs($logisticsAdmin)
            ->delete(route('logistica.requisicoes.destroy', $logisticsRequest->id))
            ->assertStatus(302)
            ->assertSessionHasErrors('request');

        [$purchase, $supplierProduct, $supplier, $purchaseActor] = $this->createSupplierPurchase();
        $purchaseMovement = Movement::query()->findOrFail($purchase->financial_movement_id);

        $this->assertSame('supplier_purchase', $purchaseMovement->origem_tipo);
        $this->assertSame((string) $purchase->id, (string) $purchaseMovement->origem_id);
        $this->assertSame('despesa', $purchaseMovement->classificacao);
        $this->assertSame(50.0, (float) $purchaseMovement->valor_total);
        $this->assertNull($purchase->financial_entry_id);
        $this->assertSame(0, FinancialEntry::query()->whereIn('origem_tipo', ['stock', 'supplier_purchase'])->where('origem_id', $purchase->id)->count());

        $originalPurchaseMovementId = (string) $purchaseMovement->id;
        app(UpdateSupplierPurchaseAction::class)->execute($purchase->fresh(), $this->buildSupplierPurchaseUpdatePayload($supplier, $supplierProduct), $purchaseActor);

        $purchaseMovement = Movement::query()->findOrFail($purchase->fresh()->financial_movement_id);
        $this->assertSame($originalPurchaseMovementId, (string) $purchaseMovement->id);
        $this->assertSame(33.0, (float) $purchaseMovement->valor_total);
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'supplier_purchase')->where('origem_id', $purchase->id)->count());

        $purchaseSettlement = app(FinancialSettlementService::class)->settleMovement($purchaseMovement, [
            'method' => 'dinheiro',
            'payment_date' => now()->toDateString(),
        ]);

        $purchaseEntry = $purchaseSettlement['financial_entry'];
        $this->assertSame('movement', $purchaseEntry->origem_tipo);
        $this->assertSame((string) $purchaseMovement->id, (string) $purchaseEntry->origem_id);
        $this->assertSame('despesa', $purchaseEntry->tipo);
        $this->assertSingleMovementEntryFact($purchaseMovement->fresh(), $purchaseEntry->fresh(), 'despesa', 33.00);

        try {
            app(UpdateSupplierPurchaseAction::class)->execute($purchase->fresh(), $this->buildSupplierPurchaseUpdatePayload($supplier, $supplierProduct), $purchaseActor);
            $this->fail('Expected supplier purchase update to be blocked after settlement.');
        } catch (ValidationException) {
        }

        try {
            app(DeleteSupplierPurchaseAction::class)->execute($purchase->fresh());
            $this->fail('Expected supplier purchase delete to be blocked after settlement.');
        } catch (ValidationException) {
        }

        [$convocationOwner, $convocationEvent, $convocationAthlete] = $this->seedConvocationBaseEntities();
        $convocationGroup = $this->createConvocationGroup($convocationOwner, $convocationEvent, [$convocationAthlete->id], 10, 2, 1);
        $convocationMovement = Movement::query()->findOrFail($convocationGroup->movimento_id);

        $this->assertSame('convocation_group', $convocationMovement->origem_tipo);
        $this->assertSame((string) $convocationGroup->id, (string) $convocationMovement->origem_id);
        $this->assertSame('despesa', $convocationMovement->classificacao);
        $this->assertGreaterThan(0, (float) $convocationMovement->valor_total);

        $initialConvocationMovementId = (string) $convocationMovement->id;
        $convocationGroup->forceFill([
            'valor_por_salto' => 5,
            'valor_inscricao_unitaria' => 20,
        ])->save();
        app(SyncConvocationGroupFinancialMovementAction::class)->execute($convocationGroup->fresh());

        $convocationMovement = Movement::query()->findOrFail($initialConvocationMovementId);
        $this->assertSame($initialConvocationMovementId, (string) $convocationGroup->fresh()->movimento_id);
        $this->assertGreaterThan(0, (float) $convocationMovement->valor_total);
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'convocation_group')->where('origem_id', $convocationGroup->id)->count());

        $convocationSettlement = app(FinancialSettlementService::class)->settleMovement($convocationMovement, [
            'method' => 'dinheiro',
            'payment_date' => now()->toDateString(),
        ]);

        $convocationEntry = $convocationSettlement['financial_entry'];
        $this->assertSingleMovementEntryFact($convocationMovement->fresh(), $convocationEntry->fresh(), 'despesa', (float) $convocationEntry->valor_pago);

        $this->actingAs($convocationOwner)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => $convocationGroup->id,
                'evento_id' => $convocationEvent->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $convocationOwner->id,
                'atletas_ids' => [$convocationAthlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 99,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 20,
            ]],
        ])->assertStatus(422);

        $this->actingAs($convocationOwner)->putJson('/api/kv/club-convocatorias-grupo', [
            'scope' => 'global',
            'value' => [[
                'id' => $convocationGroup->id,
                'evento_id' => $convocationEvent->id,
                'data_criacao' => now()->toISOString(),
                'criado_por' => $convocationOwner->id,
                'atletas_ids' => [$convocationAthlete->id],
                'tipo_custo' => 'por_salto',
                'valor_por_salto' => 5,
                'valor_por_estafeta' => 1,
                'valor_inscricao_unitaria' => 20,
                'hora_encontro' => '08:15',
                'local_encontro' => 'Piscina A',
                'observacoes' => 'Ajuste administrativo',
            ]],
        ])->assertOk();

        [$sponsorship, $moneyItemA, $moneyItemB] = $this->createSponsorshipWithTwoMoneyItems();
        $movementA = Movement::query()->findOrFail($moneyItemA->financial_movement_id);
        $movementB = Movement::query()->findOrFail($moneyItemB->financial_movement_id);

        $this->assertSame('sponsorship_money_item', $movementA->origem_tipo);
        $this->assertSame((string) $moneyItemA->id, (string) $movementA->origem_id);
        $this->assertSame('sponsorship_money_item', $movementB->origem_tipo);
        $this->assertSame((string) $moneyItemB->id, (string) $movementB->origem_id);
        $this->assertNotSame((string) $movementA->id, (string) $movementB->id);

        $integrationIdBeforeRetry = (string) SponsorshipIntegration::query()
            ->where('sponsorship_id', $sponsorship->id)
            ->where('source_id', $moneyItemA->id)
            ->value('id');

        app(SponsorshipIntegrationService::class)->syncForSponsorship($sponsorship->fresh(['moneyItems', 'goodsItems']));

        $moneyItemA->refresh();
        $this->assertSame((string) $movementA->id, (string) $moneyItemA->financial_movement_id);
        $this->assertSame($integrationIdBeforeRetry, (string) SponsorshipIntegration::query()
            ->where('sponsorship_id', $sponsorship->id)
            ->where('source_id', $moneyItemA->id)
            ->value('id'));
        $this->assertSame(1, Movement::query()->where('origem_tipo', 'sponsorship_money_item')->where('origem_id', $moneyItemA->id)->count());
        $this->assertSame(1, Movement::query()->findOrFail($movementA->id)->items()->count());

        $sponsorshipSettlement = app(FinancialSettlementService::class)->settleMovement($movementA, [
            'method' => 'dinheiro',
            'payment_date' => now()->toDateString(),
        ]);
        $sponsorshipEntry = $sponsorshipSettlement['financial_entry'];
        $this->assertSingleMovementEntryFact($movementA->fresh(), $sponsorshipEntry->fresh(), 'receita', 100.00);

        $facts = $this->paidFacts();
        $this->assertCount(0, $facts->filter(fn (array $fact): bool => ($fact['source_kind'] ?? null) === 'movement' && (string) ($fact['source_id'] ?? '') === (string) $movementB->id));
        $this->assertSame(0, FinancialEntry::query()->where('origem_tipo', 'movement')->where('origem_id', $movementB->id)->count());

        try {
            app(SponsorshipService::class)->delete($sponsorship->fresh());
            $this->fail('Expected sponsorship delete to be blocked after protected settlement.');
        } catch (ValidationException) {
        }

        $this->assertDatabaseHas('sponsorships', ['id' => $sponsorship->id]);
        $this->assertDatabaseHas('sponsorship_money_items', ['id' => $moneyItemA->id]);
        $this->assertDatabaseHas('sponsorship_money_items', ['id' => $moneyItemB->id]);

        $this->assertSame(0, Sale::query()->count());

        $facts = $this->paidFacts();
        $expectedPaidFacts = [
            ['kind' => 'invoice', 'id' => (string) $monthlyInvoice->id, 'type' => 'receita', 'amount' => 40.00],
            ['kind' => 'invoice', 'id' => (string) $competitionInvoice->id, 'type' => 'receita', 'amount' => 27.50],
            ['kind' => 'invoice', 'id' => (string) $logisticsInvoice->id, 'type' => 'receita', 'amount' => 30.00],
            ['kind' => 'invoice', 'id' => (string) $storeInvoice->id, 'type' => 'receita', 'amount' => 70.00],
            ['kind' => 'financial_entry', 'id' => (string) $purchaseEntry->id, 'type' => 'despesa', 'amount' => 33.00],
            ['kind' => 'financial_entry', 'id' => (string) $convocationEntry->id, 'type' => 'despesa', 'amount' => round(abs((float) $convocationEntry->valor_pago), 2)],
            ['kind' => 'financial_entry', 'id' => (string) $sponsorshipEntry->id, 'type' => 'receita', 'amount' => 100.00],
        ];

        foreach ($expectedPaidFacts as $expectedFact) {
            $matched = $facts->where('source_kind', $expectedFact['kind'])->where('source_id', $expectedFact['id']);
            $this->assertCount(1, $matched, 'Expected exactly one paid fact for '.$expectedFact['kind'].' '.$expectedFact['id']);
            $this->assertSame($expectedFact['type'], $matched->first()['type']);
            $this->assertSame($expectedFact['amount'], round((float) $matched->first()['amount'], 2));
        }

        $this->assertCount(count($expectedPaidFacts), $facts);
        $this->assertTrue($facts->every(fn (array $fact): bool => (float) $fact['amount'] > 0));
        $this->assertTrue($facts->every(fn (array $fact): bool => in_array($fact['type'], ['receita', 'despesa'], true)));

        $totalReceitas = round((float) $facts->where('type', 'receita')->sum('amount'), 2);
        $totalDespesas = round((float) $facts->where('type', 'despesa')->sum('amount'), 2);
        $saldo = round($totalReceitas - $totalDespesas, 2);

        $reportSummary = app(FinanceReportService::class)->summary([
            'reference_date' => now()->toDateString(),
        ]);
        $dashboardSummary = app(FinanceDashboardService::class)->build([
            'reference_date' => now()->toDateString(),
        ]);

        $this->assertSame($totalReceitas, round((float) $reportSummary['receitasMes'], 2));
        $this->assertSame($totalDespesas, round((float) $reportSummary['despesasMes'], 2));
        $this->assertSame($saldo, round((float) $reportSummary['saldoAtual'], 2));
        $this->assertSame($totalReceitas, round((float) $dashboardSummary['receitas_mes'], 2));
        $this->assertSame($totalDespesas, round((float) $dashboardSummary['despesas_mes'], 2));
        $this->assertSame($saldo, round((float) $dashboardSummary['total_geral'], 2));

        $finalMonthlySummary = app(CurrentAccountService::class)->summarize(['user_id' => $monthlyMember->id]);
        $this->assertSame(0.0, (float) $finalMonthlySummary['gross_debt']);
        $this->assertSame(0.0, (float) $finalMonthlySummary['available_credit']);
        $this->assertSame(0.0, (float) $finalMonthlySummary['manual_account_balance']);
        $this->assertSame(0.0, (float) $finalMonthlySummary['net_debt']);

        $this->assertSame(0.0, (float) app(CurrentAccountService::class)->summarize(['user_id' => $buyer->id])['net_debt']);
        $this->assertSame(0.0, (float) app(CurrentAccountService::class)->summarize(['user_id' => $logisticsRequest->requester_user_id])['net_debt']);
    }

    public function test_legacy_sale_remains_passive_and_non_blocking(): void
    {
        $product = Product::factory()->create(['stock' => 100]);
        $buyer = User::factory()->create();
        $seller = User::factory()->create();

        Sale::query()->create([
            'produto_id' => $product->id,
            'cliente_id' => $buyer->id,
            'vendedor_id' => $seller->id,
            'quantidade' => 2,
            'preco_unitario' => 35.00,
            'total' => 70.00,
            'data' => now(),
            'metodo_pagamento' => 'dinheiro',
        ]);

        $this->assertSame(100, (int) $product->fresh()->stock);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_items', 0);
        $this->assertDatabaseCount('financial_entries', 0);

        $audit = app(LegacySaleAuditService::class)->audit();

        $this->assertSame(0, (int) ($audit['summary']['critical_count'] ?? 0));
        $this->assertSame(0, (int) ($audit['summary']['parallel_finance_findings_count'] ?? 0));
    }

    private function paidFacts(): Collection
    {
        return app(FinancialReportingFactService::class)->paidFacts(
            now()->copy()->startOfMonth(),
            now()->copy()->endOfMonth(),
        );
    }

    private function assertSingleInvoiceFact(Invoice $invoice, string $type, float $amount): void
    {
        $facts = $this->paidFacts()->where('source_kind', 'invoice')->where('source_id', (string) $invoice->id);

        $this->assertCount(1, $facts);
        $this->assertSame($type, $facts->first()['type']);
        $this->assertSame(round($amount, 2), round((float) $facts->first()['amount'], 2));
        $this->assertSame(0, $this->paidFacts()->where('source_kind', 'financial_entry')->filter(fn (array $fact): bool => ($fact['origem_tipo'] ?? null) === ($invoice->origem_tipo ?? null))->count());
    }

    private function assertSingleMovementEntryFact(Movement $movement, FinancialEntry $entry, string $type, float $amount): void
    {
        $facts = $this->paidFacts();

        $entryFacts = $facts->where('source_kind', 'financial_entry')->where('source_id', (string) $entry->id);
        $movementFacts = $facts->where('source_kind', 'movement')->where('source_id', (string) $movement->id);

        $this->assertCount(1, $entryFacts);
        $this->assertCount(0, $movementFacts);
        $this->assertSame($type, $entryFacts->first()['type']);
        $this->assertSame(round($amount, 2), round((float) $entryFacts->first()['amount'], 2));
    }

    private function createMonthlyPlan(float $amount = 40.00): MonthlyFee
    {
        return MonthlyFee::query()->create([
            'designacao' => 'Mensalidade XFIN9',
            'valor' => $amount,
            'ativo' => true,
        ]);
    }

    private function createEligibleUser(MonthlyFee $plan, array $overrides = [], array $financeOverrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'nome_completo' => 'Utilizador Elegivel XFIN9',
            'email' => 'eligible-'.uniqid('', true).'@example.test',
            'estado' => 'ativo',
            'data_inscricao' => now()->startOfMonth()->toDateString(),
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ], $overrides));

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ] + $financeOverrides);

        $user->userTypes()->sync([$this->findUserTypeId('atleta')]);

        return $user->fresh('dadosFinanceiros');
    }

    private function createUserTypeIfMissing(string $codigo, string $nome): void
    {
        if (UserType::query()->where('codigo', $codigo)->exists()) {
            return;
        }

        UserType::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'descricao' => $nome,
            'ativo' => true,
        ]);
    }

    private function findUserTypeId(string $codigo): string
    {
        return (string) UserType::query()->where('codigo', $codigo)->value('id');
    }

    private function createProvaWithEventFee(?float $eventFee): Prova
    {
        $creator = User::factory()->create();

        $event = Event::query()->create([
            'titulo' => 'Meeting XFIN9',
            'descricao' => 'Evento teste XFIN9',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'prova',
            'taxa_inscricao' => $eventFee,
            'estado' => 'agendado',
            'criado_por' => $creator->id,
        ]);

        $competition = Competition::query()->create([
            'nome' => 'Competicao XFIN9',
            'local' => 'Piscina Municipal',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->addDay()->toDateString(),
            'tipo' => 'natacao',
            'evento_id' => $event->id,
        ]);

        if ($eventFee !== null) {
            \App\Models\CompetitionFinancePolicy::query()->updateOrCreate(
                [
                    'club_id' => (string) $competition->club_id,
                    'competition_id' => (string) $competition->id,
                ],
                [
                    'payer_mode' => 'athlete',
                    'charge_mode' => 'per_race',
                    'per_race_amount' => $eventFee,
                    'active' => true,
                ],
            );
        }

        return Prova::query()->create([
            'competicao_id' => $competition->id,
            'estilo' => 'LIVRE',
            'distancia_m' => 100,
            'genero' => 'M',
            'ordem_prova' => 1,
        ]);
    }

    /**
     * @return array{0:User,1:LogisticsRequest,2:Product}
     */
    private function createInvoicedRequest(): array
    {
        $admin = User::factory()->admin()->create();
        $requester = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Requisitante XFIN9',
            'email' => 'logistics-requester-xfin9@example.test',
        ]);

        $costCenter = CostCenter::query()->create([
            'codigo' => 'CC-XFIN9-LOG',
            'nome' => 'Centro XFIN9 Logistica',
            'ativo' => true,
        ]);

        $requester->centrosCusto()->attach($costCenter->id, [
            'id' => (string) Str::uuid(),
            'peso' => 100,
        ]);

        $product = Product::query()->create([
            'codigo' => 'ART-XFIN9-LOG',
            'nome' => 'Produto XFIN9 Logistica',
            'categoria' => 'Material',
            'preco' => 10,
            'stock' => 50,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'ativo' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('logistica.requisicoes.store'), [
                'requester_user_id' => $requester->id,
                'requester_name_snapshot' => $requester->nome_completo,
                'requester_area' => 'Natacao',
                'requester_type' => 'Atleta',
                'status' => 'pending',
                'items' => [[
                    'article_id' => $product->id,
                    'quantity' => 4,
                    'unit_price' => 10,
                ]],
            ])
            ->assertRedirect(route('logistica.index'));

        $request = LogisticsRequest::query()->latest()->firstOrFail();

        $this->actingAs($admin)->post(route('logistica.requisicoes.approve', $request->id))->assertRedirect(route('logistica.index'));
        $this->actingAs($admin)->post(route('logistica.requisicoes.invoice', $request->id))->assertRedirect(route('logistica.index'));

        return [$admin, $request->fresh(), $product];
    }

    /**
     * @return array<string,mixed>
     */
    private function updateLogisticsPayload(LogisticsRequest $request, Product $product, int $quantity): array
    {
        return [
            'requester_user_id' => $request->requester_user_id,
            'requester_name_snapshot' => $request->requester_name_snapshot,
            'requester_area' => $request->requester_area,
            'requester_type' => $request->requester_type,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => $quantity,
                'unit_price' => 10,
            ]],
        ];
    }

    /**
     * @return array{0:SupplierPurchase,1:Product,2:Supplier,3:User}
     */
    private function createSupplierPurchase(): array
    {
        $actor = User::factory()->create();

        $supplier = Supplier::query()->create([
            'nome' => 'Fornecedor XFIN9',
            'nif' => '509999990',
            'email' => 'supplier-xfin9@example.test',
            'telefone' => '912345678',
            'categoria' => 'Equipamento',
            'ativo' => true,
        ]);

        $product = Product::query()->create([
            'codigo' => 'SP-XFIN9-001',
            'nome' => 'Material Fornecedor XFIN9',
            'categoria' => 'Equipamento',
            'preco' => 20,
            'stock' => 10,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'supplier_id' => $supplier->id,
            'ativo' => true,
        ]);

        $purchase = app(RegisterSupplierPurchaseAction::class)->execute([
            'supplier_id' => $supplier->id,
            'invoice_reference' => 'SUP-XFIN9-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'centro_custo_id' => null,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => 5,
                'unit_cost' => 10,
            ]],
        ], $actor);

        return [$purchase->fresh(), $product->fresh(), $supplier, $actor];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSupplierPurchaseUpdatePayload(Supplier $supplier, Product $product): array
    {
        return [
            'supplier_id' => $supplier->id,
            'invoice_reference' => 'SUP-XFIN9-UPD-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(3)->toDateString(),
            'centro_custo_id' => null,
            'items' => [[
                'article_id' => $product->id,
                'quantity' => 3,
                'unit_cost' => 11,
            ]],
        ];
    }

    /**
     * @return array{0:User,1:Event,2:User}
     */
    private function seedConvocationBaseEntities(): array
    {
        $user = User::factory()->admin()->create();
        $athlete = User::factory()->athlete()->create();

        $event = Event::query()->create([
            'id' => (string) Str::uuid(),
            'titulo' => 'Evento Convocatoria XFIN9',
            'descricao' => 'Teste XFIN9',
            'data_inicio' => now()->toDateString(),
            'data_fim' => now()->toDateString(),
            'tipo' => 'prova',
            'visibilidade' => 'publico',
            'transporte_necessario' => false,
            'estado' => 'rascunho',
            'criado_por' => $user->id,
            'recorrente' => false,
            'taxa_inscricao' => 5,
            'custo_inscricao_por_prova' => 1,
            'custo_inscricao_por_salto' => 1,
            'custo_inscricao_estafeta' => 1,
        ]);

        return [$user, $event, $athlete];
    }

    private function createConvocationGroup(User $user, Event $event, array $athleteIds, float $base, float $perJump, float $perRelay): ConvocationGroup
    {
        $group = ConvocationGroup::query()->create([
            'id' => (string) Str::uuid(),
            'evento_id' => $event->id,
            'data_criacao' => now(),
            'criado_por' => $user->id,
            'atletas_ids' => $athleteIds,
            'tipo_custo' => 'por_salto',
            'valor_por_salto' => $perJump,
            'valor_por_estafeta' => $perRelay,
            'valor_inscricao_unitaria' => $base,
        ]);

        app(SyncConvocationGroupFinancialMovementAction::class)->execute($group);

        return $group->fresh();
    }

    /**
     * @return array{0:Sponsorship,1:SponsorshipMoneyItem,2:SponsorshipMoneyItem}
     */
    private function createSponsorshipWithTwoMoneyItems(): array
    {
        $admin = User::factory()->admin()->create();
        $suffix = substr((string) Str::uuid(), 0, 8);

        $sponsor = Sponsor::query()->create([
            'nome' => 'Sponsor XFIN9 '.$suffix,
            'descricao' => 'Sponsor testes XFIN9',
            'tipo' => 'principal',
            'contacto' => '912345'.$suffix,
            'email' => 'sponsor-'.$suffix.'@example.test',
            'website' => 'https://example.test/'.$suffix,
            'valor_anual' => 2500,
            'data_inicio' => now()->toDateString(),
            'estado' => 'ativo',
        ]);

        $costCenter = CostCenter::query()->create([
            'codigo' => 'CC-SP-'.$suffix,
            'nome' => 'Patrocinios '.$suffix,
            'tipo' => 'operacional',
            'descricao' => 'Centro de custo XFIN9 patrocinio',
            'orcamento' => 5000,
            'ativo' => true,
        ]);

        $result = app(SponsorshipService::class)->create([
            'sponsor_id' => $sponsor->id,
            'supplier_id' => null,
            'type' => 'mixed',
            'title' => 'Patrocinio XFIN9',
            'description' => 'Patrocinio para testes XFIN9',
            'periodicity' => 'pontual',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'cost_center_id' => $costCenter->id,
            'status' => 'ativo',
            'notes' => 'Teste automatizado XFIN9',
            'money_items' => [[
                'description' => 'Tranche A',
                'amount' => 100,
                'expected_date' => now()->toDateString(),
            ], [
                'description' => 'Tranche B',
                'amount' => 200,
                'expected_date' => now()->addDay()->toDateString(),
            ]],
            'goods_items' => [],
        ], $admin);

        $sponsorship = $result['sponsorship']->fresh(['moneyItems']);

        return [$sponsorship, $sponsorship->moneyItems[0], $sponsorship->moneyItems[1]];
    }
}

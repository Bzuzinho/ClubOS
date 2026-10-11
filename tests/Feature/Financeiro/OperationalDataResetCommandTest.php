<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MonthlyFee;
use App\Models\Movement;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Financeiro\OperationalDataResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OperationalDataResetCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        File::delete(app(OperationalDataResetService::class)->markerPath());
    }

    protected function tearDown(): void
    {
        File::delete(app(OperationalDataResetService::class)->markerPath());
        parent::tearDown();
    }

    public function test_dry_run_does_not_change_data(): void
    {
        $user = User::factory()->create();
        $fee = MonthlyFee::query()->create([
            'designacao' => 'Mensalidade teste',
            'valor' => 30,
            'ativo' => true,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $fee->id,
            'conta_corrente_manual' => 25,
        ]);

        $invoice = $this->invoice($user);

        $this->artisan('finance:reset-operational-data', ['--json' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id]);
        $this->assertDatabaseHas('dados_financeiros', [
            'user_id' => $user->id,
            'mensalidade_id' => $fee->id,
            'conta_corrente_manual' => 25,
        ]);
    }

    public function test_execute_resets_operational_domains_and_preserves_member_configuration(): void
    {
        $user = User::factory()->create();
        $fee = MonthlyFee::query()->create([
            'designacao' => 'Mensalidade real',
            'valor' => 35,
            'ativo' => true,
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $fee->id,
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'discount_reason' => 'Configuração a preservar',
            'conta_corrente_manual' => 42.50,
        ]);

        $invoice = $this->invoice($user);
        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Linha fictícia',
            'valor_unitario' => 35,
            'quantidade' => 1,
            'imposto_percentual' => 0,
            'total_linha' => 35,
        ]);

        Movement::query()->create([
            'user_id' => $user->id,
            'classificacao' => 'despesa',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->toDateString(),
            'valor_total' => 12,
            'estado_pagamento' => 'pendente',
            'tipo' => 'manual',
        ]);

        DB::table('bank_statements')->insert([
            'id' => (string) Str::uuid(),
            'conta' => 'TEST',
            'data_movimento' => now()->toDateString(),
            'descricao' => 'Movimento fictício',
            'valor' => 35,
            'conciliado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $competitionEventId = (string) Str::uuid();
        $unrelatedEventId = (string) Str::uuid();

        foreach ([
            [$competitionEventId, 'Competição fictícia'],
            [$unrelatedEventId, 'Evento real a preservar'],
        ] as [$eventId, $title]) {
            DB::table('events')->insert([
                'id' => $eventId,
                'titulo' => $title,
                'descricao' => $title,
                'data_inicio' => now()->toDateString(),
                'tipo' => 'evento_interno',
                'visibilidade' => 'publico',
                'estado' => 'agendado',
                'criado_por' => $user->id,
                'recorrente' => false,
                'transporte_necessario' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $competitionId = (string) Str::uuid();
        DB::table('competitions')->insert([
            'id' => $competitionId,
            'club_id' => 'bscn',
            'nome' => 'Competição fictícia',
            'local' => 'Teste',
            'data_inicio' => now()->toDateString(),
            'tipo' => 'prova',
            'status' => 'scheduled',
            'evento_id' => $competitionEventId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('competition_event_projections')->insert([
            'id' => (string) Str::uuid(),
            'club_id' => 'bscn',
            'competition_id' => $competitionId,
            'event_id' => $competitionEventId,
            'legacy_event_id' => $competitionEventId,
            'status' => 'linked',
            'projected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $provaId = (string) Str::uuid();
        DB::table('provas')->insert([
            'id' => $provaId,
            'competicao_id' => $competitionId,
            'estilo' => 'Livre',
            'distancia_m' => 100,
            'genero' => 'M',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('competition_registrations')->insert([
            'id' => (string) Str::uuid(),
            'prova_id' => $provaId,
            'user_id' => $user->id,
            'estado' => 'inscrito',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('results')->insert([
            'id' => (string) Str::uuid(),
            'prova_id' => $provaId,
            'user_id' => $user->id,
            'tempo_oficial' => 65.20,
            'desclassificado' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::query()->create([
            'nome' => 'Produto preservado',
            'codigo' => 'RESET-TEST',
            'preco' => 10,
            'preco_venda' => 12,
            'ultimo_custo' => 8,
            'stock' => 13,
            'stock_reservado' => 0,
            'stock_minimo' => 0,
            'ativo' => true,
            'visible_in_store' => true,
            'allow_sale' => true,
            'allow_request' => true,
            'allow_loan' => false,
            'track_stock' => true,
        ]);

        StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'adjustment',
            'quantity' => 10,
            'reference_type' => 'ledger_opening_snapshot',
            'notes' => 'Stock base preservado',
        ]);
        StockMovement::query()->create([
            'article_id' => $product->id,
            'movement_type' => 'entry',
            'quantity' => 3,
            'reference_type' => 'supplier_purchase',
            'reference_id' => (string) Str::uuid(),
            'notes' => 'Compra fictícia a remover',
        ]);

        $this->artisan('finance:reset-operational-data', [
            '--execute' => true,
            '--acknowledge' => OperationalDataResetService::ACKNOWLEDGEMENT,
            '--json' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('monthly_fees', ['id' => $fee->id]);
        $this->assertDatabaseHas('dados_financeiros', [
            'user_id' => $user->id,
            'mensalidade_id' => $fee->id,
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'conta_corrente_manual' => 0,
        ]);

        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('movements', 0);
        $this->assertDatabaseCount('bank_statements', 0);
        $this->assertDatabaseCount('competitions', 0);
        $this->assertDatabaseCount('competition_registrations', 0);
        $this->assertDatabaseCount('results', 0);
        $this->assertDatabaseMissing('events', ['id' => $competitionEventId]);
        $this->assertDatabaseHas('events', ['id' => $unrelatedEventId]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 10,
            'stock_reservado' => 0,
            'ultimo_custo' => null,
        ]);
        $this->assertDatabaseCount('stock_movements', 1);

        $this->assertFileExists(app(OperationalDataResetService::class)->markerPath());
    }

    private function invoice(User $user): Invoice
    {
        return Invoice::query()->create([
            'user_id' => $user->id,
            'data_fatura' => now()->toDateString(),
            'mes' => now()->format('Y-m'),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 35,
            'valor_pago' => 0,
            'valor_em_aberto' => 35,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee',
        ]);
    }
}

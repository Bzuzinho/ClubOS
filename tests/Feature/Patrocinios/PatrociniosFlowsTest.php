<?php

namespace Tests\Feature\Patrocinios;

use App\Models\CostCenter;
use App\Models\Movement;
use App\Models\Product;
use App\Models\Sponsor;
use App\Models\Sponsorship;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatrociniosFlowsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_sponsorship_generates_financial_movement_and_stock_entry(): void
    {
        $admin = User::factory()->admin()->create();

        $supplier = Supplier::create([
            'nome' => 'Fornecedor Patrocinios SA',
            'nif' => '509999991',
            'email' => 'patrocinios@example.com',
            'telefone' => '912345679',
            'categoria' => 'Patrocinios',
            'ativo' => true,
        ]);

        $costCenter = CostCenter::create([
            'codigo' => 'CC-PAT-01',
            'nome' => 'Patrocinios Institucionais',
            'tipo' => 'operacional',
            'descricao' => 'Centro de custo para testes de patrocinio',
            'orcamento' => 5000,
            'ativo' => true,
        ]);

        $product = Product::create([
            'codigo' => 'ART-PAT-01',
            'nome' => 'T-Shirt Oficial',
            'categoria' => 'Merchandising',
            'preco' => 15.00,
            'stock' => 3,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'supplier_id' => $supplier->id,
            'ativo' => true,
        ]);

        $sponsor = Sponsor::create([
            'nome' => 'Empresa Exemplo',
            'descricao' => 'Patrocinador institucional para testes',
            'tipo' => 'principal',
            'contacto' => '219999999',
            'email' => 'empresa@example.com',
            'website' => 'https://empresa.example.com',
            'valor_anual' => 2500.00,
            'data_inicio' => now()->toDateString(),
            'estado' => 'ativo',
        ]);

        $response = $this->actingAs($admin)->post(route('patrocinios.store'), [
            'sponsor_id' => $sponsor->id,
            'supplier_id' => $supplier->id,
            'type' => 'mixed',
            'title' => 'Patrocinio época 2026',
            'description' => 'Apoio financeiro e em merchandising',
            'periodicity' => 'mensal',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'cost_center_id' => $costCenter->id,
            'status' => 'ativo',
            'notes' => 'Criado em teste automatizado',
            'money_items' => [
                [
                    'description' => 'Tranche inicial',
                    'amount' => 1200.50,
                    'expected_date' => now()->toDateString(),
                ],
            ],
            'goods_items' => [
                [
                    'item_name' => 'T-Shirt Oficial',
                    'item_id' => $product->id,
                    'category' => 'Merchandising',
                    'quantity' => 4,
                    'unit_value' => 15.00,
                    'total_value' => 60.00,
                ],
            ],
        ]);

        $response->assertRedirect(route('patrocinios.index'));

        $sponsorship = Sponsorship::query()
            ->with(['moneyItems', 'goodsItems', 'integrations'])
            ->firstOrFail();
        $moneyItem = $sponsorship->moneyItems->firstOrFail();

        $this->assertSame('Empresa Exemplo', $sponsorship->sponsor_name);
        $this->assertSame($sponsor->id, $sponsorship->sponsor_id);
        $this->assertSame($supplier->id, $sponsorship->supplier_id);
        $this->assertSame($costCenter->id, $sponsorship->cost_center_id);
        $this->assertCount(1, $sponsorship->moneyItems);
        $this->assertCount(1, $sponsorship->goodsItems);
        $this->assertCount(2, $sponsorship->integrations);

        $movement = Movement::query()->firstOrFail();

        $this->assertSame('sponsorship_money_item', $movement->origem_tipo);
        $this->assertSame($moneyItem->id, $movement->origem_id);
        $this->assertSame('receita', $movement->classificacao);
        $this->assertSame('1200.50', (string) $movement->valor_total);

        $this->assertDatabaseHas('movement_items', [
            'movimento_id' => $movement->id,
            'descricao' => 'Tranche inicial',
            'total_linha' => 1200.50,
            'centro_custo_id' => $costCenter->id,
        ]);

        $this->assertDatabaseHas('stock_movements', [
            'article_id' => $product->id,
            'movement_type' => 'entry',
            'reference_type' => 'sponsorship_goods_item',
            'quantity' => 4,
        ]);

        $this->assertDatabaseHas('sponsorship_integrations', [
            'sponsorship_id' => $sponsorship->id,
            'integration_type' => 'financial',
            'status' => 'generated',
            'target_module' => 'financeiro',
        ]);

        $this->assertDatabaseHas('sponsorship_integrations', [
            'sponsorship_id' => $sponsorship->id,
            'integration_type' => 'stock',
            'status' => 'generated',
            'target_module' => 'logistica',
        ]);

        $product->refresh();
        $this->assertSame(7, (int) $product->stock);
    }

    public function test_deleting_sponsorship_removes_integrations_financial_records_and_reverts_stock(): void
    {
        $admin = User::factory()->admin()->create();

        $supplier = Supplier::create([
            'nome' => 'Fornecedor Patrocinios SA',
            'nif' => '509999991',
            'email' => 'patrocinios@example.com',
            'telefone' => '912345679',
            'categoria' => 'Patrocinios',
            'ativo' => true,
        ]);

        $costCenter = CostCenter::create([
            'codigo' => 'CC-PAT-01',
            'nome' => 'Patrocinios Institucionais',
            'tipo' => 'operacional',
            'descricao' => 'Centro de custo para testes de patrocinio',
            'orcamento' => 5000,
            'ativo' => true,
        ]);

        $product = Product::create([
            'codigo' => 'ART-PAT-01',
            'nome' => 'T-Shirt Oficial',
            'categoria' => 'Merchandising',
            'preco' => 15.00,
            'stock' => 3,
            'stock_reservado' => 0,
            'stock_minimo' => 1,
            'supplier_id' => $supplier->id,
            'ativo' => true,
        ]);

        $sponsor = Sponsor::create([
            'nome' => 'Empresa Exemplo',
            'descricao' => 'Patrocinador institucional para testes',
            'tipo' => 'principal',
            'contacto' => '219999999',
            'email' => 'empresa@example.com',
            'website' => 'https://empresa.example.com',
            'valor_anual' => 2500.00,
            'data_inicio' => now()->toDateString(),
            'estado' => 'ativo',
        ]);

        $this->actingAs($admin)->post(route('patrocinios.store'), [
            'sponsor_id' => $sponsor->id,
            'supplier_id' => $supplier->id,
            'type' => 'mixed',
            'title' => 'Patrocinio época 2026',
            'description' => 'Apoio financeiro e em merchandising',
            'periodicity' => 'mensal',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'cost_center_id' => $costCenter->id,
            'status' => 'ativo',
            'notes' => 'Criado em teste automatizado',
            'money_items' => [
                [
                    'description' => 'Tranche inicial',
                    'amount' => 1200.50,
                    'expected_date' => now()->toDateString(),
                ],
            ],
            'goods_items' => [
                [
                    'item_name' => 'T-Shirt Oficial',
                    'item_id' => $product->id,
                    'category' => 'Merchandising',
                    'quantity' => 4,
                    'unit_value' => 15.00,
                    'total_value' => 60.00,
                ],
            ],
        ])->assertRedirect(route('patrocinios.index'));

        $sponsorship = Sponsorship::query()->with(['moneyItems', 'goodsItems', 'integrations'])->firstOrFail();

        $response = $this->actingAs($admin)->delete(route('patrocinios.destroy', $sponsorship));

        $response->assertRedirect(route('patrocinios.index'));

        $this->assertSoftDeleted('sponsorships', ['id' => $sponsorship->id]);
        $this->assertDatabaseCount('sponsorship_money_items', 0);
        $this->assertDatabaseCount('sponsorship_goods_items', 0);
        $this->assertDatabaseCount('sponsorship_integrations', 0);
        $this->assertDatabaseCount('movements', 0);
        $this->assertDatabaseMissing('stock_movements', [
            'reference_type' => 'sponsorship_goods_item',
        ]);

        $product->refresh();
        $this->assertSame(3, (int) $product->stock);
    }
}

<?php

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyInvoiceManualEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_generated_monthly_invoice_can_be_corrected_administratively(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $costCenter = CostCenter::query()->create([
            'codigo' => 'CC-MASTER',
            'nome' => 'Master',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        InvoiceType::query()->create([
            'codigo' => 'mensalidade',
            'nome' => 'Mensalidade',
            'descricao' => 'Mensalidade',
            'ativo' => true,
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $user->id,
            'data_fatura' => '2026-03-01',
            'mes' => '2026-03',
            'data_emissao' => '2026-03-01',
            'data_vencimento' => '2026-03-01',
            'valor_total' => 25,
            'valor_pago' => 0,
            'valor_em_aberto' => 25,
            'oculta' => false,
            'estado_pagamento' => 'vencido',
            'centro_custo_id' => $costCenter->id,
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee',
            'origem_id' => 'monthly-fee-plan-1',
            'observacoes' => 'Mensalidade março 2026',
        ]);

        InvoiceItem::query()->create([
            'fatura_id' => $invoice->id,
            'descricao' => 'Master 3x',
            'quantidade' => 1,
            'valor_unitario' => 25,
            'imposto_percentual' => 0,
            'total_linha' => 25,
            'centro_custo_id' => $costCenter->id,
        ]);

        $response = $this->actingAs($admin)->putJson(route('financeiro.update', $invoice), [
            'user_id' => $user->id,
            'data_fatura' => '2026-03-01',
            'mes' => '2026-03',
            'data_emissao' => '2026-03-01',
            'data_vencimento' => '2026-03-01',
            'tipo' => 'mensalidade',
            'valor_total' => 30,
            'estado_pagamento' => 'vencido',
            'centro_custo_id' => $costCenter->id,
            'origem_tipo' => 'monthly_fee',
            'origem_id' => 'monthly-fee-plan-1',
            'observacoes' => 'Mensalidade março 2026 corrigida manualmente',
            'oculta' => false,
            'items' => [[
                'descricao' => 'Master 3x',
                'quantidade' => 1,
                'valor_unitario' => 30,
                'imposto_percentual' => 0,
                'total_linha' => 30,
                'centro_custo_id' => $costCenter->id,
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('invoice.tipo', 'mensalidade')
            ->assertJsonPath('invoice.origem_tipo', 'monthly_fee')
            ->assertJsonPath('invoice.valor_total', '30.00')
            ->assertJsonPath('invoice.valor_em_aberto', '30.00')
            ->assertJsonPath('invoice.estado_pagamento', 'vencido');

        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'tipo' => 'mensalidade',
            'origem_tipo' => 'monthly_fee',
            'valor_total' => 30,
            'valor_em_aberto' => 30,
            'estado_pagamento' => 'vencido',
        ]);

        $this->assertDatabaseHas('invoice_items', [
            'fatura_id' => $invoice->id,
            'descricao' => 'Master 3x',
            'valor_unitario' => 30,
            'total_linha' => 30,
        ]);
        $this->assertSame(1, InvoiceItem::query()->where('fatura_id', $invoice->id)->count());
    }
}

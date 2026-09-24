<?php

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptImportOwnershipContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_receipt_import_endpoint_supplies_its_own_member_and_open_invoice_options(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Atleta Importacao Financeiro',
            'numero_socio' => '9101',
        ]);

        $costCenter = CostCenter::query()->create([
            'codigo' => 'CC-REC-OWN',
            'nome' => 'Centro Recibos',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $openInvoice = Invoice::query()->create([
            'user_id' => $member->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->toDateString(),
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 45.00,
            'valor_pago' => 0,
            'valor_em_aberto' => 45.00,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
            'mes' => now()->format('Y-m'),
        ]);

        $paidInvoice = Invoice::query()->create([
            'user_id' => $member->id,
            'centro_custo_id' => $costCenter->id,
            'data_fatura' => now()->subMonth()->toDateString(),
            'data_emissao' => now()->subMonth()->toDateString(),
            'data_vencimento' => now()->subDays(10)->toDateString(),
            'valor_total' => 40.00,
            'valor_pago' => 40.00,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'tipo' => 'mensalidade',
            'oculta' => false,
            'mes' => now()->subMonth()->format('Y-m'),
        ]);

        $response = $this->actingAs($admin)->getJson(route('financeiro.receipt-imports.index', [
            'include_options' => 1,
        ]));

        $response->assertOk();

        $userIds = collect($response->json('users'))->pluck('id')->all();
        $invoiceIds = collect($response->json('invoices'))->pluck('id')->all();

        $this->assertContains($member->id, $userIds);
        $this->assertContains($openInvoice->id, $invoiceIds);
        $this->assertNotContains($paidInvoice->id, $invoiceIds);
    }

    public function test_receipt_import_endpoint_omits_lookup_options_when_not_requested(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->getJson(route('financeiro.receipt-imports.index'));

        $response->assertOk()
            ->assertJsonMissingPath('users')
            ->assertJsonMissingPath('invoices');
    }
}

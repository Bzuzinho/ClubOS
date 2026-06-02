<?php

namespace Tests\Feature\Configuracoes;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\CostCenter;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfiguracoesReceiptImportTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuracoes_financeiro_contains_receipt_import_subtab_contract_in_source(): void
    {
        $configuracoesIndex = file_get_contents(resource_path('js/Pages/Configuracoes/Index.tsx'));

        $this->assertIsString($configuracoesIndex);
        $this->assertStringContainsString('financeiro-importacao-recibos', $configuracoesIndex);
        $this->assertStringContainsString('Importar Recibos', $configuracoesIndex);
        $this->assertStringContainsString('ReceiptImportsTab', $configuracoesIndex);
    }

    public function test_configuracoes_financeiro_exposes_receipt_import_payload_for_admin_partial_reload(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Atleta Configuracoes',
            'numero_socio' => '9001',
        ]);

        $costCenter = CostCenter::query()->create([
            'codigo' => 'CC-CONF-REC',
            'nome' => 'Centro Configuracoes Recibos',
            'tipo' => 'departamento',
            'ativo' => true,
        ]);

        $invoice = Invoice::query()->create([
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

        $response = $this->inertiaPartialGetAs($admin, route('configuracoes', ['tab' => 'financeiro']), [
            'receiptImportUsers',
            'receiptImportInvoices',
        ]);

        $response->assertOk();
        $response->assertJsonPath('component', 'Configuracoes/Index');
        $userIds = collect($response->json('props.receiptImportUsers'))->pluck('id')->all();
        $this->assertContains($member->id, $userIds);

        $invoiceIds = collect($response->json('props.receiptImportInvoices'))->pluck('id')->all();
        $this->assertContains($invoice->id, $invoiceIds);
    }

    private function inertiaPartialGetAs(User $user, string $uri, array $partialData)
    {
        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
            'X-Inertia-Partial-Component' => 'Configuracoes/Index',
            'X-Inertia-Partial-Data' => implode(',', $partialData),
        ])->get($uri);
    }
}

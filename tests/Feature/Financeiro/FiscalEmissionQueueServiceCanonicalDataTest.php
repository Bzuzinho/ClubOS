<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\CostCenter;
use App\Models\DadosPessoais;
use App\Models\FiscalDocumentRequest;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\Financeiro\FiscalEmissionQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalEmissionQueueServiceCanonicalDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_financial_entry_uses_canonical_fiscal_data_before_users_legacy_data(): void
    {
        $user = User::factory()->create([
            'name' => 'Legacy Name',
            'nome_completo' => 'Legacy Fiscal Name',
            'nif' => '111111111',
            'morada' => 'Rua Legacy 10',
            'codigo_postal' => '1111-111',
            'localidade' => 'Lisboa Legacy',
            'email' => 'legacy@example.com',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Nome Canonico',
            'nif' => '222222222',
            'morada' => 'Rua Canonica 99',
            'codigo_postal' => '2222-222',
            'localidade' => 'Porto Canonico',
        ]);

        $entry = $this->createPaidRevenueEntry($user);

        $request = app(FiscalEmissionQueueService::class)->queueFinancialEntry($entry);

        $this->assertNotNull($request);
        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $request->status);
        $this->assertSame('Nome Canonico', $request->customer_name);
        $this->assertSame('222222222', $request->customer_tax_number);
        $this->assertSame("Rua Canonica 99\n2222-222 Porto Canonico", $request->customer_address);
    }

    public function test_queue_financial_entry_falls_back_to_users_fiscal_data_without_dados_pessoais(): void
    {
        $user = User::factory()->create([
            'name' => 'Legacy Name',
            'nome_completo' => 'Legacy Fiscal Name',
            'nif' => '333333333',
            'morada' => 'Rua Legacy 30',
            'codigo_postal' => '3333-333',
            'localidade' => 'Coimbra Legacy',
            'email' => 'legacy30@example.com',
        ]);

        $this->assertNull($user->dadosPessoais);

        $entry = $this->createPaidRevenueEntry($user);

        $request = app(FiscalEmissionQueueService::class)->queueFinancialEntry($entry);

        $this->assertNotNull($request);
        $this->assertSame(FiscalDocumentRequest::STATUS_PENDING, $request->status);
        $this->assertSame('Legacy Fiscal Name', $request->customer_name);
        $this->assertSame('333333333', $request->customer_tax_number);
        $this->assertSame("Rua Legacy 30\n3333-333 Coimbra Legacy", $request->customer_address);
    }

    public function test_queue_financial_entry_keeps_error_status_when_nif_is_missing(): void
    {
        $user = User::factory()->create([
            'name' => 'Sem NIF',
            'nome_completo' => 'Nome Sem NIF',
            'nif' => null,
            'morada' => 'Rua Sem NIF 1',
            'codigo_postal' => '4444-444',
            'localidade' => 'Aveiro',
            'email' => 'semnif@example.com',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Nome Canonico Sem NIF',
            'nif' => null,
        ]);

        $entry = $this->createPaidRevenueEntry($user);

        $request = app(FiscalEmissionQueueService::class)->queueFinancialEntry($entry);

        $this->assertNotNull($request);
        $this->assertSame(FiscalDocumentRequest::STATUS_ERROR_DATA, $request->status);
        $this->assertSame('Nome Canonico Sem NIF', $request->customer_name);
        $this->assertNull($request->customer_tax_number);
        $this->assertNotNull($request->last_error);
        $this->assertStringContainsString('NIF do cliente em falta.', $request->last_error);
    }

    private function createPaidRevenueEntry(User $user): FinancialEntry
    {
        return FinancialEntry::query()->create([
            'data' => '2026-06-24',
            'tipo' => 'receita',
            'categoria' => 'Servico',
            'descricao' => 'Receita para emissao fiscal',
            'documento_ref' => 'FISCAL-ENTRY-001',
            'valor' => 45.00,
            'valor_pago' => 45.00,
            'valor_em_aberto' => 0,
            'estado' => 'pago',
            'data_pagamento' => '2026-06-24',
            'centro_custo_id' => $this->createCostCenter()->id,
            'user_id' => $user->id,
            'entidade_nome' => null,
            'origem_tipo' => 'manual',
            'origem_modulo' => 'financeiro',
            'origem_id' => null,
        ]);
    }

    private function createCostCenter(): CostCenter
    {
        return CostCenter::query()->firstOrCreate(
            ['codigo' => 'CC-FISCAL-QUEUE'],
            [
                'nome' => 'Centro Fiscal Queue',
                'tipo' => 'departamento',
                'ativo' => true,
            ],
        );
    }
}

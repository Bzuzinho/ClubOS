<?php

declare(strict_types=1);

namespace Tests\Feature\Financeiro;

use App\Models\DadosFinanceiros;
use App\Models\FinancialEntry;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Models\UserType;
use App\Services\Financeiro\MonthlyFeeGenerationService;
use App\Services\Financeiro\MonthlyFeeOwnershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class MonthlyFeeOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_generated_monthly_fee_has_canonical_owner_and_source(): void
    {
        $plan = MonthlyFee::query()->create([
            'designacao' => 'Mensalidade FEE1',
            'valor' => 35,
            'ativo' => true,
        ]);
        $user = User::factory()->create([
            'estado' => 'ativo',
            'data_inscricao' => '2026-05-01',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);
        $athleteType = UserType::query()->firstOrCreate(
            ['codigo' => 'atleta'],
            ['nome' => 'Atleta', 'descricao' => 'Atleta', 'ativo' => true],
        );
        $user->userTypes()->sync([$athleteType->id]);
        DadosFinanceiros::query()->create([
            'user_id' => $user->id,
            'mensalidade_id' => $plan->id,
        ]);

        $invoice = app(MonthlyFeeGenerationService::class)->generateForUser(
            $user->fresh(['dadosFinanceiros.mensalidade', 'centrosCusto']),
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-01'),
            ['manual_trigger' => true],
        )->first();

        $this->assertNotNull($invoice);
        $this->assertSame($user->id, $invoice->user_id);
        $this->assertSame('monthly_fee', $invoice->origem_tipo);
        $this->assertSame($plan->id, $invoice->origem_id);
    }

    public function test_audit_detects_financial_entry_owner_mismatch(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $invoice = $this->monthlyInvoice($owner);
        FinancialEntry::query()->create([
            'data' => '2026-07-01',
            'tipo' => 'receita',
            'categoria' => 'Mensalidade',
            'descricao' => 'Entrada legacy',
            'valor' => 30,
            'user_id' => $other->id,
            'fatura_id' => $invoice->id,
        ]);

        $payload = app(MonthlyFeeOwnershipService::class)->audit();

        $this->assertSame(1, $payload['summary']['financial_entries_owner_mismatch_count']);
        $this->assertContains('financial_entry_owner_mismatch', collect($payload['findings'])->pluck('code')->all());
    }

    public function test_repair_defaults_to_dry_run_and_does_not_change_data(): void
    {
        $owner = User::factory()->create();
        $invoice = $this->monthlyInvoice($owner);

        $this->artisan('finance:repair-monthly-fee-ownership', ['--json' => true])
            ->assertSuccessful();

        $this->assertSame($owner->id, $invoice->fresh()->user_id);
    }

    private function monthlyInvoice(User $owner)
    {
        return $owner->invoices()->create([
            'data_fatura' => '2026-07-01',
            'mes' => '2026-07',
            'data_emissao' => '2026-07-01',
            'data_vencimento' => '2026-07-08',
            'valor_total' => 30,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);
    }
}

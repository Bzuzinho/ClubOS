<?php

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembrosCurrentAccountSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_show_uses_open_amount_for_partial_invoice_instead_of_nominal_total(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $invoice = Invoice::query()->create([
            'user_id' => $member->id,
            'mes' => 'Mensalidade Maio',
            'data_fatura' => now()->subDays(5)->toDateString(),
            'data_emissao' => now()->subDays(5)->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $payment = Payment::query()->create([
            'user_id' => $member->id,
            'amount' => 40,
            'allocated_amount' => 40,
            'unallocated_amount' => 0,
            'payment_date' => now()->toDateString(),
            'method' => 'dinheiro',
            'source' => Payment::SOURCE_MANUAL,
            'status' => Payment::STATUS_CONFIRMED,
        ]);

        PaymentAllocation::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));

        $response->assertOk();
        $response->assertJsonPath('component', 'Membros/Show');
        $this->assertEquals(60.0, (float) $response->json('props.member.conta_corrente'));
        $this->assertEquals(60.0, (float) $response->json('props.member.divida_bruta'));
        $this->assertEquals(60.0, (float) $response->json('props.member.divida_liquida'));
        $this->assertEquals(100.0, (float) $response->json('props.faturas.0.valor_total'));
        $this->assertEquals(40.0, (float) $response->json('props.faturas.0.valor_pago'));
        $this->assertEquals(60.0, (float) $response->json('props.faturas.0.valor_em_aberto'));
    }

    public function test_member_show_exposes_manual_legacy_balance_separately_without_duplicating_total(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'conta_corrente_manual' => 18.5,
        ]);

        Invoice::query()->create([
            'user_id' => $member->id,
            'mes' => 'Mensalidade Junho',
            'data_fatura' => now()->subDays(2)->toDateString(),
            'data_emissao' => now()->subDays(2)->toDateString(),
            'data_vencimento' => now()->addDays(8)->toDateString(),
            'valor_total' => 35,
            'valor_pago' => 0,
            'valor_em_aberto' => 35,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));

        $response->assertOk();
        $this->assertEquals(35.0, (float) $response->json('props.member.conta_corrente'));
        $this->assertEquals(18.5, (float) $response->json('props.member.conta_corrente_manual'));
        $this->assertEquals(18.5, (float) $response->json('props.member.saldo_manual_legado'));
        $this->assertEquals(35.0, (float) $response->json('props.member.divida_liquida'));
        $this->assertEquals(18.5, (float) $response->json('props.member.current_account_summary.manual_account_balance'));
    }

    private function inertiaGetAs(User $user, string $uri)
    {
        $inertiaVersion = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($user)->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Version' => (string) $inertiaVersion,
        ])->get($uri);
    }
}
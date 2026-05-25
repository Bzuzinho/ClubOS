<?php

namespace Tests\Feature\Dashboard;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberDashboardSurfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_surface_payload_keeps_canonical_net_debt_even_with_paid_history(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        Invoice::query()->create([
            'user_id' => $member->id,
            'mes' => 'Mensalidade Abril',
            'data_fatura' => now()->subMonths(2)->toDateString(),
            'data_emissao' => now()->subMonths(2)->toDateString(),
            'data_vencimento' => now()->subMonths(2)->addDays(5)->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 100,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pago',
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        $partialInvoice = Invoice::query()->create([
            'user_id' => $member->id,
            'mes' => 'Mensalidade Maio',
            'data_fatura' => now()->subDays(5)->toDateString(),
            'data_emissao' => now()->subDays(5)->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
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
            'invoice_id' => $partialInvoice->id,
            'amount' => 40,
            'status' => PaymentAllocation::STATUS_CONFIRMED,
            'allocated_at' => now(),
        ]);

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));

        $response->assertOk();
        $response->assertJsonPath('component', 'Membros/Show');
        $this->assertEquals(60.0, (float) $response->json('props.member.current_account_summary.net_debt'));
        $this->assertEquals(60.0, (float) $response->json('props.member.conta_corrente'));
        $this->assertEquals(100.0, (float) $response->json('props.faturas.0.valor_total'));
        $this->assertEquals(60.0, (float) $response->json('props.faturas.0.valor_em_aberto'));
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
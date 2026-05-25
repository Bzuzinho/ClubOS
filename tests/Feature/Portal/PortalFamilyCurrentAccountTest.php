<?php

namespace Tests\Feature\Portal;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AccountCredit;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalFamilyCurrentAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_portal_sums_only_real_open_debt_for_visible_family_members(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educandoA = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Parcial',
        ]);
        $educandoB = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Futuro',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educandoA, $educandoB]);

        $invoice = Invoice::query()->create([
            'user_id' => $educandoA->id,
            'mes' => 'Mensalidade Maio',
            'data_fatura' => now()->subDays(4)->toDateString(),
            'data_emissao' => now()->subDays(4)->toDateString(),
            'data_vencimento' => now()->addDays(3)->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        $payment = Payment::query()->create([
            'user_id' => $educandoA->id,
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

        Invoice::query()->create([
            'user_id' => $educandoB->id,
            'mes' => 'Mensalidade Futura',
            'data_fatura' => now()->addMonth()->toDateString(),
            'data_emissao' => now()->addMonth()->toDateString(),
            'data_vencimento' => now()->addMonth()->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 0,
            'valor_em_aberto' => 100,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => true,
        ]);

        $response = $this->inertiaGetAs($guardian, route('portal.family'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Family');
        $response->assertJsonPath('props.familySummary.pagamentos_pendentes_valor', 60);
        $response->assertJsonPath('props.familySummary.net_debt', 60);
        $response->assertJsonPath('props.familySummary.gross_debt', 60);
        $this->assertCount(1, $response->json('props.pagamentos'));
        $response->assertJsonPath('props.pagamentos.0.valor', 60);
        $response->assertJsonPath('props.pagamentos.0.valor_nominal', 100);
    }

    public function test_family_portal_exposes_available_credit_separately_and_reduces_net_debt(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Crédito',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educando]);

        Invoice::query()->create([
            'user_id' => $educando->id,
            'mes' => 'Mensalidade Junho',
            'data_fatura' => now()->subDays(3)->toDateString(),
            'data_emissao' => now()->subDays(3)->toDateString(),
            'data_vencimento' => now()->addDays(7)->toDateString(),
            'valor_total' => 60,
            'valor_pago' => 0,
            'valor_em_aberto' => 60,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
            'oculta' => false,
        ]);

        AccountCredit::query()->create([
            'user_id' => $educando->id,
            'amount' => 15,
            'remaining_amount' => 15,
            'source' => 'portal_family_test',
            'status' => AccountCredit::STATUS_AVAILABLE,
        ]);

        $response = $this->inertiaGetAs($guardian, route('portal.family'));

        $response->assertOk();
        $response->assertJsonPath('props.familySummary.gross_debt', 60);
        $response->assertJsonPath('props.familySummary.available_credit', 15);
        $response->assertJsonPath('props.familySummary.net_debt', 45);
        $response->assertJsonPath('props.familySummary.pagamentos_pendentes_valor', 45);
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

    /**
     * @param  array<int, User>  $educandos
     */
    private function linkGuardianToEducandos(User $guardian, array $educandos): void
    {
        foreach ($educandos as $educando) {
            DB::table('user_guardian')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $educando->id,
                'guardian_id' => $guardian->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
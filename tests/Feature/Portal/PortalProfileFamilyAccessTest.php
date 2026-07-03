<?php

namespace Tests\Feature\Portal;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AccountCredit;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalProfileFamilyAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardian_can_view_and_edit_educando_profile_through_portal(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Portal',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educando]);

        $showResponse = $this->inertiaGetAs($guardian, route('portal.profile', ['member' => $educando->id]));

        $showResponse->assertOk();
        $showResponse->assertJsonPath('component', 'Portal/Profile');
        $showResponse->assertJsonPath('props.profile.id', $educando->id);
        $showResponse->assertJsonPath('props.profile.can_edit', true);

        $updateResponse = $this->actingAs($guardian)->patch(route('portal.profile.update', ['member' => $educando->id]), [
            'nome_completo' => 'Educando Atualizado',
        ]);

        $updateResponse->assertRedirect(route('portal.profile', ['member' => $educando->id]));
        $this->assertDatabaseHas('dados_pessoais', [
            'user_id' => $educando->id,
            'nome_completo' => 'Educando Atualizado',
        ]);
    }

    public function test_user_cannot_view_or_edit_unrelated_profile_through_portal(): void
    {
        $user = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
        ]);

        $otherMember = User::factory()->create([
            'nome_completo' => 'Outro Membro',
        ]);

        $this->inertiaGetAs($user, route('portal.profile', ['member' => $otherMember->id]))->assertForbidden();

        $this->actingAs($user)
            ->patch(route('portal.profile.update', ['member' => $otherMember->id]), [
                'nome_completo' => 'Tentativa Inválida',
            ])
            ->assertForbidden();
    }

    public function test_guardian_portal_profile_still_works_when_family_tables_are_not_migrated(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Sem Familia Table',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educando]);

        Schema::dropIfExists('familia_user');
        Schema::dropIfExists('familias');

        $response = $this->inertiaGetAs($guardian, route('portal.profile', ['member' => $educando->id]));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Profile');
        $response->assertJsonPath('props.profile.id', $educando->id);
        $response->assertJsonPath('props.profile.can_edit', true);
    }

    public function test_portal_profile_financial_payload_uses_operational_current_account_and_exposes_credit(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Financeiro',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educando]);

        DadosFinanceiros::query()->create([
            'user_id' => $educando->id,
            'conta_corrente_manual' => 7.5,
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $educando->id,
            'mes' => 'Mensalidade Maio',
            'data_fatura' => now()->subDays(5)->toDateString(),
            'data_emissao' => now()->subDays(5)->toDateString(),
            'data_vencimento' => now()->addDays(3)->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $payment = Payment::query()->create([
            'user_id' => $educando->id,
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

        AccountCredit::query()->create([
            'user_id' => $educando->id,
            'amount' => 15,
            'remaining_amount' => 15,
            'source' => 'manual_test',
            'status' => AccountCredit::STATUS_AVAILABLE,
        ]);

        $response = $this->inertiaGetAs($guardian, route('portal.profile', ['member' => $educando->id]));

        $response->assertOk();
        $response->assertJsonPath('props.profile.financial.account_balance', '45,00 €');
        $response->assertJsonPath('props.profile.financial.outstanding_value', '45,00 €');
        $response->assertJsonPath('props.profile.financial.gross_debt', '60,00 €');
        $response->assertJsonPath('props.profile.financial.available_credit', '15,00 €');
        $response->assertJsonPath('props.profile.financial.next_payment.amount', '60,00 €');
        $this->assertArrayNotHasKey('manual_account_balance', $response->json('props.profile.financial'));
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
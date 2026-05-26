<?php

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosFinanceiros;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Financeiro\CurrentAccountService;
use App\Services\Members\MemberImportService;
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
        $this->assertEquals(60.0, (float) $response->json('props.member.current_account_summary.net_debt'));
        $this->assertEquals(60.0, (float) $response->json('props.member.current_account_summary.gross_debt'));
        $this->assertEquals(60.0, (float) $response->json('props.member.current_account_summary.monthly_fees_open_amount'));
        $this->assertEquals(0.0, (float) $response->json('props.member.current_account_summary.revenue_movements_open_amount'));
        $this->assertArrayNotHasKey('divida_bruta', $response->json('props.member'));
        $this->assertArrayNotHasKey('divida_liquida', $response->json('props.member'));
        $this->assertEquals(100.0, (float) $response->json('props.faturas.0.valor_total'));
        $this->assertEquals(40.0, (float) $response->json('props.faturas.0.valor_pago'));
        $this->assertEquals(60.0, (float) $response->json('props.faturas.0.valor_em_aberto'));
    }

    public function test_member_show_keeps_manual_legacy_balance_out_of_operational_top_level_fields(): void
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
        $this->assertEquals(35.0, (float) $response->json('props.member.current_account_summary.net_debt'));
        $this->assertEquals(18.5, (float) $response->json('props.member.current_account_summary.manual_account_balance'));
        $this->assertArrayNotHasKey('conta_corrente_manual', $response->json('props.member'));
        $this->assertArrayNotHasKey('saldo_manual_legado', $response->json('props.member'));
        $this->assertArrayNotHasKey('divida_liquida', $response->json('props.member'));
        $this->assertArrayNotHasKey('divida_bruta', $response->json('props.member'));
    }

    public function test_member_show_page_does_not_expose_editable_manual_current_account_field(): void
    {
        $source = file_get_contents(resource_path('js/Pages/Membros/Show.tsx'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('conta_corrente_manual', $source);
        $this->assertStringNotContainsString('Conta corrente manual', $source);
    }

    public function test_member_update_rejects_manual_legacy_balance_adjustments_and_preserves_operational_current_account(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Membro Ajuste',
            'sexo' => 'masculino',
            'estado' => 'ativo',
        ]);

        DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'conta_corrente_manual' => 18.5,
        ]);

        Invoice::query()->create([
            'user_id' => $member->id,
            'mes' => 'Mensalidade Julho',
            'data_fatura' => now()->subDay()->toDateString(),
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'valor_total' => 35,
            'valor_pago' => 0,
            'valor_em_aberto' => 35,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $response = $this->actingAs($admin)->from(route('membros.show', $member))
            ->put(route('membros.update', $member), [
                'nome_completo' => $member->nome_completo,
                'email_utilizador' => $member->email_utilizador,
                'numero_socio' => (string) $member->numero_socio,
                'sexo' => $member->sexo,
                'estado' => $member->estado,
                'tipo_membro' => $member->tipo_membro ?? [],
                'conta_corrente_manual' => 99.99,
            ]);

        $response->assertRedirect(route('membros.show', $member));
        $response->assertSessionHasErrors('conta_corrente_manual');

        $member->dadosFinanceiros()->firstOrFail()->refresh();
        $this->assertSame('18.50', $member->dadosFinanceiros->conta_corrente_manual);

        $summary = app(CurrentAccountService::class)->summarize(['user_id' => $member->id]);

        $this->assertSame(35.0, (float) $summary['net_debt']);
        $this->assertSame(18.5, (float) $summary['manual_account_balance']);
    }

    public function test_member_import_ignores_manual_legacy_balance_field_and_warns(): void
    {
        $result = app(MemberImportService::class)->import([
            [
                'Nome completo' => 'Importado Sem Saldo',
                'Sexo' => 'masculino',
                'Estado' => 'ativo',
                'Conta Corrente' => '123,45',
            ],
        ], [
            'nome_completo' => 'Nome completo',
            'sexo' => 'Sexo',
            'estado' => 'Estado',
            'conta_corrente_manual' => 'Conta Corrente',
        ]);

        $this->assertSame(1, $result['created_count']);
        $this->assertCount(0, $result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('conta_corrente_manual', $result['warnings'][0]['field']);

        $member = User::query()->findOrFail($result['created_ids'][0]);

        $this->assertNull($member->dadosFinanceiros);
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
<?php

namespace Tests\Feature\Dashboard;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Invoice;
use App\Models\Movement;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardEntryRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_athlete_enters_dashboard_in_athlete_portal(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $response = $this->inertiaGetAs($athlete, '/dashboard');

        $response->assertOk();
        $response->assertJsonPath('component', 'Dashboard/Atleta');
        $response->assertJsonPath('props.is_also_admin', false);
        $response->assertJsonPath('props.is_atleta', true);
        $response->assertJsonPath('props.has_family', false);
    }

    public function test_admin_only_enters_classic_dashboard_and_can_force_admin_mode(): void
    {
        $admin = User::factory()->admin()->create([
            'tipo_membro' => ['admin'],
        ]);

        $portalResponse = $this->inertiaGetAs($admin, '/dashboard');

        $portalResponse->assertOk();
        $portalResponse->assertJsonPath('component', 'Dashboard');

        $adminResponse = $this->inertiaGetAs($admin, '/dashboard?mode=admin');

        $adminResponse->assertOk();
        $adminResponse->assertJsonPath('component', 'Dashboard');
    }

    public function test_non_admin_cannot_force_admin_mode(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $this->inertiaGetAs($athlete, '/dashboard?mode=admin')->assertForbidden();
    }

    public function test_guardian_enters_portal_first_and_gets_family_access(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educandoA = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Um',
        ]);
        $educandoB = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Dois',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educandoA, $educandoB]);

        $response = $this->inertiaGetAs($guardian, '/dashboard');

        $response->assertOk();
        $response->assertJsonPath('component', 'Dashboard/Atleta');
        $response->assertJsonPath('props.has_family', true);
        $response->assertJsonPath('props.family_summary.educandos', 2);
        $response->assertJsonPath('props.family_portal_url', route('portal.family'));
    }

    public function test_guardian_who_is_also_athlete_still_enters_portal_first(): void
    {
        $guardianAthlete = User::factory()->athlete()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao', 'atleta'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $this->linkGuardianToEducandos($guardianAthlete, [$educando]);

        $response = $this->inertiaGetAs($guardianAthlete, '/dashboard');

        $response->assertOk();
        $response->assertJsonPath('component', 'Dashboard/Atleta');
        $response->assertJsonPath('props.is_atleta', true);
        $response->assertJsonPath('props.has_family', true);
    }

    public function test_admin_guardian_enters_portal_first_and_keeps_admin_access(): void
    {
        $adminGuardian = User::factory()->admin()->create([
            'perfil' => 'admin',
            'tipo_membro' => ['admin', 'encarregado_educacao'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $this->linkGuardianToEducandos($adminGuardian, [$educando]);

        $response = $this->inertiaGetAs($adminGuardian, '/dashboard');

        $response->assertOk();
        $response->assertJsonPath('component', 'Dashboard/Atleta');
        $response->assertJsonPath('props.is_also_admin', true);
        $response->assertJsonPath('props.has_family', true);
    }

    public function test_generic_user_enters_portal_without_family(): void
    {
        $user = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
        ]);

        $response = $this->inertiaGetAs($user, '/dashboard');

        $response->assertOk();
        $response->assertJsonPath('component', 'Dashboard/Atleta');
        $response->assertJsonPath('props.has_family', false);
    }

    public function test_athlete_dashboard_current_account_includes_financial_movements(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        Invoice::query()->create([
            'user_id' => $athlete->id,
            'mes' => 'Mensalidade Abril',
            'data_fatura' => now()->subDay()->toDateString(),
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->addDays(7)->toDateString(),
            'valor_total' => 40,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $firstResponse = $this->inertiaGetAs($athlete, '/dashboard');

        $firstResponse->assertOk();
        $firstResponse->assertJsonPath('props.component', null);
        $firstResponse->assertJsonPath('props.resumo.conta_corrente', 40);

        Movement::query()->create([
            'user_id' => $athlete->id,
            'classificacao' => 'receita',
            'data_emissao' => now()->toDateString(),
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'valor_total' => 15,
            'estado_pagamento' => 'pendente',
            'tipo' => 'material',
            'referencia_pagamento' => 'LOJA-001',
        ]);

        $secondResponse = $this->inertiaGetAs($athlete, '/dashboard');

        $secondResponse->assertOk();
        $secondResponse->assertJsonPath('props.resumo.conta_corrente', 55);
    }

    public function test_athlete_dashboard_current_account_uses_open_amount_and_excludes_future_hidden_invoices(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $invoice = Invoice::query()->create([
            'user_id' => $athlete->id,
            'mes' => 'Mensalidade Maio',
            'data_fatura' => now()->subDay()->toDateString(),
            'data_emissao' => now()->subDay()->toDateString(),
            'data_vencimento' => now()->addDays(7)->toDateString(),
            'valor_total' => 100,
            'valor_pago' => 0,
            'valor_em_aberto' => 0,
            'oculta' => false,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $payment = Payment::query()->create([
            'user_id' => $athlete->id,
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
            'user_id' => $athlete->id,
            'mes' => 'Mensalidade Futura',
            'data_fatura' => now()->addMonth()->toDateString(),
            'data_emissao' => now()->addMonth()->toDateString(),
            'data_vencimento' => now()->addMonth()->toDateString(),
            'valor_total' => 999,
            'valor_pago' => 0,
            'valor_em_aberto' => 999,
            'oculta' => true,
            'estado_pagamento' => 'pendente',
            'tipo' => 'mensalidade',
        ]);

        $response = $this->inertiaGetAs($athlete, '/dashboard');

        $response->assertOk();
        $response->assertJsonPath('props.resumo.conta_corrente', 60);
        $response->assertJsonPath('props.resumo.divida_bruta', 60);
        $response->assertJsonPath('props.resumo.credito_disponivel', 0);
    }

    public function test_family_route_requires_family_access_and_renders_family_portal(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Teste',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educando]);

        $allowedResponse = $this->inertiaGetAs($guardian, '/portal/familia');

        $allowedResponse->assertOk();
        $allowedResponse->assertJsonPath('component', 'Portal/Family');
        $allowedResponse->assertJsonCount(1, 'props.educandos');

        $genericUser = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
        ]);

        $this->inertiaGetAs($genericUser, '/portal/familia')->assertForbidden();
    }

    public function test_guardian_can_search_and_associate_family_members(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
            'nome_completo' => 'Guardian User',
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Base',
        ]);

        $candidate = User::factory()->create([
            'nome_completo' => 'Novo Familiar',
            'email' => 'novo.familiar@example.test',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educando]);

        $searchResponse = $this->actingAs($guardian)
            ->getJson('/portal/familia/membros/search?search=Novo');

        $searchResponse->assertOk();
        $searchResponse->assertJsonCount(1, 'results');
        $searchResponse->assertJsonPath('results.0.id', $candidate->id);

        $associateResponse = $this->actingAs($guardian)
            ->post('/portal/familia/membros', [
                'member_id' => $candidate->id,
                'papel_na_familia' => 'familiar',
            ]);

        $associateResponse->assertRedirect(route('portal.family'));

        $this->assertDatabaseHas('familias', [
            'responsavel_user_id' => $guardian->id,
        ]);

        $this->assertDatabaseHas('familia_user', [
            'user_id' => $candidate->id,
            'papel_na_familia' => 'familiar',
        ]);

        $familyPage = $this->inertiaGetAs($guardian, '/portal/familia');

        $familyPage->assertOk();
        $memberNames = collect($familyPage->json('props.families.0.members'))->pluck('name')->all();

        $this->assertContains('Novo Familiar', $memberNames);
        $this->assertContains('Educando Base', $memberNames);
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
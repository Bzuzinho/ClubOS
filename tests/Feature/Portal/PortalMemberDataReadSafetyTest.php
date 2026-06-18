<?php

declare(strict_types=1);

namespace Tests\Feature\Portal;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalMemberDataReadSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_profile_reads_fallback_data_without_persisting_changes(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Nome Users Portal',
            'morada' => 'Morada Users Portal',
            'nif' => '123123123',
            'rgpd' => true,
        ]);

        $this->linkGuardianToEducandos($guardian, [$educando]);

        $dadosPessoais = DadosPessoais::query()->create([
            'user_id' => $educando->id,
            'nome_completo' => 'Nome Dados Pessoais Portal',
            'morada' => '',
            'nif' => '999999999',
        ]);

        $dadosConfiguracao = DadosConfiguracao::query()->create([
            'user_id' => $educando->id,
            'consentimento_rgpd' => false,
        ]);

        $dadosPessoaisUpdatedAtBefore = $dadosPessoais->updated_at;
        $dadosConfiguracaoUpdatedAtBefore = $dadosConfiguracao->updated_at;

        $response = $this->inertiaGetAs($guardian, route('portal.profile', ['member' => $educando->id]));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Profile');
        $response->assertJsonPath('props.profile.id', $educando->id);
        $response->assertJsonPath('props.profile.name', 'Nome Dados Pessoais Portal');
        $response->assertJsonPath('props.profile.editable.nif', '999999999');
        $response->assertJsonPath('props.profile.editable.morada', 'Morada Users Portal');

        $editable = $response->json('props.profile.editable');
        $this->assertIsArray($editable);
        $this->assertArrayNotHasKey('perfil', $editable);
        $this->assertArrayNotHasKey('estado', $editable);
        $this->assertArrayNotHasKey('rgpd', $editable);

        $educando->refresh();
        $this->assertSame('Nome Users Portal', $educando->nome_completo);
        $this->assertSame('Morada Users Portal', $educando->morada);
        $this->assertSame('123123123', $educando->nif);

        $dadosPessoais->refresh();
        $dadosConfiguracao->refresh();
        $this->assertTrue($dadosPessoais->updated_at?->equalTo($dadosPessoaisUpdatedAtBefore));
        $this->assertTrue($dadosConfiguracao->updated_at?->equalTo($dadosConfiguracaoUpdatedAtBefore));
    }

    public function test_portal_family_remains_stable_with_members_having_new_table_rows(): void
    {
        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
            'nome_completo' => 'Guardião Family',
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Family',
        ]);

        $this->linkGuardianToEducandos($guardian, [$educando]);

        $dadosPessoais = DadosPessoais::query()->create([
            'user_id' => $educando->id,
            'nome_completo' => 'Educando Dados Pessoais',
        ]);

        $dadosConfiguracao = DadosConfiguracao::query()->create([
            'user_id' => $educando->id,
            'consentimento_rgpd' => true,
        ]);

        $dadosPessoaisUpdatedAtBefore = $dadosPessoais->updated_at;
        $dadosConfiguracaoUpdatedAtBefore = $dadosConfiguracao->updated_at;

        $response = $this->inertiaGetAs($guardian, route('portal.family'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Family');
        $response->assertJsonPath('props.familyMember.id', $guardian->id);
        $response->assertJsonPath('props.educandos.0.id', $educando->id);
        $response->assertJsonPath('props.educandos.0.name', 'Educando Family');

        $dadosPessoais->refresh();
        $dadosConfiguracao->refresh();
        $this->assertTrue($dadosPessoais->updated_at?->equalTo($dadosPessoaisUpdatedAtBefore));
        $this->assertTrue($dadosConfiguracao->updated_at?->equalTo($dadosConfiguracaoUpdatedAtBefore));
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberDataReadVisualSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_show_and_edit_use_users_data_when_new_tables_do_not_exist(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'nome_completo' => 'Membro Legado',
            'nif' => '123456789',
            'morada' => 'Rua Legacy 10',
            'codigo_postal' => '4000-100',
            'localidade' => 'Porto',
            'rgpd' => true,
            'afiliacao' => false,
        ]);

        $beforeDadosPessoaisCount = DadosPessoais::query()->count();
        $beforeDadosConfiguracaoCount = DadosConfiguracao::query()->count();

        $show = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));
        $show->assertOk();
        $show->assertJsonPath('component', 'Membros/Show');
        $show->assertJsonPath('props.member.nome_completo', 'Membro Legado');
        $show->assertJsonPath('props.member.nif', '123456789');
        $show->assertJsonPath('props.member.morada', 'Rua Legacy 10');
        $show->assertJsonPath('props.member.rgpd', true);
        $show->assertJsonPath('props.member.afiliacao', false);

        $edit = $this->inertiaGetAs($admin, route('membros.edit', ['member' => $member->id]));
        $edit->assertOk();
        $edit->assertJsonPath('component', 'Membros/Edit');
        $edit->assertJsonPath('props.member.nome_completo', 'Membro Legado');
        $edit->assertJsonPath('props.member.nif', '123456789');
        $edit->assertJsonPath('props.member.morada', 'Rua Legacy 10');

        $this->assertSame($beforeDadosPessoaisCount, DadosPessoais::query()->count());
        $this->assertSame($beforeDadosConfiguracaoCount, DadosConfiguracao::query()->count());
    }

    public function test_member_show_and_edit_prefer_dados_pessoais_but_keep_fallback_for_empty_fields(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'nome_completo' => 'Nome Users',
            'nif' => '111111111',
            'morada' => 'Morada Users',
            'contacto' => '910000000',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $member->id,
            'nome_completo' => 'Nome Dados Pessoais',
            'nif' => '999999999',
            'morada' => '',
            'contacto' => '920000000',
        ]);

        $show = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));
        $show->assertOk();
        $show->assertJsonPath('props.member.nome_completo', 'Nome Dados Pessoais');
        $show->assertJsonPath('props.member.nif', '999999999');
        $show->assertJsonPath('props.member.morada', 'Morada Users');
        $show->assertJsonPath('props.member.contacto', '920000000');

        $edit = $this->inertiaGetAs($admin, route('membros.edit', ['member' => $member->id]));
        $edit->assertOk();
        $edit->assertJsonPath('props.member.nome_completo', 'Nome Dados Pessoais');
        $edit->assertJsonPath('props.member.nif', '999999999');
        $edit->assertJsonPath('props.member.morada', 'Morada Users');
        $edit->assertJsonPath('props.member.contacto', '920000000');
    }

    public function test_member_show_and_edit_prefer_dados_configuracao_with_fallback_when_null(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'rgpd' => true,
            'consentimento' => true,
            'afiliacao' => true,
            'data_afiliacao' => '2026-01-10',
        ]);

        $dadosConfiguracao = DadosConfiguracao::query()->create([
            'user_id' => $member->id,
            'consentimento_rgpd' => false,
            'consentimento_imagem' => false,
            'afiliacao_federativa' => null,
            'afiliacao_data' => null,
        ]);

        $updatedAtBefore = $dadosConfiguracao->updated_at;

        $show = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));
        $show->assertOk();
        $show->assertJsonPath('props.member.rgpd', false);
        $show->assertJsonPath('props.member.consentimento', false);
        $show->assertJsonPath('props.member.afiliacao', true);
        $show->assertJsonPath('props.member.data_afiliacao', '2026-01-10');

        $edit = $this->inertiaGetAs($admin, route('membros.edit', ['member' => $member->id]));
        $edit->assertOk();
        $edit->assertJsonPath('props.member.rgpd', false);
        $edit->assertJsonPath('props.member.consentimento', false);
        $edit->assertJsonPath('props.member.afiliacao', true);

        $dadosConfiguracao->refresh();
        $this->assertTrue($dadosConfiguracao->updated_at?->equalTo($updatedAtBefore));
    }

    public function test_member_show_and_edit_do_not_fail_when_member_has_no_new_table_rows(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'nome_completo' => 'Sem Novas Tabelas',
            'perfil' => 'socio',
        ]);

        $this->assertNull($member->dadosPessoais);
        $this->assertNull($member->dadosConfiguracao);

        $show = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));
        $show->assertOk();
        $show->assertJsonPath('component', 'Membros/Show');
        $show->assertJsonPath('props.member.nome_completo', 'Sem Novas Tabelas');

        $edit = $this->inertiaGetAs($admin, route('membros.edit', ['member' => $member->id]));
        $edit->assertOk();
        $edit->assertJsonPath('component', 'Membros/Edit');
        $edit->assertJsonPath('props.member.nome_completo', 'Sem Novas Tabelas');
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

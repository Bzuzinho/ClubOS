<?php

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MemberDataStructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_have_dados_pessoais_and_relation_works(): void
    {
        $user = User::factory()->create();

        $dadosPessoais = DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Membro Teste',
            'sexo' => 'masculino',
            'nif' => '123456789',
        ]);

        $this->assertNotNull($dadosPessoais->id);
        $this->assertNotNull($user->fresh()->dadosPessoais);
        $this->assertSame($dadosPessoais->id, $user->fresh()->dadosPessoais->id);
        $this->assertSame($user->id, $dadosPessoais->user->id);
    }

    public function test_user_can_have_dados_configuracao_and_relation_works(): void
    {
        $user = User::factory()->create();

        $dadosConfiguracao = DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => true,
            'consentimento_rgpd_data' => '2026-06-18 10:00:00',
            'configuracao_extra' => ['fonte' => 'teste'],
        ]);

        $this->assertNotNull($dadosConfiguracao->id);
        $this->assertNotNull($user->fresh()->dadosConfiguracao);
        $this->assertSame($dadosConfiguracao->id, $user->fresh()->dadosConfiguracao->id);
        $this->assertSame($user->id, $dadosConfiguracao->user->id);
    }

    public function test_user_id_is_unique_in_dados_pessoais(): void
    {
        $this->expectException(QueryException::class);

        $user = User::factory()->create();

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Primeiro Registo',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Segundo Registo',
        ]);
    }

    public function test_user_id_is_unique_in_dados_configuracao(): void
    {
        $this->expectException(QueryException::class);

        $user = User::factory()->create();

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => true,
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => false,
        ]);
    }

    public function test_cascade_delete_removes_member_structural_rows(): void
    {
        $user = User::factory()->create();

        $dadosPessoais = DadosPessoais::query()->create([
            'user_id' => $user->id,
            'nome_completo' => 'Membro Cascade',
        ]);

        $dadosConfiguracao = DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => true,
        ]);

        $user->delete();

        $this->assertDatabaseMissing('dados_pessoais', ['id' => $dadosPessoais->id]);
        $this->assertDatabaseMissing('dados_configuracao', ['id' => $dadosConfiguracao->id]);
    }

    public function test_casts_for_boolean_date_and_json_work(): void
    {
        $user = User::factory()->create();

        $dadosPessoais = DadosPessoais::query()->create([
            'user_id' => $user->id,
            'data_nascimento' => '2008-09-10',
            'validade_documento' => '2030-01-31',
        ])->fresh();

        $dadosConfiguracao = DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => 1,
            'consentimento_rgpd_data' => '2026-06-18 11:45:00',
            'afiliacao_data' => '2026-04-01',
            'configuracao_extra' => ['canal' => 'portal', 'versao' => 2],
        ])->fresh();

        $this->assertTrue($dadosConfiguracao->consentimento_rgpd);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $dadosPessoais->data_nascimento);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $dadosPessoais->validade_documento);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $dadosConfiguracao->consentimento_rgpd_data);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $dadosConfiguracao->afiliacao_data);
        $this->assertIsArray($dadosConfiguracao->configuracao_extra);
        $this->assertSame('portal', $dadosConfiguracao->configuracao_extra['canal']);
    }

    public function test_new_tables_exist_and_users_core_fields_remain_available(): void
    {
        $this->assertTrue(Schema::hasTable('dados_pessoais'));
        $this->assertTrue(Schema::hasTable('dados_configuracao'));
        $this->assertTrue(Schema::hasColumns('users', [
            'nome_completo',
            'data_nascimento',
            'sexo',
            'nif',
            'rgpd',
            'consentimento',
            'afiliacao',
            'declaracao_de_transporte',
        ]));
        $this->assertFalse(Schema::hasColumn('users', 'encarregado_educacao'));
        $this->assertFalse(Schema::hasColumn('users', 'educandos'));
    }
}

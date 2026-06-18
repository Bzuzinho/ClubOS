<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\MemberDataReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint M2.3 — Testes da camada de leitura canónica com fallback.
 *
 * Cobre os 16 cenários definidos na sprint:
 * 1.  Ficha usa users quando dados_pessoais não existe.
 * 2.  Ficha usa dados_pessoais quando existe.
 * 3.  Campo vazio em dados_pessoais faz fallback para users.
 * 4.  Boolean false em dados_configuracao é respeitado e não cai para fallback.
 * 5.  Ficha usa dados_configuracao quando existe.
 * 6.  Campo vazio em dados_configuracao faz fallback para users.
 * 7.  role/perfil continua vindo de users.
 * 8.  email de autenticação continua vindo de users.
 * 9.  leitura não cria dados_pessoais.
 * 10. leitura não cria dados_configuracao.
 * 11. leitura não altera users.
 * 12. portal família mantém payload compatível.
 * 13. portal perfil mantém payload compatível, se aplicável.
 * 14. ambiente sem backfill continua funcional via fallback.
 * 15. ambiente com backfill usa novas tabelas.
 * 16. não há alteração de escrita no update da ficha.
 */
class MemberDataReadFallbackTest extends TestCase
{
    use RefreshDatabase;

    private MemberDataReadService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MemberDataReadService::class);
    }

    // -------------------------------------------------------------------------
    // 1. Ficha usa users quando dados_pessoais não existe
    // -------------------------------------------------------------------------

    public function test_personal_payload_falls_back_to_users_when_no_dados_pessoais(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Ana Fallback',
            'nif'           => '123456789',
            'morada'        => 'Rua Fallback 1',
        ]);

        $this->assertNull($user->dadosPessoais);

        $payload = $this->service->personalPayload($user);

        $this->assertSame('Ana Fallback', $payload['nome_completo']);
        $this->assertSame('123456789', $payload['nif']);
        $this->assertSame('Rua Fallback 1', $payload['morada']);
    }

    // -------------------------------------------------------------------------
    // 2. Ficha usa dados_pessoais quando existe
    // -------------------------------------------------------------------------

    public function test_personal_payload_uses_dados_pessoais_when_exists(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Nome Antigo',
            'nif'           => '000000000',
        ]);

        DadosPessoais::query()->create([
            'user_id'       => $user->id,
            'nome_completo' => 'Nome Novo Dados Pessoais',
            'nif'           => '987654321',
        ]);

        $user->refresh()->load('dadosPessoais');

        $payload = $this->service->personalPayload($user);

        $this->assertSame('Nome Novo Dados Pessoais', $payload['nome_completo']);
        $this->assertSame('987654321', $payload['nif']);
    }

    // -------------------------------------------------------------------------
    // 3. Campo vazio em dados_pessoais faz fallback para users
    // -------------------------------------------------------------------------

    public function test_empty_field_in_dados_pessoais_falls_back_to_users(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Nome Reserva',
            'morada'        => 'Morada Reserva',
        ]);

        DadosPessoais::query()->create([
            'user_id'       => $user->id,
            'nome_completo' => 'Nome Dados Pessoais',
            'morada'        => '',   // campo vazio → deve cair para fallback
        ]);

        $user->refresh()->load('dadosPessoais');

        $payload = $this->service->personalPayload($user);

        // nome_completo existe em dados_pessoais — usa nova tabela
        $this->assertSame('Nome Dados Pessoais', $payload['nome_completo']);
        // morada está vazia em dados_pessoais — deve cair para users
        $this->assertSame('Morada Reserva', $payload['morada']);
    }

    // -------------------------------------------------------------------------
    // 4. Boolean false em dados_configuracao é respeitado e não cai para fallback
    // -------------------------------------------------------------------------

    public function test_boolean_false_in_dados_configuracao_is_respected(): void
    {
        $user = User::factory()->create([
            'rgpd'       => true,  // users tem true
            'consentimento' => true,
        ]);

        DadosConfiguracao::query()->create([
            'user_id'             => $user->id,
            'consentimento_rgpd'  => false, // nova tabela tem false explícito
            'consentimento_imagem' => false,
        ]);

        $user->refresh()->load('dadosConfiguracao');

        $payload = $this->service->configurationPayload($user);

        // false é valor válido — não deve cair para o true de users
        $this->assertFalse($payload['consentimento_rgpd']);
        $this->assertFalse($payload['consentimento_imagem']);
    }

    // -------------------------------------------------------------------------
    // 5. Ficha usa dados_configuracao quando existe
    // -------------------------------------------------------------------------

    public function test_configuration_payload_uses_dados_configuracao_when_exists(): void
    {
        $user = User::factory()->create([
            'afiliacao' => false,
        ]);

        DadosConfiguracao::query()->create([
            'user_id'             => $user->id,
            'afiliacao_federativa' => true,
        ]);

        $user->refresh()->load('dadosConfiguracao');

        $payload = $this->service->configurationPayload($user);

        $this->assertTrue($payload['afiliacao_federativa']);
    }

    // -------------------------------------------------------------------------
    // 6. Campo vazio em dados_configuracao faz fallback para users
    // -------------------------------------------------------------------------

    public function test_null_field_in_dados_configuracao_falls_back_to_users(): void
    {
        $user = User::factory()->create([
            'afiliacao' => true,
        ]);

        DadosConfiguracao::query()->create([
            'user_id'              => $user->id,
            'afiliacao_federativa' => null, // null → deve cair para users
        ]);

        $user->refresh()->load('dadosConfiguracao');

        $payload = $this->service->configurationPayload($user);

        // null em dados_configuracao → fallback para users.afiliacao = true
        $this->assertTrue($payload['afiliacao_federativa']);
    }

    // -------------------------------------------------------------------------
    // 7. role/perfil continua vindo de users
    // -------------------------------------------------------------------------

    public function test_perfil_always_comes_from_users(): void
    {
        $user = User::factory()->create([
            'perfil' => 'admin',
        ]);

        DadosPessoais::query()->create([
            'user_id'        => $user->id,
            'tipo_utilizador' => 'treinador',
        ]);

        $user->refresh()->load(['dadosPessoais', 'dadosConfiguracao']);

        $merged = $this->service->mergedMemberPayload($user, $user->toArray());

        // perfil vem sempre de users
        $this->assertSame('admin', $merged['perfil']);
    }

    // -------------------------------------------------------------------------
    // 8. Email de autenticação continua vindo de users
    // -------------------------------------------------------------------------

    public function test_email_utilizador_always_comes_from_users(): void
    {
        $user = User::factory()->create([
            'email_utilizador' => 'real@example.com',
        ]);

        $user->refresh()->load(['dadosPessoais', 'dadosConfiguracao']);

        $merged = $this->service->mergedMemberPayload($user, $user->toArray());

        $this->assertSame('real@example.com', $merged['email_utilizador']);
    }

    // -------------------------------------------------------------------------
    // 9. Leitura não cria dados_pessoais
    // -------------------------------------------------------------------------

    public function test_read_does_not_create_dados_pessoais(): void
    {
        $user = User::factory()->create();

        $countBefore = DadosPessoais::query()->count();

        $user->load(['dadosPessoais', 'dadosConfiguracao']);
        $this->service->personalPayload($user);

        $this->assertSame($countBefore, DadosPessoais::query()->count());
    }

    // -------------------------------------------------------------------------
    // 10. Leitura não cria dados_configuracao
    // -------------------------------------------------------------------------

    public function test_read_does_not_create_dados_configuracao(): void
    {
        $user = User::factory()->create();

        $countBefore = DadosConfiguracao::query()->count();

        $user->load(['dadosPessoais', 'dadosConfiguracao']);
        $this->service->configurationPayload($user);

        $this->assertSame($countBefore, DadosConfiguracao::query()->count());
    }

    // -------------------------------------------------------------------------
    // 11. Leitura não altera users
    // -------------------------------------------------------------------------

    public function test_read_does_not_alter_users(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Original',
            'nif'           => '111111111',
        ]);

        DadosPessoais::query()->create([
            'user_id'       => $user->id,
            'nome_completo' => 'Sobreposição',
            'nif'           => '999999999',
        ]);

        $user->refresh()->load(['dadosPessoais', 'dadosConfiguracao']);
        $this->service->mergedMemberPayload($user, $user->toArray());

        $freshUser = User::query()->find($user->id);
        $this->assertSame('Original', $freshUser->nome_completo);
        $this->assertSame('111111111', $freshUser->nif);
    }

    // -------------------------------------------------------------------------
    // 12. Portal família mantém payload compatível (FamilyPortalController
    //     não lê dados pessoais profundos — confirmar ausência de regressão)
    // -------------------------------------------------------------------------

    public function test_family_portal_payload_compatible_without_dados_pessoais(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Familiar Teste',
        ]);

        // Garantir que a ausência de dados_pessoais não quebra nada
        $this->assertNull($user->dadosPessoais);

        // O serviço deve funcionar com fallback sem erro
        $user->load(['dadosPessoais', 'dadosConfiguracao']);
        $payload = $this->service->personalPayload($user);

        $this->assertSame('Familiar Teste', $payload['nome_completo']);
        $this->assertArrayHasKey('morada', $payload);
        $this->assertArrayHasKey('contacto', $payload);
    }

    // -------------------------------------------------------------------------
    // 13. Portal perfil mantém payload compatível com dados_pessoais preenchidos
    // -------------------------------------------------------------------------

    public function test_portal_profile_payload_compatible_with_dados_pessoais(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Atleta Portal',
            'nif'           => '222222222',
            'morada'        => 'Rua Antiga',
        ]);

        DadosPessoais::query()->create([
            'user_id'       => $user->id,
            'nome_completo' => 'Atleta Portal Atualizado',
            'nif'           => '333333333',
            'morada'        => 'Rua Nova',
        ]);

        $user->refresh()->load(['dadosPessoais', 'dadosConfiguracao']);

        $merged = $this->service->mergedMemberPayload($user, $user->toArray());

        $this->assertSame('Atleta Portal Atualizado', $merged['nome_completo']);
        $this->assertSame('333333333', $merged['nif']);
        $this->assertSame('Rua Nova', $merged['morada']);
        // estado e numero_socio continuam de users
        $this->assertArrayHasKey('estado', $merged);
        $this->assertArrayHasKey('numero_socio', $merged);
    }

    // -------------------------------------------------------------------------
    // 14. Ambiente sem backfill continua funcional via fallback
    // -------------------------------------------------------------------------

    public function test_environment_without_backfill_is_functional_via_fallback(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Membro Sem Backfill',
            'sexo'          => 'feminino',
            'nif'           => '444444444',
            'morada'        => 'Rua Sem Backfill',
            'codigo_postal' => '1234-567',
            'localidade'    => 'Lisboa',
            'nacionalidade' => 'Portuguesa',
        ]);

        // Sem dados_pessoais nem dados_configuracao
        $user->load(['dadosPessoais', 'dadosConfiguracao']);

        $personal = $this->service->personalPayload($user);
        $config   = $this->service->configurationPayload($user);

        $this->assertSame('Membro Sem Backfill', $personal['nome_completo']);
        $this->assertSame('feminino', $personal['sexo']);
        $this->assertSame('444444444', $personal['nif']);
        $this->assertSame('Rua Sem Backfill', $personal['morada']);
        $this->assertSame('1234-567', $personal['codigo_postal']);
        $this->assertSame('Lisboa', $personal['localidade']);
        $this->assertSame('Portuguesa', $personal['nacionalidade']);
        $this->assertIsArray($config);
    }

    // -------------------------------------------------------------------------
    // 15. Ambiente com backfill usa novas tabelas
    // -------------------------------------------------------------------------

    public function test_environment_with_backfill_uses_new_tables(): void
    {
        $user = User::factory()->create([
            'nome_completo' => 'Membro Legado',
            'nif'           => '555555555',
            'rgpd'          => false,
        ]);

        DadosPessoais::query()->create([
            'user_id'       => $user->id,
            'nome_completo' => 'Membro Backfill',
            'nif'           => '666666666',
        ]);

        DadosConfiguracao::query()->create([
            'user_id'            => $user->id,
            'consentimento_rgpd' => true,
        ]);

        $user->refresh()->load(['dadosPessoais', 'dadosConfiguracao']);

        $personal = $this->service->personalPayload($user);
        $config   = $this->service->configurationPayload($user);

        // Usa dados_pessoais
        $this->assertSame('Membro Backfill', $personal['nome_completo']);
        $this->assertSame('666666666', $personal['nif']);

        // Usa dados_configuracao
        $this->assertTrue($config['consentimento_rgpd']);
    }

    // -------------------------------------------------------------------------
    // 16. Não há alteração de escrita no update da ficha (via HTTP)
    // -------------------------------------------------------------------------

    public function test_show_does_not_persist_changes_to_users(): void
    {
        /** @var User $admin */
        $admin = User::factory()->create(['perfil' => 'admin']);
        $member = User::factory()->create([
            'nome_completo' => 'Membro Leitura',
            'nif'           => '777777777',
        ]);

        DadosPessoais::query()->create([
            'user_id'       => $member->id,
            'nome_completo' => 'Membro Leitura Atualizado',
            'nif'           => '888888888',
        ]);

        $this->actingAs($admin);

        $this->get(route('membros.show', $member->id));

        // Garantir que users.nome_completo e users.nif não foram alterados
        $fresh = User::query()->find($member->id);
        $this->assertSame('Membro Leitura', $fresh->nome_completo);
        $this->assertSame('777777777', $fresh->nif);
    }

    // -------------------------------------------------------------------------
    // Auxiliar: valueFromPersonal e valueFromConfiguration em fallback de campo null
    // -------------------------------------------------------------------------

    public function test_value_from_personal_null_field_falls_back(): void
    {
        $user = User::factory()->create([
            'contacto' => '912345678',
        ]);

        DadosPessoais::query()->create([
            'user_id'  => $user->id,
            'contacto' => null,
        ]);

        $user->refresh()->load('dadosPessoais');

        $value = $this->service->valueFromPersonal($user, 'contacto', ['contacto', 'telemovel', 'contacto_telefonico']);

        $this->assertSame('912345678', $value);
    }

    public function test_value_from_configuration_string_zero_is_valid(): void
    {
        $user = User::factory()->create();

        DadosConfiguracao::query()->create([
            'user_id'          => $user->id,
            'afiliacao_numero' => '0',
        ]);

        $user->refresh()->load('dadosConfiguracao');

        $value = $this->service->valueFromConfiguration($user, 'afiliacao_numero', 'num_federacao');

        $this->assertSame('0', $value);
    }
}

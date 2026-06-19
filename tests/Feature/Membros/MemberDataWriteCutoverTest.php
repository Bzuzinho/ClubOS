<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\DadosFinanceiros;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\MemberDataReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MemberDataWriteCutoverTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_creates_missing_personal_and_configuration_rows_and_keeps_legacy_users_synced(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Membro Original',
            'name' => 'Membro Original',
            'email_utilizador' => 'membro.original@example.test',
            'email' => 'membro.original@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M-100',
            'perfil' => 'atleta',
            'ativo_desportivo' => true,
            'num_federacao' => 'FED-001',
            'escalao' => ['sub16'],
        ]);

        $finance = DadosFinanceiros::query()->create([
            'user_id' => $member->id,
            'discount_type' => 'fixed',
            'discount_value' => 10,
            'discount_reason' => 'Manual',
        ]);

        $guardian = User::factory()->create();
        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $member->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->baseUpdatePayload($member, [
            'nome_completo' => 'Membro Atualizado',
            'telefone' => '919000111',
            'nif' => '123456789',
            'morada' => 'Rua Nova 100',
            'codigo_postal' => '4000-100',
            'localidade' => 'Porto',
            'email_secundario' => 'secundario@example.test',
            'notas' => 'Observacao nova',
            'rgpd' => false,
            'consentimento' => false,
            'afiliacao' => true,
            'declaracao_de_transporte' => true,
            'arquivo_afiliacao' => 'members/affiliation/ficha.pdf',
            'declaracao_transporte' => 'members/transport/decl.pdf',
        ]);

        $response = $this->actingAs($admin)
            ->from(route('membros.show', $member))
            ->put(route('membros.update', $member), $payload);

        $response->assertRedirect(route('membros.show', ['member' => $member->id]));

        $member->refresh();
        $personal = DadosPessoais::query()->where('user_id', $member->id)->first();
        $config = DadosConfiguracao::query()->where('user_id', $member->id)->first();

        $this->assertNotNull($personal);
        $this->assertNotNull($config);

        $this->assertSame('Membro Atualizado', $personal->nome_completo);
        $this->assertSame('919000111', $personal->contacto);
        $this->assertSame('Observacao nova', $personal->observacoes);
        $this->assertFalse((bool) $config->consentimento_rgpd);
        $this->assertFalse((bool) $config->consentimento_imagem);
        $this->assertTrue((bool) $config->afiliacao_federativa);
        $this->assertTrue((bool) $config->declaracao_transporte);

        $this->assertSame('Membro Atualizado', $member->nome_completo);
        $this->assertSame('Membro Atualizado', $member->name);
        $this->assertSame('membro.original@example.test', $member->email_utilizador);
        $this->assertSame('membro.original@example.test', $member->email);
        $this->assertSame('atleta', $member->perfil);
        $this->assertSame('ativo', $member->estado);
        $this->assertSame('M-100', $member->numero_socio);

        $this->assertSame('fixed', $finance->fresh()->discount_type);
        $this->assertSame('10.00', $finance->fresh()->discount_value);
        $this->assertSame('Manual', $finance->fresh()->discount_reason);

        $this->assertTrue((bool) $member->ativo_desportivo);
        $this->assertSame('FED-001', $member->num_federacao);
        $this->assertSame(['sub16'], $member->escalao);

        $this->assertSame(1, DB::table('user_guardian')->where('user_id', $member->id)->count());

        $this->assertNotNull($personal->migration_source_hash);
        $this->assertNotNull($config->migration_source_hash);
        $this->assertNotNull($personal->migrated_from_users_at);
        $this->assertNotNull($config->migrated_from_users_at);
    }

    public function test_update_updates_existing_rows_without_creating_duplicates(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Membro Existing',
            'name' => 'Membro Existing',
            'email_utilizador' => 'existing@example.test',
            'email' => 'existing@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M-200',
        ]);

        $personal = DadosPessoais::query()->create([
            'user_id' => $member->id,
            'nome_completo' => 'Antes',
            'morada' => 'Rua Antes',
        ]);

        $config = DadosConfiguracao::query()->create([
            'user_id' => $member->id,
            'consentimento_rgpd' => true,
        ]);

        $payload = $this->baseUpdatePayload($member, [
            'nome_completo' => 'Depois',
            'morada' => 'Rua Depois',
            'rgpd' => false,
        ]);

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        $member->refresh();
        $personal->refresh();
        $config->refresh();

        $this->assertSame('Depois', $personal->nome_completo);
        $this->assertSame('Rua Depois', $personal->morada);
        $this->assertFalse((bool) $config->consentimento_rgpd);

        $this->assertSame($personal->id, DadosPessoais::query()->where('user_id', $member->id)->value('id'));
        $this->assertSame($config->id, DadosConfiguracao::query()->where('user_id', $member->id)->value('id'));
        $this->assertSame(1, DadosPessoais::query()->where('user_id', $member->id)->count());
        $this->assertSame(1, DadosConfiguracao::query()->where('user_id', $member->id)->count());
    }

    public function test_update_does_not_clear_fields_absent_from_request_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Sem Limpeza',
            'name' => 'Sem Limpeza',
            'email_utilizador' => 'sem.limpeza@example.test',
            'email' => 'sem.limpeza@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M-300',
            'morada' => 'Morada Legacy',
            'rgpd' => true,
        ]);

        DadosPessoais::query()->create([
            'user_id' => $member->id,
            'morada' => 'Morada Persistida',
            'contacto' => '910000000',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $member->id,
            'consentimento_rgpd' => true,
            'afiliacao_federativa' => true,
        ]);

        $payload = $this->baseUpdatePayload($member, [
            'nome_completo' => 'Sem Limpeza Atualizado',
            'telefone' => '919999999',
        ]);

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        $personal = DadosPessoais::query()->where('user_id', $member->id)->firstOrFail();
        $config = DadosConfiguracao::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertSame('Morada Persistida', $personal->morada);
        $this->assertSame('919999999', $personal->contacto);
        $this->assertTrue((bool) $config->consentimento_rgpd);
        $this->assertTrue((bool) $config->afiliacao_federativa);
    }

    public function test_update_persists_boolean_false_values_in_configuration_table(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Bool Test',
            'name' => 'Bool Test',
            'email_utilizador' => 'bool.test@example.test',
            'email' => 'bool.test@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M-400',
            'rgpd' => true,
            'consentimento' => true,
        ]);

        $payload = $this->baseUpdatePayload($member, [
            'rgpd' => false,
            'consentimento' => false,
            'declaracao_de_transporte' => false,
        ]);

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        $config = DadosConfiguracao::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertFalse((bool) $config->consentimento_rgpd);
        $this->assertFalse((bool) $config->consentimento_imagem);
        $this->assertFalse((bool) $config->declaracao_transporte);
    }

    public function test_store_creates_personal_and_configuration_rows_and_keeps_users_auth_account_fields(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = [
            'nome_completo' => 'Novo Membro',
            'email_utilizador' => 'novo.membro@example.test',
            'password' => 'password1234',
            'numero_socio' => 'M-500',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'morada' => 'Rua Store 10',
            'rgpd' => true,
            'consentimento' => true,
            'afiliacao' => false,
            'declaracao_de_transporte' => false,
        ];

        $this->actingAs($admin)->post(route('membros.store'), $payload)
            ->assertRedirect(route('membros.index'));

        $member = User::query()->where('numero_socio', 'M-500')->firstOrFail();

        $this->assertNotNull(DadosPessoais::query()->where('user_id', $member->id)->first());
        $this->assertNotNull(DadosConfiguracao::query()->where('user_id', $member->id)->first());

        $this->assertSame('novo.membro@example.test', $member->email_utilizador);
        $this->assertSame('novo.membro@example.test', $member->email);
        $this->assertNotEmpty($member->password);
    }

    public function test_read_service_returns_new_values_after_cutover_update(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Read Before',
            'name' => 'Read Before',
            'email_utilizador' => 'read.before@example.test',
            'email' => 'read.before@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M-600',
            'contacto' => '910000100',
        ]);

        $payload = $this->baseUpdatePayload($member, [
            'nome_completo' => 'Read After',
            'telefone' => '910000200',
            'rgpd' => true,
        ]);

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        $merged = app(MemberDataReadService::class)
            ->mergedMemberPayload($member->fresh()->loadMissing(['dadosPessoais', 'dadosConfiguracao']), $member->fresh()->toArray());

        $this->assertSame('Read After', $merged['nome_completo']);
        $this->assertSame('910000200', $merged['contacto']);
        $this->assertTrue((bool) $merged['rgpd']);
    }

    public function test_fallback_remains_functional_if_dados_pessoais_row_is_removed_after_update(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Fallback Personal',
            'name' => 'Fallback Personal',
            'email_utilizador' => 'fallback.personal@example.test',
            'email' => 'fallback.personal@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M-700',
            'morada' => 'Morada Users',
        ]);

        $payload = $this->baseUpdatePayload($member, [
            'morada' => 'Morada Nova',
        ]);

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        DadosPessoais::query()->where('user_id', $member->id)->delete();

        $result = app(MemberDataReadService::class)->personalPayload($member->fresh());

        $this->assertSame('Morada Nova', $result['morada']);
    }

    public function test_fallback_remains_functional_if_dados_configuracao_row_is_removed_after_update(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Fallback Config',
            'name' => 'Fallback Config',
            'email_utilizador' => 'fallback.config@example.test',
            'email' => 'fallback.config@example.test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'M-800',
            'rgpd' => false,
        ]);

        $payload = $this->baseUpdatePayload($member, [
            'rgpd' => true,
        ]);

        $this->actingAs($admin)->put(route('membros.update', $member), $payload)->assertRedirect();

        DadosConfiguracao::query()->where('user_id', $member->id)->delete();

        $result = app(MemberDataReadService::class)->configurationPayload($member->fresh());

        $this->assertTrue((bool) $result['consentimento_rgpd']);
    }

    public function test_portal_profile_update_applies_dual_write_for_personal_data(): void
    {
        $member = User::factory()->create([
            'nome_completo' => 'Portal Antes',
            'name' => 'Portal Antes',
            'email_utilizador' => 'portal.antes@example.test',
            'email' => 'portal.antes@example.test',
            'sexo' => 'masculino',
            'perfil' => 'atleta',
            'estado' => 'ativo',
            'numero_socio' => 'M-900',
            'morada' => 'Morada Portal Antes',
            'nif' => '123123123',
            'contacto' => '910001111',
        ]);

        $payload = [
            'nome_completo' => 'Portal Depois',
            'data_nascimento' => '2010-05-10',
            'nif' => '999888777',
            'cc' => 'CC123456',
            'morada' => 'Morada Portal Depois',
            'codigo_postal' => '4300-200',
            'localidade' => 'Porto',
            'nacionalidade' => 'Portuguesa',
            'sexo' => 'masculino',
            'contacto' => '910002222',
            'email_secundario' => 'portal.sec@example.test',
        ];

        $this->actingAs($member)
            ->patch(route('portal.profile.update'), $payload)
            ->assertRedirect(route('portal.profile'));

        $member->refresh();
        $personal = DadosPessoais::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertSame('Portal Depois', $member->nome_completo);
        $this->assertSame('Portal Depois', $member->name);
        $this->assertSame('999888777', $member->nif);
        $this->assertSame('Morada Portal Depois', $member->morada);
        $this->assertSame('Portal Depois', $personal->nome_completo);
        $this->assertSame('999888777', $personal->nif);
        $this->assertSame('Morada Portal Depois', $personal->morada);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseUpdatePayload(User $member, array $overrides = []): array
    {
        return array_merge([
            'nome_completo' => $member->nome_completo,
            'email_utilizador' => $member->email_utilizador,
            'numero_socio' => (string) $member->numero_socio,
            'sexo' => $member->sexo,
            'estado' => $member->estado,
            'tipo_membro' => $member->tipo_membro ?? [],
            'perfil' => $member->perfil,
        ], $overrides);
    }
}

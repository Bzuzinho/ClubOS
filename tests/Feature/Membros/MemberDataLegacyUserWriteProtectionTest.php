<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MemberDataLegacyUserWriteProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_store_writes_canonical_data_but_does_not_mirror_full_personal_payload_to_users(): void
    {
        $admin = User::factory()->admin()->create();

        $payload = [
            'nome_completo' => 'Novo Canonico Store',
            'email_utilizador' => 'novo.canonico.store@example.test',
            'password' => 'password1234',
            'numero_socio' => 'M34-100',
            'sexo' => 'feminino',
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'data_nascimento' => '2010-02-15',
            'nif' => '987654321',
            'cc' => 'CC-STORE-001',
            'morada' => 'Rua Canonica Store 12',
            'codigo_postal' => '4000-120',
            'localidade' => 'Porto',
            'nacionalidade' => 'Portuguesa',
            'telefone' => '910000111',
            'email_secundario' => 'sec.store@example.test',
            'rgpd' => true,
            'consentimento' => true,
            'declaracao_de_transporte' => true,
            'afiliacao' => true,
            'num_federacao' => 'FED-STORE-100',
        ];

        $this->actingAs($admin)
            ->post(route('membros.store'), $payload)
            ->assertRedirect(route('membros.index'));

        $member = User::query()->where('numero_socio', 'M34-100')->firstOrFail();
        $personal = DadosPessoais::query()->where('user_id', $member->id)->firstOrFail();
        $config = DadosConfiguracao::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertSame('Novo Canonico Store', $personal->nome_completo);
        $this->assertSame('2010-02-15', optional($personal->data_nascimento)?->format('Y-m-d'));
        $this->assertSame('987654321', $personal->nif);
        $this->assertSame('Rua Canonica Store 12', $personal->morada);
        $this->assertSame('4000-120', $personal->codigo_postal);
        $this->assertSame('Porto', $personal->localidade);
        $this->assertSame('910000111', $personal->contacto);
        $this->assertSame('sec.store@example.test', $personal->email_secundario);

        $this->assertTrue((bool) $config->consentimento_rgpd);
        $this->assertTrue((bool) $config->consentimento_imagem);
        $this->assertTrue((bool) $config->declaracao_transporte);
        $this->assertTrue((bool) $config->afiliacao_federativa);
        $this->assertSame('FED-STORE-100', $config->afiliacao_numero);

        $this->assertSame('Novo Canonico Store', $member->name);
        $this->assertSame('novo.canonico.store@example.test', $member->email);
        $this->assertSame('novo.canonico.store@example.test', $member->email_utilizador);
        $this->assertSame('ativo', $member->estado);
        $this->assertSame('M34-100', $member->numero_socio);

        $this->assertUsersSensitiveColumnsDoNotMatchPayload($member, $payload);
    }

    public function test_member_update_writes_canonical_data_but_does_not_mirror_full_personal_payload_to_users(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'name' => 'Legacy Name Before',
            'nome_completo' => 'Legacy Nome Antes',
            'email' => 'legacy.before@example.test',
            'email_utilizador' => 'legacy.before@example.test',
            'estado' => 'ativo',
            'numero_socio' => 'M34-200',
            'perfil' => 'atleta',
            'sexo' => 'masculino',
            'data_nascimento' => '2008-01-01',
            'nif' => '111111111',
            'cc' => 'CC-OLD-111',
            'morada' => 'Morada Legacy Antiga',
            'codigo_postal' => '1000-001',
            'localidade' => 'Lisboa',
            'nacionalidade' => 'Portuguesa',
            'contacto' => '910000001',
            'contacto_telefonico' => '910000002',
            'email_secundario' => 'legacy.old.secondary@example.test',
            'rgpd' => false,
            'consentimento' => false,
            'declaracao_de_transporte' => false,
            'afiliacao' => false,
            'num_federacao' => 'FED-OLD-200',
        ]);

        $legacyBefore = $this->extractExistingLegacyBaseline($member, [
            'nome_completo',
            'data_nascimento',
            'sexo',
            'nif',
            'cc',
            'documento_identificacao',
            'morada',
            'codigo_postal',
            'localidade',
            'nacionalidade',
            'contacto',
            'contacto_alternativo',
            'contacto_telefonico',
            'telefone',
            'email_secundario',
            'rgpd',
            'consentimento',
            'declaracao_de_transporte',
            'afiliacao',
            'num_federacao',
        ]);

        $payload = [
            'nome_completo' => 'Nome Canonico Depois',
            'email_utilizador' => 'canonical.after@example.test',
            'numero_socio' => 'M34-200',
            'sexo' => 'feminino',
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'perfil' => 'atleta',
            'data_nascimento' => '2011-06-20',
            'nif' => '222333444',
            'cc' => 'CC-NEW-222',
            'morada' => 'Morada Canonica Nova 99',
            'codigo_postal' => '4000-999',
            'localidade' => 'Porto',
            'nacionalidade' => 'Brasileira',
            'telefone' => '919000123',
            'contacto_telefonico' => '919000124',
            'email_secundario' => 'canonical.secondary@example.test',
            'rgpd' => true,
            'consentimento' => true,
            'declaracao_de_transporte' => true,
            'afiliacao' => true,
            'num_federacao' => 'FED-NEW-200',
        ];

        $this->actingAs($admin)
            ->from(route('membros.show', $member))
            ->put(route('membros.update', $member), $payload)
            ->assertRedirect(route('membros.show', ['member' => $member->id]));

        $member->refresh();
        $personal = DadosPessoais::query()->where('user_id', $member->id)->firstOrFail();
        $config = DadosConfiguracao::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertSame('Nome Canonico Depois', $personal->nome_completo);
        $this->assertSame('2011-06-20', optional($personal->data_nascimento)?->format('Y-m-d'));
        $this->assertSame('222333444', $personal->nif);
        $this->assertSame('Morada Canonica Nova 99', $personal->morada);
        $this->assertSame('4000-999', $personal->codigo_postal);
        $this->assertSame('Porto', $personal->localidade);
        $this->assertSame('919000123', $personal->contacto);
        $this->assertSame('canonical.secondary@example.test', $personal->email_secundario);

        $this->assertTrue((bool) $config->consentimento_rgpd);
        $this->assertTrue((bool) $config->consentimento_imagem);
        $this->assertTrue((bool) $config->declaracao_transporte);
        $this->assertTrue((bool) $config->afiliacao_federativa);
        $this->assertSame('FED-NEW-200', $config->afiliacao_numero);

        $this->assertSame('Nome Canonico Depois', $member->name);
        $this->assertSame('canonical.after@example.test', $member->email);
        $this->assertSame('canonical.after@example.test', $member->email_utilizador);
        $this->assertSame('ativo', $member->estado);
        $this->assertSame('M34-200', $member->numero_socio);

        $this->assertUsersColumnsPreservedFromBaseline($member, $legacyBefore);
    }

    public function test_portal_profile_update_writes_canonical_data_but_does_not_mirror_full_personal_payload_to_users(): void
    {
        $member = User::factory()->create([
            'name' => 'Portal Legacy Name',
            'nome_completo' => 'Portal Legacy Nome',
            'email' => 'portal.legacy@example.test',
            'email_utilizador' => 'portal.legacy@example.test',
            'perfil' => 'atleta',
            'estado' => 'ativo',
            'numero_socio' => 'M34-300',
            'sexo' => 'masculino',
            'data_nascimento' => '2009-01-10',
            'nif' => '333222111',
            'cc' => 'CC-PORTAL-OLD',
            'morada' => 'Morada Portal Old',
            'codigo_postal' => '1500-001',
            'localidade' => 'Lisboa',
            'nacionalidade' => 'Portuguesa',
            'contacto' => '917000001',
            'email_secundario' => 'portal.old.secondary@example.test',
            'num_federacao' => 'FED-PORTAL-OLD',
        ]);

        $legacyBefore = $this->extractExistingLegacyBaseline($member, [
            'nome_completo',
            'data_nascimento',
            'sexo',
            'nif',
            'cc',
            'documento_identificacao',
            'morada',
            'codigo_postal',
            'localidade',
            'nacionalidade',
            'contacto',
            'contacto_alternativo',
            'contacto_telefonico',
            'telefone',
            'email_secundario',
            'num_federacao',
        ]);

        $payload = [
            'nome_completo' => 'Portal Canonico Depois',
            'data_nascimento' => '2012-08-09',
            'nif' => '555666777',
            'cc' => 'CC-PORTAL-NEW',
            'morada' => 'Morada Portal Nova',
            'codigo_postal' => '4300-300',
            'localidade' => 'Porto',
            'nacionalidade' => 'Espanhola',
            'sexo' => 'feminino',
            'contacto' => '917123456',
            'email_secundario' => 'portal.new.secondary@example.test',
            'num_federacao' => 'FED-PORTAL-NEW',
        ];

        $this->actingAs($member)
            ->patch(route('portal.profile.update'), $payload)
            ->assertRedirect(route('portal.profile'));

        $member->refresh();
        $personal = DadosPessoais::query()->where('user_id', $member->id)->firstOrFail();

        $this->assertSame('Portal Canonico Depois', $personal->nome_completo);
        $this->assertSame('2012-08-09', optional($personal->data_nascimento)?->format('Y-m-d'));
        $this->assertSame('555666777', $personal->nif);
        $this->assertSame('Morada Portal Nova', $personal->morada);
        $this->assertSame('4300-300', $personal->codigo_postal);
        $this->assertSame('Porto', $personal->localidade);
        $this->assertSame('917123456', $personal->contacto);
        $this->assertSame('portal.new.secondary@example.test', $personal->email_secundario);

        $this->assertSame('Portal Canonico Depois', $member->name);
        $this->assertSame('portal.legacy@example.test', $member->email);
        $this->assertSame('portal.legacy@example.test', $member->email_utilizador);
        $this->assertSame('atleta', $member->perfil);
        $this->assertSame('ativo', $member->estado);
        $this->assertSame('M34-300', $member->numero_socio);

        $this->assertUsersColumnsPreservedFromBaseline($member, $legacyBefore);
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    private function extractExistingLegacyBaseline(User $member, array $columns): array
    {
        $baseline = [];

        foreach ($columns as $column) {
            if (!Schema::hasColumn('users', $column)) {
                continue;
            }

            $baseline[$column] = $member->getAttribute($column);
        }

        return $baseline;
    }

    /**
     * @param  array<string, mixed>  $baseline
     */
    private function assertUsersColumnsPreservedFromBaseline(User $member, array $baseline): void
    {
        foreach ($baseline as $column => $valueBefore) {
            $this->assertEquals(
                $this->normalizeComparableValue($valueBefore),
                $this->normalizeComparableValue($member->getAttribute($column)),
                sprintf('Legacy users.%s was unexpectedly changed by member write flow.', $column)
            );
        }
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertUsersSensitiveColumnsDoNotMatchPayload(User $member, array $payload): void
    {
        $columnToExpectedPayloadValue = [];

        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'nome_completo', ['nome_completo']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'data_nascimento', ['data_nascimento']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'sexo', ['sexo']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'nif', ['nif']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'cc', ['cc', 'documento_identificacao']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'morada', ['morada']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'codigo_postal', ['codigo_postal']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'localidade', ['localidade']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'nacionalidade', ['nacionalidade']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'contacto', ['contacto', 'telefone']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'telefone', ['contacto', 'telefone']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'contacto_telefonico', ['contacto_alternativo', 'contacto_telefonico']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'email_secundario', ['email_secundario']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'rgpd', ['rgpd']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'consentimento', ['consentimento']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'declaracao_de_transporte', ['declaracao_de_transporte']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'afiliacao', ['afiliacao']);
        $this->mapPayloadValue($columnToExpectedPayloadValue, $payload, 'num_federacao', ['num_federacao']);

        foreach ($columnToExpectedPayloadValue as $column => $expectedValue) {
            $this->assertNotEquals(
                $expectedValue,
                $member->getAttribute($column),
                sprintf('Legacy users.%s should not mirror canonical payload value on store.', $column)
            );
        }
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $columns
     */
    private function mapPayloadValue(array &$mapping, array $payload, string $payloadKey, array $columns): void
    {
        if (!array_key_exists($payloadKey, $payload)) {
            return;
        }

        foreach ($columns as $column) {
            if (!Schema::hasColumn('users', $column)) {
                continue;
            }

            $mapping[$column] = $payload[$payloadKey];
        }
    }
}

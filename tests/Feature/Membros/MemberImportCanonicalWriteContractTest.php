<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\DadosFinanceiros;
use App\Models\DadosPessoais;
use App\Models\MonthlyFee;
use App\Models\User;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberImportService;
use App\Services\Financeiro\MemberMonthlyFeeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MemberImportCanonicalWriteContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_canonical_personal_and_configuration_rows(): void
    {
        $row = $this->baseImportRow([
            'email_utilizador' => 'contract.personal.config@example.test',
            'numero_socio' => 'M4F1-001',
        ]);

        $result = app(MemberImportService::class)->import([$row], $this->mappingFor(array_keys($row)));

        $this->assertSame(1, $result['created_count']);
        $this->assertCount(0, $result['errors']);

        $member = User::query()->findOrFail($result['created_ids'][0]);

        $personal = DadosPessoais::query()->where('user_id', $member->id)->first();
        $this->assertNotNull($personal, 'A importacao deve criar dados_pessoais para o membro importado.');

        $config = DadosConfiguracao::query()->where('user_id', $member->id)->first();
        $this->assertNotNull($config, 'A importacao deve criar dados_configuracao para o membro importado.');

        if ($personal !== null) {
            $this->assertSame('Contrato Canonico', $personal->nome_completo);
            $this->assertSame('123456789', $personal->nif);
            $this->assertSame('Rua do Contrato 1', $personal->morada);
            $this->assertSame('4000-100', $personal->codigo_postal);
            $this->assertSame('Porto', $personal->localidade);
            $this->assertSame('910000100', $personal->contacto);
        }

        if ($config !== null) {
            $this->assertTrue((bool) $config->consentimento_rgpd);
            $this->assertTrue((bool) $config->consentimento_imagem);
            $this->assertTrue((bool) $config->afiliacao_federativa);
            $this->assertSame('FED-2026-001', $config->afiliacao_numero);
            $this->assertTrue((bool) $config->declaracao_transporte);
        }
    }

    public function test_import_keeps_users_limited_to_auth_and_operational_fields_and_reads_personal_and_configuration_from_canonical_tables(): void
    {
        $row = $this->baseImportRow([
            'email_utilizador' => 'contract.users.limited@example.test',
            'numero_socio' => 'M4F1-002',
        ]);

        $result = app(MemberImportService::class)->import([$row], $this->mappingFor(array_keys($row)));

        $this->assertSame(1, $result['created_count']);
        $this->assertCount(0, $result['errors']);

        $member = User::query()->findOrFail($result['created_ids'][0]);

        $this->assertSame('Contrato Canonico', $member->name);
        $this->assertSame('contract.users.limited@example.test', $member->email_utilizador);
        $this->assertSame('contract.users.limited@example.test', $member->email);
        $this->assertSame('M4F1-002', (string) $member->numero_socio);
        $this->assertSame('ativo', $member->estado);
        $this->assertSame('atleta', $member->perfil);
        $this->assertContains('atleta', $member->tipo_membro ?? []);
        $this->assertNotEmpty($member->password);
        $this->assertTrue(Hash::check('password123', $member->password));

        // Contract proof: reads must come from canonical tables, not users fallback.
        // If canonical rows are missing, changing legacy users fields will leak into payload and this test fails.
        $member->update([
            'nome_completo' => 'LEGACY USERS NOME',
            'nif' => '999999999',
            'morada' => 'LEGACY USERS MORADA',
            'codigo_postal' => '9999-999',
            'localidade' => 'LEGACY USERS LOCALIDADE',
            'contacto' => '919999999',
            'rgpd' => false,
            'consentimento' => false,
            'afiliacao' => false,
            'num_federacao' => 'LEGACY-FED',
            'declaracao_de_transporte' => false,
        ]);

        $readService = app(MemberDataReadService::class);
        $freshMember = $member->fresh();
        $personalPayload = $readService->personalPayload($freshMember);
        $configurationPayload = $readService->configurationPayload($freshMember);

        $this->assertSame('Contrato Canonico', $personalPayload['nome_completo']);
        $this->assertSame('123456789', $personalPayload['nif']);
        $this->assertSame('Rua do Contrato 1', $personalPayload['morada']);
        $this->assertSame('4000-100', $personalPayload['codigo_postal']);
        $this->assertSame('Porto', $personalPayload['localidade']);
        $this->assertSame('910000100', $personalPayload['contacto']);

        $this->assertTrue((bool) $configurationPayload['consentimento_rgpd']);
        $this->assertTrue((bool) $configurationPayload['consentimento_imagem']);
        $this->assertTrue((bool) $configurationPayload['afiliacao_federativa']);
        $this->assertSame('FED-2026-001', $configurationPayload['afiliacao_numero']);
        $this->assertTrue((bool) $configurationPayload['declaracao_transporte']);
    }

    public function test_import_creates_financial_data_when_tipo_mensalidade_is_mapped(): void
    {
        $monthlyFee = MonthlyFee::query()->create([
            'designacao' => 'Mensalidade Contrato 2026',
            'valor' => 25,
            'ativo' => true,
        ]);

        $row = $this->baseImportRow([
            'email_utilizador' => 'contract.financial@example.test',
            'numero_socio' => 'M4F1-003',
            'tipo_mensalidade' => 'Mensalidade Contrato 2026',
        ]);

        $result = app(MemberImportService::class)->import([$row], $this->mappingFor(array_keys($row)));

        $this->assertSame(1, $result['created_count']);
        $this->assertCount(0, $result['errors']);

        $member = User::query()->findOrFail($result['created_ids'][0]);
        $financeData = DadosFinanceiros::query()->where('user_id', $member->id)->first();

        $this->assertNotNull($financeData);

        if ($financeData !== null) {
            $this->assertSame($monthlyFee->id, $financeData->mensalidade_id);
        }

        $this->assertSame(
            $monthlyFee->id,
            app(MemberMonthlyFeeResolver::class)->resolveForUser($member->fresh('dadosFinanceiros'))
        );
    }

    public function test_import_ignores_conta_corrente_manual_with_warning(): void
    {
        $row = $this->baseImportRow([
            'email_utilizador' => 'contract.warning@example.test',
            'numero_socio' => 'M4F1-004',
            'conta_corrente_manual' => '123,45',
        ]);

        $result = app(MemberImportService::class)->import([$row], $this->mappingFor(array_keys($row)));

        $this->assertSame(1, $result['created_count']);
        $this->assertCount(0, $result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertTrue(
            collect($result['warnings'])->contains(
                fn (array $warning): bool => ($warning['field'] ?? null) === 'conta_corrente_manual'
            ),
            'A importacao deve devolver warning para conta_corrente_manual.'
        );

        $member = User::query()->findOrFail($result['created_ids'][0]);
        $this->assertNull($member->dadosFinanceiros, 'Sem tipo_mensalidade, nao deve criar dados_financeiros por causa de conta_corrente_manual.');
    }

    public function test_import_generated_member_can_be_read_by_member_data_read_service(): void
    {
        $row = $this->baseImportRow([
            'email_utilizador' => 'contract.read.service@example.test',
            'numero_socio' => 'M4F1-005',
        ]);

        $result = app(MemberImportService::class)->import([$row], $this->mappingFor(array_keys($row)));

        $this->assertSame(1, $result['created_count']);
        $this->assertCount(0, $result['errors']);

        $member = User::query()->findOrFail($result['created_ids'][0]);
        $readService = app(MemberDataReadService::class);

        $personalPayload = $readService->personalPayload($member);
        $configurationPayload = $readService->configurationPayload($member);

        $this->assertSame('Contrato Canonico', $personalPayload['nome_completo']);
        $this->assertSame('123456789', $personalPayload['nif']);
        $this->assertSame('Rua do Contrato 1', $personalPayload['morada']);
        $this->assertSame('4000-100', $personalPayload['codigo_postal']);
        $this->assertSame('Porto', $personalPayload['localidade']);
        $this->assertSame('910000100', $personalPayload['contacto']);

        $this->assertTrue((bool) $configurationPayload['consentimento_rgpd']);
        $this->assertTrue((bool) $configurationPayload['consentimento_imagem']);
        $this->assertTrue((bool) $configurationPayload['afiliacao_federativa']);
        $this->assertSame('FED-2026-001', $configurationPayload['afiliacao_numero']);
        $this->assertTrue((bool) $configurationPayload['declaracao_transporte']);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseImportRow(array $overrides = []): array
    {
        return array_merge([
            'nome_completo' => 'Contrato Canonico',
            'data_nascimento' => '2008-05-01',
            'sexo' => 'masculino',
            'nif' => '123456789',
            'morada' => 'Rua do Contrato 1',
            'codigo_postal' => '4000-100',
            'localidade' => 'Porto',
            'contacto' => '910000100',
            'email_utilizador' => 'contract.default@example.test',
            'numero_socio' => 'M4F1-000',
            'rgpd' => 'sim',
            'consentimento' => 'sim',
            'afiliacao' => 'sim',
            'num_federacao' => 'FED-2026-001',
            'declaracao_de_transporte' => 'sim',
            'estado' => 'ativo',
            'perfil' => 'atleta',
            'tipo_membro' => 'atleta',
        ], $overrides);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function mappingFor(array $fields): array
    {
        $mapping = [];

        foreach ($fields as $field) {
            $mapping[$field] = $field;
        }

        return $mapping;
    }
}

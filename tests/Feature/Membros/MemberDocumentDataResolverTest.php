<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Models\DadosConfiguracao;
use App\Models\User;
use App\Models\UserDocument;
use App\Services\Members\MemberDocumentDataResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MemberDocumentDataResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_documents_prefers_canonical_configuration_values_for_rgpd_and_preserves_false_boolean(): void
    {
        $user = User::factory()->create([
            'rgpd' => true,
            'data_rgpd' => '2026-01-01',
            'arquivo_rgpd' => 'legacy/rgpd-user.pdf',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => false,
            'consentimento_rgpd_data' => '2026-05-20 10:00:00',
        ]);

        $profileDocuments = app(MemberDocumentDataResolver::class)->profileDocuments($user->fresh());

        $this->assertFalse($profileDocuments['rgpd']['is_validated']);
        $this->assertSame('2026-05-20T10:00:00+00:00', $profileDocuments['rgpd']['validated_at']);
    }

    public function test_profile_documents_preserves_image_transport_consent_or_behavior(): void
    {
        $user = User::factory()->create([
            'consentimento' => false,
            'declaracao_de_transporte' => false,
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_imagem' => false,
            'declaracao_transporte' => true,
            'consentimento_imagem_data' => '2026-03-11 09:30:00',
        ]);

        $profileDocuments = app(MemberDocumentDataResolver::class)->profileDocuments($user->fresh());

        $this->assertTrue($profileDocuments['consentimento']['is_validated']);
        $this->assertSame('2026-03-11T09:30:00+00:00', $profileDocuments['consentimento']['validated_at']);
        $this->assertTrue($profileDocuments['declaracao_transporte']['is_validated']);
    }

    public function test_profile_documents_resolves_federation_status_number_and_date_from_canonical_configuration(): void
    {
        $user = User::factory()->create([
            'afiliacao' => false,
            'num_federacao' => null,
            'cartao_federacao' => null,
            'data_afiliacao' => null,
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'afiliacao_federativa' => true,
            'afiliacao_numero' => 'FED-2026-001',
            'afiliacao_data' => '2026-02-14',
            'afiliacao_ficheiro' => 'canonical/afiliacao.pdf',
        ]);

        $profileDocuments = app(MemberDocumentDataResolver::class)->profileDocuments($user->fresh());

        $this->assertTrue($profileDocuments['federacao']['is_validated']);
        $this->assertSame('2026-02-14', $profileDocuments['federacao']['validated_at']);
        $this->assertSame('FED-2026-001', $profileDocuments['federacao']['numero']);
        $this->assertTrue($profileDocuments['afiliacao']['is_validated']);
        $this->assertSame('2026-02-14', $profileDocuments['afiliacao']['validated_at']);
        $this->assertSame('FED-2026-001', $profileDocuments['afiliacao']['numero']);
    }

    public function test_profile_documents_preserves_medical_attestation_date_and_validation_state(): void
    {
        $user = User::factory()->create([
            'data_atestado_medico' => '2099-01-31',
        ]);

        $profileDocuments = app(MemberDocumentDataResolver::class)->profileDocuments($user->fresh());

        $this->assertTrue($profileDocuments['atestado']['is_validated']);
        $this->assertSame('2099-01-31', $profileDocuments['atestado']['validated_at']);
        $this->assertSame('2099-01-31', $profileDocuments['atestado']['valid_until']);
    }

    public function test_resolver_provides_transitional_legacy_paths_when_canonical_path_is_absent(): void
    {
        $user = User::factory()->create([
            'arquivo_rgpd' => 'legacy/rgpd-fallback.pdf',
            'arquivo_consentimento' => 'legacy/consentimento-fallback.pdf',
            'declaracao_transporte' => 'legacy/declaracao-transporte.pdf',
            'arquivo_atestado_medico' => ['legacy/atestado-fallback.pdf'],
            'cartao_federacao' => 'legacy/cartao-federacao.pdf',
            'arquivo_afiliacao' => 'legacy/afiliacao-fallback.pdf',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'certificado_medico_ficheiro' => null,
            'declaracao_transporte_ficheiro' => null,
            'afiliacao_ficheiro' => null,
        ]);

        $paths = app(MemberDocumentDataResolver::class)->documentPaths($user->fresh());

        $this->assertSame('legacy/rgpd-fallback.pdf', $paths['rgpd']);
        $this->assertSame('legacy/consentimento-fallback.pdf', $paths['consentimento']);
        $this->assertSame('legacy/atestado-fallback.pdf', $paths['atestado']);
        $this->assertSame('legacy/cartao-federacao.pdf', $paths['cartao_federacao']);
        $this->assertSame('legacy/declaracao-transporte.pdf', $paths['declaracao_transporte']);
        $this->assertSame('legacy/afiliacao-fallback.pdf', $paths['afiliacao']);
    }

    public function test_document_paths_prefer_canonical_configuration_values_when_available(): void
    {
        $user = User::factory()->create([
            'declaracao_transporte' => 'legacy/declaracao-transporte.pdf',
            'arquivo_afiliacao' => 'legacy/afiliacao-fallback.pdf',
            'cartao_federacao' => null,
            'arquivo_atestado_medico' => 'legacy/atestado-fallback.pdf',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'declaracao_transporte_ficheiro' => 'canonical/declaracao-transporte.pdf',
            'afiliacao_ficheiro' => 'canonical/afiliacao.pdf',
            'certificado_medico_ficheiro' => 'canonical/atestado.pdf',
        ]);

        $paths = app(MemberDocumentDataResolver::class)->documentPaths($user->fresh());

        $this->assertSame('canonical/declaracao-transporte.pdf', $paths['declaracao_transporte']);
        $this->assertSame('canonical/declaracao-transporte.pdf', $paths['consentimento']);
        $this->assertSame('canonical/afiliacao.pdf', $paths['afiliacao']);
        $this->assertSame('canonical/afiliacao.pdf', $paths['cartao_federacao']);
        $this->assertSame('canonical/atestado.pdf', $paths['atestado']);
    }

    public function test_resolver_does_not_persist_anything(): void
    {
        $user = User::factory()->create([
            'arquivo_rgpd' => 'legacy/no-write.pdf',
        ]);

        $config = DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => true,
            'consentimento_rgpd_data' => '2026-04-04 12:00:00',
        ]);

        UserDocument::query()->create([
            'user_id' => $user->id,
            'type' => 'rgpd',
            'name' => 'RGPD',
            'file_path' => 'private/user-documents/rgpd.pdf',
        ]);

        $userUpdatedAtBefore = $user->updated_at;
        $configUpdatedAtBefore = $config->updated_at;
        $documentsCountBefore = UserDocument::query()->count();

        $resolver = app(MemberDocumentDataResolver::class);
        $resolver->resolve($user->fresh());
        $resolver->documentPaths($user->fresh());

        $this->assertSame($documentsCountBefore, UserDocument::query()->count());
        $this->assertTrue($user->fresh()->updated_at?->equalTo($userUpdatedAtBefore));
        $this->assertTrue($config->fresh()->updated_at?->equalTo($configUpdatedAtBefore));
    }
}

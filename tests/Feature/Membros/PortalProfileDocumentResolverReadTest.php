<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosConfiguracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PortalProfileDocumentResolverReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_legacy_read_scanner_reports_no_document_findings_for_portal_profile_controller(): void
    {
        $this->assertNotContains(
            'app/Http/Controllers/PortalProfileController.php',
            app(\App\Services\Members\UsersLegacyReadScanner::class)->defaultAllowlist(),
        );

        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Http/Controllers/PortalProfileController.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $documentFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_documents_configuration'
        );

        $this->assertCount(0, $documentFindings);
    }

    public function test_portal_profile_keeps_document_payload_keys_via_member_document_data_resolver(): void
    {
        $member = User::factory()->athlete()->create([
            'perfil' => 'user',
            'tipo_membro' => ['atleta'],
            'rgpd' => true,
            'data_rgpd' => '2020-01-01',
            'consentimento' => false,
            'declaracao_de_transporte' => false,
            'data_consentimento' => '2020-01-02',
            'num_federacao' => 'LEGACY-FED-OLD',
            'data_afiliacao' => '2020-01-03',
            'cartao_federacao' => null,
            'data_atestado_medico' => '2099-12-31',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $member->id,
            'consentimento_rgpd' => false,
            'consentimento_rgpd_data' => '2026-05-20 10:00:00',
            'consentimento_imagem' => false,
            'declaracao_transporte' => true,
            'consentimento_imagem_data' => '2026-05-21 11:30:00',
            'afiliacao_federativa' => true,
            'afiliacao_numero' => 'FED-CANON-42',
            'afiliacao_data' => '2026-05-22',
        ]);

        $response = $this->inertiaGetAs($member, route('portal.profile'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Profile');
        $response->assertJsonPath('props.profile.editable.num_federacao', 'FED-CANON-42');

        $sports = collect($response->json('props.profile.sports'));
        $documents = collect($response->json('props.profile.documents'));

        $federationSport = $sports->firstWhere('label', 'N.º federação');
        $rgpd = $documents->firstWhere('label', 'RGPD');
        $consentimento = $documents->firstWhere('label', 'Consentimento imagem/transporte');
        $atestado = $documents->firstWhere('label', 'Atestado médico');
        $federacao = $documents->firstWhere('label', 'Cartão federação');

        $this->assertIsArray($federationSport);
        $this->assertSame('FED-CANON-42', data_get($federationSport, 'value'));

        $this->assertIsArray($rgpd);
        $this->assertSame('pending', data_get($rgpd, 'status'));
        $this->assertSame('20/05/2026', data_get($rgpd, 'meta'));

        $this->assertIsArray($consentimento);
        $this->assertSame('valid', data_get($consentimento, 'status'));
        $this->assertSame('21/05/2026', data_get($consentimento, 'meta'));

        $this->assertIsArray($atestado);
        $this->assertSame('valid', data_get($atestado, 'status'));
        $this->assertSame('31/12/2099', data_get($atestado, 'meta'));

        $this->assertIsArray($federacao);
        $this->assertSame('valid', data_get($federacao, 'status'));
        $this->assertSame('22/05/2026', data_get($federacao, 'meta'));
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
     * @return array<string, mixed>
     */
    private function decodeArtisanJsonOutput(string $output): array
    {
        $decoded = json_decode(trim($output), true);

        $this->assertIsArray($decoded, 'Command output should be valid JSON.');

        return $decoded;
    }
}

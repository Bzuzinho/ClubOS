<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosConfiguracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PortalDocumentControllerCanonicalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_documents_uses_resolver_with_canonical_configuration_values(): void
    {
        $user = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['atleta'],
            'rgpd' => true,
            'data_rgpd' => '2026-01-01',
            'arquivo_rgpd' => 'legacy/rgpd.pdf',
            'consentimento' => false,
            'declaracao_de_transporte' => false,
            'arquivo_consentimento' => null,
            'declaracao_transporte' => 'legacy/declaracao.pdf',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $user->id,
            'consentimento_rgpd' => false,
            'consentimento_imagem' => false,
            'declaracao_transporte' => true,
            'consentimento_imagem_data' => '2026-05-20 08:00:00',
        ]);

        $response = $this->inertiaGetAs($user, route('portal.documents'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Documents');

        $documents = collect($response->json('props.documents_overview.documents'));

        $rgpd = $documents->firstWhere('type', 'rgpd');
        $consentimento = $documents->firstWhere('type', 'consentimento');

        $this->assertIsArray($rgpd);
        $this->assertIsArray($consentimento);
        $this->assertSame('pending', data_get($rgpd, 'status.key'));
        $this->assertSame('valid', data_get($consentimento, 'status.key'));
        $this->assertNotNull(data_get($rgpd, 'actions.view_url'));
    }

    public function test_users_legacy_read_scanner_reports_no_findings_for_portal_document_controller(): void
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Http/Controllers/PortalDocumentController.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());

        $this->assertSame(0, $payload['summary']['findings_count'] ?? -1);
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

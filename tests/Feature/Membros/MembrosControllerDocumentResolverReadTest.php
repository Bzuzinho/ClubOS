<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosConfiguracao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class MembrosControllerDocumentResolverReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_membros_controller_keeps_document_payload_keys_via_resolver(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'cartao_federacao' => 'legacy/cartao-federacao.pdf',
            'arquivo_rgpd' => 'legacy/rgpd.pdf',
            'arquivo_consentimento' => 'legacy/consentimento.pdf',
            'arquivo_afiliacao' => 'legacy/afiliacao.pdf',
            'declaracao_transporte' => 'legacy/declaracao-transporte.pdf',
            'data_atestado_medico' => '2026-05-04',
        ]);

        DadosConfiguracao::query()->create([
            'user_id' => $member->id,
            'consentimento_rgpd' => false,
            'consentimento_rgpd_data' => '2026-05-01 09:00:00',
            'consentimento_imagem' => true,
            'consentimento_imagem_data' => '2026-05-02 11:30:00',
            'declaracao_transporte' => true,
            'declaracao_transporte_ficheiro' => 'canonical/declaracao-transporte.pdf',
            'afiliacao_federativa' => true,
            'afiliacao_data' => '2026-05-03',
            'afiliacao_ficheiro' => 'canonical/afiliacao.pdf',
        ]);

        $show = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));
        $show->assertOk();
        $show->assertJsonPath('component', 'Membros/Show');
        $show->assertJsonPath('props.member.cartao_federacao', 'legacy/cartao-federacao.pdf');
        $show->assertJsonPath('props.member.arquivo_rgpd', 'legacy/rgpd.pdf');
        $show->assertJsonPath('props.member.arquivo_consentimento', 'legacy/consentimento.pdf');
        $show->assertJsonPath('props.member.arquivo_afiliacao', 'canonical/afiliacao.pdf');
        $show->assertJsonPath('props.member.declaracao_transporte', 'canonical/declaracao-transporte.pdf');
        $show->assertJsonPath('props.member.declaracao_de_transporte', true);
        $show->assertJsonPath('props.member.data_rgpd', '2026-05-01T09:00:00+00:00');
        $show->assertJsonPath('props.member.data_consentimento', '2026-05-02T11:30:00+00:00');
        $show->assertJsonPath('props.member.data_afiliacao', '2026-05-03');
        $show->assertJsonPath('props.member.data_atestado_medico', '2026-05-04');

        $edit = $this->inertiaGetAs($admin, route('membros.edit', ['member' => $member->id]));
        $edit->assertOk();
        $edit->assertJsonPath('component', 'Membros/Edit');
        $edit->assertJsonPath('props.member.cartao_federacao', 'legacy/cartao-federacao.pdf');
        $edit->assertJsonPath('props.member.arquivo_rgpd', 'legacy/rgpd.pdf');
        $edit->assertJsonPath('props.member.arquivo_consentimento', 'legacy/consentimento.pdf');
        $edit->assertJsonPath('props.member.arquivo_afiliacao', 'canonical/afiliacao.pdf');
        $edit->assertJsonPath('props.member.declaracao_transporte', 'canonical/declaracao-transporte.pdf');
        $edit->assertJsonPath('props.member.declaracao_de_transporte', true);
        $edit->assertJsonPath('props.member.data_rgpd', '2026-05-01T09:00:00+00:00');
        $edit->assertJsonPath('props.member.data_consentimento', '2026-05-02T11:30:00+00:00');
        $edit->assertJsonPath('props.member.data_afiliacao', '2026-05-03');
        $edit->assertJsonPath('props.member.data_atestado_medico', '2026-05-04');
    }

    public function test_users_legacy_read_scanner_reports_no_document_findings_for_membros_controller(): void
    {
        $this->assertNotContains(
            'app/Http/Controllers/MembrosController.php',
            app(\App\Services\Members\UsersLegacyReadScanner::class)->defaultAllowlist(),
        );

        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Http/Controllers/MembrosController.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $documentFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_documents_configuration'
        );

        $this->assertCount(0, $documentFindings);
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
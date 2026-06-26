<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class PortalProfilePersonalCanonicalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_legacy_read_scanner_reports_no_personal_profile_findings_for_portal_profile_controller(): void
    {
        $this->assertNotContains(
            'app/Http/Controllers/PortalProfileController.php',
            app(UsersLegacyReadScanner::class)->defaultAllowlist(),
        );

        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => ['app/Http/Controllers/PortalProfileController.php'],
        ]);

        $this->assertSame(0, $exitCode);

        $payload = $this->decodeArtisanJsonOutput(Artisan::output());
        $personalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_personal_profile'
        );

        $this->assertCount(0, $personalFindings);
    }

    public function test_portal_profile_prefers_canonical_personal_payload_values_over_legacy_users_fields(): void
    {
        $member = User::factory()->create([
            'perfil' => 'user',
            'name' => 'Nome Legacy Auth',
            'nome_completo' => 'Nome Legacy Perfil',
            'data_nascimento' => '1990-01-15',
            'nif' => '111111111',
            'cc' => 'LEGACY-CC',
            'morada' => 'Rua Legacy 1',
            'codigo_postal' => '1000-100',
            'localidade' => 'Lisboa Legacy',
            'nacionalidade' => 'Legacyland',
            'sexo' => 'feminino',
            'contacto' => '910000000',
            'telemovel' => '911111111',
            'contacto_telefonico' => '912222222',
            'email_secundario' => 'legacy@example.test',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $member->id,
            'nome_completo' => 'Nome Canonico Perfil',
            'data_nascimento' => '2005-04-03',
            'nif' => '999999999',
            'documento_identificacao' => 'CANON-CC-42',
            'morada' => 'Rua Canonica 99',
            'codigo_postal' => '4000-123',
            'localidade' => 'Porto Canonico',
            'nacionalidade' => 'Portugal',
            'sexo' => 'masculino',
            'contacto' => '934567890',
            'email_secundario' => 'canonico@example.test',
        ]);

        $response = $this->inertiaGetAs($member, route('portal.profile'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Profile');
        $response->assertJsonPath('props.profile.editable.nome_completo', 'Nome Canonico Perfil');
        $response->assertJsonPath('props.profile.editable.data_nascimento', '2005-04-03');
        $response->assertJsonPath('props.profile.editable.nif', '999999999');
        $response->assertJsonPath('props.profile.editable.cc', 'CANON-CC-42');
        $response->assertJsonPath('props.profile.editable.morada', 'Rua Canonica 99');
        $response->assertJsonPath('props.profile.editable.codigo_postal', '4000-123');
        $response->assertJsonPath('props.profile.editable.localidade', 'Porto Canonico');
        $response->assertJsonPath('props.profile.editable.nacionalidade', 'Portugal');
        $response->assertJsonPath('props.profile.editable.sexo', 'masculino');
        $response->assertJsonPath('props.profile.editable.contacto', '934567890');
        $response->assertJsonPath('props.profile.editable.email_secundario', 'canonico@example.test');

        $personal = collect($response->json('props.profile.personal'));

        $this->assertSame('Nome Canonico Perfil', data_get($personal->firstWhere('label', 'Nome completo'), 'value'));
        $this->assertSame('03/04/2005', data_get($personal->firstWhere('label', 'Data de nascimento'), 'value'));
        $this->assertSame('999999999', data_get($personal->firstWhere('label', 'NIF'), 'value'));
        $this->assertSame('CANON-CC-42', data_get($personal->firstWhere('label', 'CC'), 'value'));
        $this->assertSame('Rua Canonica 99', data_get($personal->firstWhere('label', 'Morada'), 'value'));
        $this->assertSame('934567890', data_get($personal->firstWhere('label', 'Contacto'), 'value'));
        $this->assertSame('Masculino', data_get($personal->firstWhere('label', 'Sexo'), 'value'));
        $this->assertSame('canonico@example.test', data_get($personal->firstWhere('label', 'Email secundário'), 'value'));
    }

    public function test_portal_profile_editable_sex_is_null_for_invalid_canonical_value_and_display_is_humanized(): void
    {
        $member = User::factory()->create([
            'perfil' => 'user',
            'sexo' => 'masculino',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $member->id,
            'sexo' => 'nao_binario',
            'data_nascimento' => '2008-08-09',
        ]);

        $response = $this->inertiaGetAs($member, route('portal.profile'));

        $response->assertOk();
        $response->assertJsonPath('props.profile.editable.sexo', null);
        $response->assertJsonPath('props.profile.editable.data_nascimento', '2008-08-09');

        $personal = collect($response->json('props.profile.personal'));

        $this->assertSame('Nao Binario', data_get($personal->firstWhere('label', 'Sexo'), 'value'));
        $this->assertSame('09/08/2008', data_get($personal->firstWhere('label', 'Data de nascimento'), 'value'));
    }

    public function test_portal_profile_birthdate_display_handles_null_without_breaking(): void
    {
        $member = User::factory()->create([
            'perfil' => 'user',
            'data_nascimento' => null,
        ]);

        DadosPessoais::query()->create([
            'user_id' => $member->id,
            'data_nascimento' => null,
        ]);

        $response = $this->inertiaGetAs($member, route('portal.profile'));

        $response->assertOk();
        $response->assertJsonPath('props.profile.editable.data_nascimento', null);

        $personal = collect($response->json('props.profile.personal'));

        $this->assertSame('Sem informação', data_get($personal->firstWhere('label', 'Data de nascimento'), 'value'));
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
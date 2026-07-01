<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class MembrosControllerPersonalCanonicalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_prefers_canonical_personal_data_for_all_users_payload(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $dependent = User::factory()->create([
            'name' => 'Legacy Fallback Name',
            'nome_completo' => 'Legacy Full Name',
            'data_nascimento' => '1990-05-03',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $dependent->id,
            'nome_completo' => 'Canonical Personal Name',
            'data_nascimento' => '2001-02-04',
        ]);

        $member->educandos()->sync([$dependent->id]);

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));

        $response->assertOk();
        $response->assertJsonPath('component', 'Membros/Show');

        $allUsers = collect($response->json('props.allUsers'));
        $dependentEntry = $allUsers->firstWhere('id', $dependent->id);

        $this->assertIsArray($dependentEntry);
        $this->assertSame('Canonical Personal Name', $dependentEntry['nome_completo']);
        $this->assertSame('2001-02-04', $dependentEntry['data_nascimento']);
    }

    public function test_show_falls_back_safely_when_canonical_personal_data_is_missing(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $dependent = User::factory()->create([
            'name' => 'Fallback Name',
            'nome_completo' => null,
            'data_nascimento' => '1988-11-12',
        ]);

        $member->educandos()->sync([$dependent->id]);

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));

        $response->assertOk();

        $allUsers = collect($response->json('props.allUsers'));
        $dependentEntry = $allUsers->firstWhere('id', $dependent->id);

        $this->assertIsArray($dependentEntry);
        $this->assertSame('Fallback Name', $dependentEntry['nome_completo']);
        $this->assertSame('1988-11-12', $dependentEntry['data_nascimento']);
    }

    public function test_users_legacy_read_scanner_reports_no_personal_profile_findings_for_membros_controller(): void
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
        $personalFindings = collect($payload['findings'] ?? [])->filter(
            fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_personal_profile'
        );

        $this->assertCount(0, $personalFindings);
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

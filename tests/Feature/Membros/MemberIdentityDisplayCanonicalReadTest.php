<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosPessoais;
use App\Models\User;
use App\Services\Family\FamilyService;
use App\Services\Members\UsersLegacyReadScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MemberIdentityDisplayCanonicalReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanner_reports_no_member_identity_display_findings_for_target_controllers_services_and_model(): void
    {
        $paths = [
            'app/Http/Controllers/ConfiguracoesController.php',
            'app/Http/Controllers/DashboardController.php',
            'app/Http/Controllers/EquipasController.php',
            'app/Http/Controllers/EventosController.php',
            'app/Http/Controllers/FamilyPortalController.php',
            'app/Http/Controllers/LogisticaController.php',
            'app/Http/Controllers/MembrosController.php',
            'app/Http/Controllers/PortalEventController.php',
            'app/Http/Controllers/PortalPageController.php',
            'app/Http/Controllers/PortalProfileController.php',
            'app/Http/Controllers/PortalTrainingController.php',
            'app/Services/Family/FamilyService.php',
            'app/Models/ConvocationGroup.php',
            'app/Services/Members/MemberDataMigrationService.php',
        ];

        foreach ($paths as $path) {
            $payload = $this->runAuditForPath($path);
            $identityFindings = collect($payload['findings'] ?? [])->filter(
                fn (array $finding): bool => ($finding['remediation_group'] ?? null) === 'member_identity_display'
            );

            $this->assertCount(0, $identityFindings, sprintf('Unexpected identity display findings in %s', $path));
        }
    }

    public function test_default_allowlist_does_not_include_updated_controllers_services_or_model(): void
    {
        $allowlist = app(UsersLegacyReadScanner::class)->defaultAllowlist();

        $this->assertNotContains('app/Http/Controllers/MembrosController.php', $allowlist);
        $this->assertNotContains('app/Http/Controllers/PortalProfileController.php', $allowlist);
        $this->assertNotContains('app/Http/Controllers/FamilyPortalController.php', $allowlist);
        $this->assertNotContains('app/Http/Controllers/LogisticaController.php', $allowlist);
        $this->assertNotContains('app/Services/Family/FamilyService.php', $allowlist);
        $this->assertNotContains('app/Models/ConvocationGroup.php', $allowlist);
    }

    public function test_membros_index_uses_canonical_display_name_when_different_from_legacy_user_nome_completo(): void
    {
        $admin = User::factory()->admin()->create();

        $member = User::factory()->create([
            'name' => 'Auth Legacy Name',
            'nome_completo' => 'Legacy Display Name',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $member->id,
            'nome_completo' => 'Canonical Display Name',
        ]);

        Cache::forget('membros:list');
        Cache::forget('membros:stats');

        $response = $this->inertiaGetAs($admin, route('membros.index'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Membros/Index');

        $memberEntry = collect($response->json('props.members'))
            ->firstWhere('id', $member->id);

        $this->assertIsArray($memberEntry);
        $this->assertSame('Canonical Display Name', $memberEntry['nome_completo']);
    }

    public function test_family_service_uses_canonical_display_name_for_legacy_family_member_names(): void
    {
        $guardian = User::factory()->create([
            'name' => 'Guardian Legacy Auth',
            'nome_completo' => 'Guardian Legacy Display',
        ]);

        $educando = User::factory()->create([
            'name' => 'Dependent Legacy Auth',
            'nome_completo' => 'Dependent Legacy Display',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $guardian->id,
            'nome_completo' => 'Guardian Canonical Display',
        ]);

        DadosPessoais::query()->create([
            'user_id' => $educando->id,
            'nome_completo' => 'Dependent Canonical Display',
        ]);

        $guardian->educandos()->sync([$educando->id]);

        /** @var FamilyService $familyService */
        $familyService = app(FamilyService::class);
        $families = $familyService->familiesForPortal($guardian);

        $this->assertTrue($families->isNotEmpty());

        $family = $families->first();
        $this->assertIsArray($family);

        $names = collect($family['members'] ?? [])->pluck('name')->all();

        $this->assertContains('Guardian Canonical Display', $names);
        $this->assertContains('Dependent Canonical Display', $names);
    }

    /**
     * @return array<string, mixed>
     */
    private function runAuditForPath(string $path): array
    {
        $exitCode = Artisan::call('members:audit-users-legacy-read', [
            '--json' => true,
            '--path' => [$path],
        ]);

        $this->assertSame(0, $exitCode);

        $decoded = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($decoded, 'Command output should be valid JSON.');

        return $decoded;
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
}

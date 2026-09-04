<?php

namespace Tests\Feature\Portal;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalUserUxContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_sources_keep_family_agenda_profile_and_navigation_ux_contract(): void
    {
        $family = file_get_contents(resource_path('js/Pages/Portal/Family.tsx'));
        $agenda = file_get_contents(resource_path('js/Pages/Portal/Events.tsx'));
        $profile = file_get_contents(resource_path('js/Pages/Portal/Profile.tsx'));
        $dashboard = file_get_contents(resource_path('js/Pages/Portal/Base.tsx'));
        $communications = file_get_contents(resource_path('js/Pages/Membros/CommunicationsTab.tsx'));

        $this->assertIsString($family);
        $this->assertIsString($agenda);
        $this->assertIsString($profile);
        $this->assertIsString($dashboard);
        $this->assertIsString($communications);

        $heroGradient = 'bg-[linear-gradient(145deg,#0f62c8_0%,#0c4d9d_100%)]';
        $this->assertStringContainsString($heroGradient, $family);
        $this->assertStringContainsString($heroGradient, $agenda);

        $this->assertStringContainsString('function safeAgeGroup', $family);
        $this->assertStringContainsString('uuidPattern', $family);
        $this->assertStringContainsString('member.numero_socio', $family);

        $this->assertStringContainsString('Dados pessoais', $profile);
        $this->assertStringContainsString('Número de sócio', $profile);
        $this->assertStringContainsString('disabled readOnly', $profile);
        $this->assertStringContainsString('Estado civil', $profile);
        $this->assertStringContainsString('Guardar', $profile);
        $this->assertStringNotContainsString('allowed_profiles.map', $profile);

        $this->assertStringContainsString('getPortalBottomNavItems(has_family)', $dashboard);
        $this->assertStringContainsString("key: 'payments', label: 'Pagamentos'", $dashboard);
        $this->assertStringContainsString("key: 'documents', label: 'Documentos'", $dashboard);
        $this->assertStringContainsString("key: 'communications', label: 'Comunicações'", $dashboard);
        $this->assertStringContainsString('.filter((action) => !bottomNavKeys.has(action.key))', $dashboard);

        $this->assertStringContainsString('className="divide-y divide-slate-100 lg:hidden"', $communications);
        $this->assertStringContainsString('className="hidden max-h-[420px] overflow-auto lg:block"', $communications);
        $this->assertStringContainsString('w-[calc(100vw-1.5rem)]', $communications);
        $this->assertStringContainsString('w-[calc(100vw-2rem)] max-w-[340px]', $communications);
    }

    public function test_profile_marital_status_is_canonical_and_member_number_cannot_be_changed_from_portal(): void
    {
        $member = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
            'numero_socio' => '2026-0059',
        ]);

        $response = $this->actingAs($member)->patch(route('portal.profile.update'), [
            'nome_completo' => 'Membro Portal',
            'estado_civil' => 'casado',
            'numero_socio' => 'ALTERADO-INDEVIDAMENTE',
        ]);

        $response->assertRedirect(route('portal.profile'));
        $this->assertDatabaseHas('dados_pessoais', [
            'user_id' => $member->id,
            'nome_completo' => 'Membro Portal',
            'estado_civil' => 'casado',
        ]);
        $this->assertSame('2026-0059', $member->fresh()->numero_socio);

        $showResponse = $this->inertiaGetAs($member, route('portal.profile'));
        $showResponse->assertOk();
        $showResponse->assertJsonPath('component', 'Portal/Profile');
        $showResponse->assertJsonPath('props.profile.member_number', '2026-0059');
        $showResponse->assertJsonPath('props.profile.editable.estado_civil', 'casado');

        $personal = collect($showResponse->json('props.profile.personal'));
        $this->assertSame('Casado(a)', data_get($personal->firstWhere('label', 'Estado civil'), 'value'));
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

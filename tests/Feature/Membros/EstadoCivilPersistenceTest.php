<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EstadoCivilPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_civil_status_is_stored_and_hydrated_from_canonical_personal_data(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('membros.store'), $this->storePayload([
                'numero_socio' => 'EC-100',
                'estado_civil' => 'casado',
            ]))
            ->assertRedirect(route('membros.index'));

        $member = User::query()->where('numero_socio', 'EC-100')->firstOrFail();
        $this->assertSame(
            'casado',
            DadosPessoais::query()->where('user_id', $member->id)->value('estado_civil')
        );

        $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]))
            ->assertOk()
            ->assertJsonPath('props.member.estado_civil', 'casado');

        $this->actingAs($admin)
            ->from(route('membros.show', $member))
            ->put(route('membros.update', $member), $this->updatePayload($member, [
                'estado_civil' => 'uniao_de_facto',
            ]))
            ->assertRedirect(route('membros.show', ['member' => $member->id]));

        $this->assertSame(
            'uniao_de_facto',
            DadosPessoais::query()->where('user_id', $member->id)->value('estado_civil')
        );

        $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]))
            ->assertOk()
            ->assertJsonPath('props.member.estado_civil', 'uniao_de_facto');

        if (Schema::hasColumn('users', 'estado_civil')) {
            $this->assertNotSame('uniao_de_facto', $member->fresh()->getAttribute('estado_civil'));
        }
    }

    public function test_member_update_rejects_invalid_civil_status(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create([
            'nome_completo' => 'Estado Civil Invalid',
            'name' => 'Estado Civil Invalid',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'numero_socio' => 'EC-200',
        ]);

        $this->actingAs($admin)
            ->from(route('membros.show', $member))
            ->put(route('membros.update', $member), $this->updatePayload($member, [
                'estado_civil' => 'valor_invalido',
            ]))
            ->assertRedirect(route('membros.show', $member))
            ->assertSessionHasErrors('estado_civil');
    }

    public function test_frontend_civil_status_options_match_backend_values(): void
    {
        $source = file_get_contents(resource_path('js/Components/Members/Tabs/PersonalTab.tsx'));

        foreach (['solteiro', 'casado', 'uniao_de_facto', 'divorciado', 'viuvo'] as $value) {
            $this->assertStringContainsString(sprintf('value="%s"', $value), $source);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'nome_completo' => 'Estado Civil Store',
            'email_utilizador' => 'estado.civil.store@example.test',
            'numero_socio' => 'EC-001',
            'sexo' => 'feminino',
            'estado' => 'ativo',
            'tipo_membro' => ['socio'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function updatePayload(User $member, array $overrides = []): array
    {
        return array_merge([
            'nome_completo' => $member->nome_completo ?: $member->name ?: 'Estado Civil Update',
            'email_utilizador' => $member->email_utilizador,
            'numero_socio' => (string) $member->numero_socio,
            'sexo' => $member->sexo ?: 'masculino',
            'estado' => $member->estado ?: 'ativo',
            'tipo_membro' => $member->tipo_membro ?? [],
        ], $overrides);
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

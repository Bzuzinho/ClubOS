<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DadosPessoais;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MembrosGlobalSearchAndNifUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_search_queries_all_pages_and_canonical_personal_data(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Admin Pesquisa']);

        foreach (range(1, 12) as $index) {
            User::factory()->create(['name' => sprintf('A Membro %02d', $index)]);
        }

        $target = User::factory()->create([
            'name' => 'Z Nome Auth Antigo',
            'nome_completo' => 'Z Nome Legacy Antigo',
            'nif' => '111111111',
        ]);
        DadosPessoais::query()->create([
            'user_id' => $target->id,
            'nome_completo' => 'Pessoa Canónica Encontrável',
            'nif' => '245678901',
        ]);

        $firstPage = $this->inertiaGetAs($admin, route('membros.index', [
            'tab' => 'list',
            'per_page' => 10,
        ]));
        $this->assertFalse(collect($firstPage->json('props.members'))->pluck('id')->contains($target->id));

        $response = $this->inertiaGetAs($admin, route('membros.index', [
            'tab' => 'list',
            'per_page' => 10,
            'search' => 'Canónica Encontrável',
        ]));

        $response->assertOk();
        $response->assertJsonPath('props.membersPagination.total', 1);
        $response->assertJsonPath('props.members.0.id', $target->id);
        $response->assertJsonPath('props.members.0.nome_completo', 'Pessoa Canónica Encontrável');
    }

    public function test_member_creation_rejects_nif_already_present_in_canonical_data(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = User::factory()->create();
        DadosPessoais::query()->create([
            'user_id' => $existing->id,
            'nome_completo' => 'Membro Existente',
            'nif' => '245 678 902',
        ]);

        $response = $this->actingAs($admin)->post(route('membros.store'), $this->memberPayload([
            'email_utilizador' => 'duplicate.nif@example.test',
            'numero_socio' => 'NIF-NEW-001',
            'nif' => '245678902',
        ]));

        $response->assertSessionHasErrors('nif');
        $this->assertDatabaseMissing('users', ['email_utilizador' => 'duplicate.nif@example.test']);
    }

    public function test_member_creation_rejects_nif_present_only_in_legacy_users(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['nif' => '245678903']);

        $response = $this->actingAs($admin)->post(route('membros.store'), $this->memberPayload([
            'email_utilizador' => 'duplicate.legacy.nif@example.test',
            'numero_socio' => 'NIF-NEW-002',
            'nif' => '245678903',
        ]));

        $response->assertSessionHasErrors('nif');
        $this->assertDatabaseMissing('users', ['email_utilizador' => 'duplicate.legacy.nif@example.test']);
    }

    public function test_member_update_rejects_nif_used_by_another_member(): void
    {
        $admin = User::factory()->admin()->create();
        $existing = User::factory()->create();
        DadosPessoais::query()->create([
            'user_id' => $existing->id,
            'nome_completo' => 'Titular do NIF',
            'nif' => '245678905',
        ]);
        $member = User::factory()->create([
            'nome_completo' => 'Membro a editar',
            'numero_socio' => 'NIF-EDIT-001',
        ]);

        $response = $this->actingAs($admin)->put(route('membros.update', $member), $this->memberPayload([
            'nome_completo' => 'Membro a editar',
            'email_utilizador' => $member->email_utilizador,
            'numero_socio' => 'NIF-EDIT-001',
            'nif' => '245 678 905',
        ]));

        $response->assertSessionHasErrors('nif');
        $this->assertNotSame('245 678 905', DadosPessoais::query()->where('user_id', $member->id)->value('nif'));
    }

    /** @return array<string, mixed> */
    private function memberPayload(array $overrides = []): array
    {
        return array_merge([
            'nome_completo' => 'Novo Membro NIF',
            'email_utilizador' => 'new.member.nif@example.test',
            'numero_socio' => 'NIF-NEW-000',
            'nif' => '245678900',
            'sexo' => 'masculino',
            'estado' => 'ativo',
            'ativo_desportivo' => false,
            'menor' => false,
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

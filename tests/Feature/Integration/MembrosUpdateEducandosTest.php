<?php

namespace Tests\Feature\Integration;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembrosUpdateEducandosTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_persists_canonical_educandos_relationships_without_writing_json_mirrors(): void
    {
        $this->withoutMiddleware();

        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
            'nome_completo' => 'Guardian Test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Educando Test',
            'sexo' => 'masculino',
            'estado' => 'ativo',
        ]);

        $response = $this->actingAs($guardian)->put(route('membros.update', $guardian), [
            'nome_completo' => $guardian->nome_completo,
            'email_utilizador' => $guardian->email_utilizador,
            'numero_socio' => (string) $guardian->numero_socio,
            'sexo' => $guardian->sexo,
            'estado' => $guardian->estado,
            'tipo_membro' => $guardian->tipo_membro,
            'sync_educandos' => true,
            'educandos' => [$educando->id],
            'sync_encarregado_educacao' => true,
            'encarregado_educacao' => [],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertNull(session('error'), (string) session('error'));
        $response->assertRedirect(route('membros.show', $guardian));

        $this->assertDatabaseHas('user_guardian', [
            'guardian_id' => $guardian->id,
            'user_id' => $educando->id,
        ]);

        $guardian->refresh();
        $educando->refresh();

        $this->assertSame([$educando->id], $guardian->educandos()->pluck('users.id')->all());
        $this->assertSame([], $guardian->educandos ?? []);
        $this->assertSame([$guardian->id], $educando->encarregados()->pluck('users.id')->all());
        $this->assertSame([], $educando->encarregado_educacao ?? []);

        $showResponse = $this->inertiaGetAs($guardian, route('membros.show', ['member' => $guardian->id]));

        $showResponse->assertOk();
        $showResponse->assertJsonPath('component', 'Membros/Show');
        $showResponse->assertJsonCount(1, 'props.member.educandos');
        $showResponse->assertJsonPath('props.member.educandos.0.id', $educando->id);
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
<?php

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Familia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class MembrosFamilyContextTabPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_show_includes_family_context_with_guardians_dependents_and_aggregated_family(): void
    {
        $admin = User::factory()->admin()->create([
            'perfil' => 'admin',
        ]);

        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
            'nome_completo' => 'Encarregado Contexto',
            'email_utilizador' => 'encarregado@example.test',
            'contacto_telefonico' => '910000001',
        ]);

        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Atleta Contexto',
        ]);

        $sibling = User::factory()->create([
            'tipo_membro' => ['socio'],
            'nome_completo' => 'Irmao Contexto',
        ]);

        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $athlete->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guardian->forceFill(['educandos' => [$athlete->id]])->save();
        $athlete->forceFill(['encarregado_educacao' => [$guardian->id]])->save();

        $family = Familia::query()->create([
            'nome' => 'Familia Contexto',
            'descricao' => 'Teste',
            'responsavel_user_id' => $guardian->id,
            'ativo' => true,
        ]);

        DB::table('familia_user')->insert([
            [
                'id' => (string) Str::uuid(),
                'familia_id' => $family->id,
                'user_id' => $guardian->id,
                'papel_na_familia' => 'responsavel',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'familia_id' => $family->id,
                'user_id' => $athlete->id,
                'papel_na_familia' => 'educando',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'familia_id' => $family->id,
                'user_id' => $sibling->id,
                'papel_na_familia' => 'membro',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $athlete->id]));

        $response->assertOk();
        $response->assertJsonPath('component', 'Membros/Show');
        $response->assertJsonPath('props.family_context.is_dependent_profile', true);
        $response->assertJsonPath('props.family_context.is_guardian_profile', false);
        $response->assertJsonPath('props.family_context.guardians.0.id', $guardian->id);
        $response->assertJsonPath('props.family_context.guardians.0.email', 'encarregado@example.test');
        $response->assertJsonPath('props.family_context.guardians.0.contacto', '910000001');
        $response->assertJsonPath('props.family_context.families.0.nome', 'Familia Contexto');
        $response->assertJsonPath('props.family_context.families.0.ativo', true);
        $response->assertJsonPath('props.family_context.summary.guardians_count', 1);
        $response->assertJsonPath('props.family_context.summary.families_count', 1);

        $familyMembers = collect($response->json('props.family_context.families.0.members'));
        $this->assertTrue($familyMembers->contains(fn (array $entry) => ($entry['id'] ?? null) === $guardian->id));
    }

    public function test_member_show_marks_guardian_profile_with_dependents_in_family_context(): void
    {
        $admin = User::factory()->admin()->create([
            'perfil' => 'admin',
        ]);

        $guardian = User::factory()->create([
            'perfil' => 'encarregado',
            'tipo_membro' => ['encarregado_educacao'],
        ]);

        $educando = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $educando->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $guardian->forceFill(['educandos' => [$educando->id]])->save();
        $educando->forceFill(['encarregado_educacao' => [$guardian->id]])->save();

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $guardian->id]));

        $response->assertOk();
        $response->assertJsonPath('props.family_context.is_guardian_profile', true);
        $response->assertJsonPath('props.family_context.dependents.0.id', $educando->id);
        $response->assertJsonPath('props.family_context.summary.dependents_count', 1);
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

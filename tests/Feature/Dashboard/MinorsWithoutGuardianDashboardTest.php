<?php

namespace Tests\Feature\Dashboard;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MinorsWithoutGuardianDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_lists_only_minors_without_guardians_without_changing_data(): void
    {
        $admin = User::factory()->admin()->create(['menor' => false, 'data_nascimento' => '1980-01-01']);
        $minorWithoutGuardian = $this->member('Menor Pendente', now()->subYears(12)->toDateString(), false);
        $minorByFlagWithInvalidDate = $this->member('Menor Data Inválida', 'invalid-date', true);
        $minorWithGuardian = $this->member('Menor Resolvido', now()->subYears(10)->toDateString(), false);
        $adultWithoutGuardian = $this->member('Adulto Sem EE', now()->subYears(30)->toDateString(), false);
        $guardian = $this->member('Encarregado Existente', now()->subYears(40)->toDateString(), false);

        DB::table('user_guardian')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $minorWithGuardian->id,
            'guardian_id' => $guardian->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $before = [
            'users' => DB::table('users')->count(),
            'guardians' => DB::table('user_guardian')->count(),
            'families' => DB::table('familia_user')->count(),
        ];

        $response = $this->inertiaGetAs($admin, route('dashboard'));

        $response->assertOk()
            ->assertJsonPath('component', 'Dashboard')
            ->assertJsonPath('props.minorsWithoutGuardian.total', 2)
            ->assertJsonPath('props.minorsWithoutGuardian.has_more', false)
            ->assertJsonPath('props.minorsWithoutGuardian.items.0.name', 'Menor Data Inválida')
            ->assertJsonPath('props.minorsWithoutGuardian.items.0.age', null)
            ->assertJsonPath('props.minorsWithoutGuardian.items.0.member_url', route('membros.show', $minorByFlagWithInvalidDate))
            ->assertJsonPath('props.minorsWithoutGuardian.items.1.name', 'Menor Pendente')
            ->assertJsonPath('props.minorsWithoutGuardian.items.1.member_url', route('membros.show', $minorWithoutGuardian));

        $names = collect($response->json('props.minorsWithoutGuardian.items'))->pluck('name');
        $this->assertNotContains($minorWithGuardian->name, $names);
        $this->assertNotContains($adultWithoutGuardian->name, $names);
        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['guardians'], DB::table('user_guardian')->count());
        $this->assertSame($before['families'], DB::table('familia_user')->count());
    }

    public function test_dashboard_summary_exposes_more_records_and_all_members_link(): void
    {
        $admin = User::factory()->admin()->create(['menor' => false, 'data_nascimento' => '1980-01-01']);

        foreach (range(1, 6) as $index) {
            $this->member("Menor {$index}", now()->subYears(10)->toDateString(), false);
        }

        $response = $this->inertiaGetAs($admin, route('dashboard'));

        $response->assertOk()
            ->assertJsonPath('props.minorsWithoutGuardian.total', 6)
            ->assertJsonCount(5, 'props.minorsWithoutGuardian.items')
            ->assertJsonPath('props.minorsWithoutGuardian.has_more', true)
            ->assertJsonPath('props.minorsWithoutGuardian.all_url', route('membros.index'));
    }

    private function member(string $name, string $birthdate, bool $minor): User
    {
        $user = User::factory()->create([
            'name' => $name,
            'nome_completo' => $name,
            'perfil' => 'atleta',
            'tipo_membro' => ['Atleta'],
            'estado' => 'ativo',
            'menor' => $minor,
            'data_nascimento' => $birthdate === 'invalid-date' ? null : $birthdate,
        ]);

        DB::table('dados_pessoais')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'nome_completo' => $name,
            'data_nascimento' => $birthdate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
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

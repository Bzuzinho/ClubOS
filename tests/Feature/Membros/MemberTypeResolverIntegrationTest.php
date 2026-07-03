<?php

declare(strict_types=1);

namespace Tests\Feature\Membros;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Training;
use App\Models\User;
use App\Models\UserType;
use App\Services\Desportivo\PrepareTrainingAthletesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class MemberTypeResolverIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_with_canonical_user_type_atleta_is_counted_as_athlete_in_members_stats(): void
    {
        $admin = User::factory()->admin()->create();
        $athleteType = $this->createUserType('atleta', 'Atleta');

        $athlete = User::factory()->create([
            'tipo_membro' => [],
            'estado' => 'ativo',
            'ativo_desportivo' => true,
        ]);
        $athlete->userTypes()->sync([$athleteType->id]);

        Cache::forget('membros:list');
        Cache::forget('membros:stats');

        $response = $this->inertiaGetAs($admin, route('membros.index'));

        $response->assertOk();
        $response->assertJsonPath('props.stats.totalAtletas', 1);
    }

    public function test_member_without_user_types_uses_legacy_tipo_membro_fallback_for_athlete_stats(): void
    {
        $admin = User::factory()->admin()->create();

        User::factory()->create([
            'tipo_membro' => ['atleta'],
            'estado' => 'ativo',
            'ativo_desportivo' => true,
        ]);

        Cache::forget('membros:list');
        Cache::forget('membros:stats');

        $response = $this->inertiaGetAs($admin, route('membros.index'));

        $response->assertOk();
        $response->assertJsonPath('props.stats.totalAtletas', 1);
    }

    public function test_member_show_payload_exposes_member_types_for_canonical_athlete(): void
    {
        $admin = User::factory()->admin()->create();
        $athleteType = $this->createUserType('atleta', 'Atleta');

        $member = User::factory()->create([
            'tipo_membro' => [],
            'estado' => 'ativo',
        ]);
        $member->userTypes()->sync([$athleteType->id]);

        $response = $this->inertiaGetAs($admin, route('membros.show', ['member' => $member->id]));

        $response->assertOk();
        $response->assertJsonPath('component', 'Membros/Show');

        $memberTypes = collect($response->json('props.member.memberTypes') ?? []);
        $this->assertTrue($memberTypes->contains('atleta'));
    }

    public function test_prepare_training_athletes_action_finds_athlete_using_canonical_user_types(): void
    {
        $coach = User::factory()->create();
        $athleteType = $this->createUserType('atleta', 'Atleta');

        $athlete = User::factory()->create([
            'tipo_membro' => [],
            'estado' => 'ativo',
            'ativo_desportivo' => true,
        ]);
        $athlete->userTypes()->sync([$athleteType->id]);

        $training = Training::create([
            'numero_treino' => 'AC1-001',
            'data' => '2026-07-03',
            'tipo_treino' => 'cais',
            'descricao_treino' => 'Treino AC1',
            'criado_por' => $coach->id,
        ]);

        app(PrepareTrainingAthletesAction::class)->execute($training, []);

        $this->assertDatabaseHas('training_athletes', [
            'treino_id' => $training->id,
            'user_id' => $athlete->id,
        ]);
    }

    public function test_member_type_audit_detects_divergence_and_fail_flag_returns_non_zero(): void
    {
        $athleteType = $this->createUserType('atleta', 'Atleta');

        $user = User::factory()->create([
            'tipo_membro' => ['treinador'],
            'estado' => 'ativo',
        ]);
        $user->userTypes()->sync([$athleteType->id]);

        $exitCode = Artisan::call('members:audit-member-types', [
            '--json' => true,
            '--fail-on-divergence' => true,
        ]);

        $this->assertSame(1, $exitCode);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        $this->assertSame(1, (int) ($payload['summary']['divergent_count'] ?? 0));
        $this->assertSame(1, (int) ($payload['summary']['canonical_athlete_not_legacy_count'] ?? 0));
    }

    private function createUserType(string $codigo, string $nome): UserType
    {
        return UserType::query()->create([
            'codigo' => $codigo,
            'nome' => $nome,
            'descricao' => $nome,
            'ativo' => true,
        ]);
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
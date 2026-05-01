<?php

namespace Tests\Feature\Portal;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PortalTrainingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_athlete_can_view_own_trainings_in_portal(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
            'nome_completo' => 'Atleta Portal',
        ]);

        $futureTrainingId = $this->createTraining([
            'data' => now()->addDays(2)->toDateString(),
            'hora_inicio' => '19:00:00',
            'local' => 'Piscina Municipal da Benedita',
            'tipo_treino' => 'Técnica + resistência',
            'descricao_treino' => 'Técnica + resistência',
            'volume_planeado_m' => 4200,
            'escaloes' => json_encode(['Infantis A']),
            'notas_gerais' => 'Séries de técnica e bloco aeróbio.',
        ]);

        $pastTrainingId = $this->createTraining([
            'data' => now()->subDays(1)->toDateString(),
            'hora_inicio' => '18:30:00',
            'local' => 'Piscina Municipal da Benedita',
            'tipo_treino' => 'Recuperação ativa',
            'descricao_treino' => 'Recuperação ativa',
            'volume_planeado_m' => 3000,
            'escaloes' => json_encode(['Infantis A']),
            'notas_gerais' => 'Saída solta e técnica respiratória.',
        ]);

        $this->attachAthleteToTraining($futureTrainingId, $athlete->id, [
            'estado' => null,
            'presente' => false,
        ]);

        $this->attachAthleteToTraining($pastTrainingId, $athlete->id, [
            'estado' => 'presente',
            'presente' => true,
            'volume_real_m' => 2800,
            'observacoes_tecnicas' => 'Boa consistência no bloco final.',
        ]);

        $response = $this->inertiaGetAs($athlete, route('portal.trainings'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Trainings');
        $response->assertJsonPath('props.is_athlete', true);
        $response->assertJsonPath('props.next_training.location', 'Piscina Municipal da Benedita');
        $response->assertJsonPath('props.next_training.status.label', 'Por confirmar');
        $response->assertJsonPath('props.upcoming_trainings.0.permissions.can_confirm_presence', true);
        $response->assertJsonPath('props.latest_training.final_meters', 2800);
        $response->assertJsonPath('props.latest_training.status.label', 'Incompleto');
        $response->assertJsonPath('props.latest_coach_note.note', 'Boa consistência no bloco final.');
    }

    public function test_athlete_without_trainings_gets_empty_portal_state(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $response = $this->inertiaGetAs($athlete, route('portal.trainings'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Trainings');
        $response->assertJsonPath('props.next_training', null);
        $response->assertJsonPath('props.latest_training', null);
        $response->assertJsonCount(0, 'props.upcoming_trainings');
        $response->assertJsonCount(0, 'props.history');
    }

    public function test_non_athlete_can_open_page_but_sees_no_training_records(): void
    {
        $user = User::factory()->create([
            'perfil' => 'user',
            'tipo_membro' => ['socio'],
        ]);

        $response = $this->inertiaGetAs($user, route('portal.trainings'));

        $response->assertOk();
        $response->assertJsonPath('component', 'Portal/Trainings');
        $response->assertJsonPath('props.is_athlete', false);
        $response->assertJsonPath('props.next_training', null);
        $response->assertJsonPath('props.latest_coach_note', null);
    }

    public function test_athlete_can_confirm_own_presence_but_cannot_update_other_athlete_training(): void
    {
        $athlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);
        $otherAthlete = User::factory()->athlete()->create([
            'tipo_membro' => ['atleta'],
        ]);

        $trainingId = $this->createTraining([
            'data' => now()->addDay()->toDateString(),
            'hora_inicio' => '19:00:00',
            'tipo_treino' => 'Técnica',
        ]);

        $ownRecordId = $this->attachAthleteToTraining($trainingId, $athlete->id, [
            'estado' => null,
            'presente' => false,
        ]);

        $otherRecordId = $this->attachAthleteToTraining($trainingId, $otherAthlete->id, [
            'estado' => null,
            'presente' => false,
        ]);

        $this->actingAs($athlete)
            ->patch(route('portal.trainings.update', $ownRecordId), [
                'action' => 'confirm_presence',
            ])
            ->assertRedirect(route('portal.trainings'));

        $this->assertDatabaseHas('training_athletes', [
            'id' => $ownRecordId,
            'user_id' => $athlete->id,
            'estado' => 'presente',
            'presente' => true,
        ]);

        $this->actingAs($athlete)
            ->patch(route('portal.trainings.update', $otherRecordId), [
                'action' => 'confirm_presence',
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('training_athletes', [
            'id' => $otherRecordId,
            'user_id' => $otherAthlete->id,
            'presente' => false,
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTraining(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('trainings')->insert(array_merge([
            'id' => $id,
            'numero_treino' => 'TR-' . Str::upper(Str::random(6)),
            'data' => now()->toDateString(),
            'hora_inicio' => '18:00:00',
            'hora_fim' => '19:30:00',
            'local' => 'Piscina Municipal',
            'tipo_treino' => 'Técnica',
            'volume_planeado_m' => 2500,
            'descricao_treino' => 'Treino agendado',
            'escaloes' => json_encode(['Grupo A']),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function attachAthleteToTraining(string $trainingId, string $userId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        DB::table('training_athletes')->insert(array_merge([
            'id' => $id,
            'treino_id' => $trainingId,
            'user_id' => $userId,
            'presente' => false,
            'estado' => null,
            'volume_real_m' => null,
            'rpe' => null,
            'observacoes_tecnicas' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }
}
<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\Training;
use App\Models\User;
use App\Services\Desportivo\PrepareTrainingAthletesAction;
use App\Services\Desportivo\SportsCaisWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

final class TrainingToCaisAttendanceContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_prepared_training_athlete_is_the_same_record_consumed_and_updated_by_cais(): void
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->create([
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);
        $training = Training::query()->create([
            'numero_treino' => '#H3C01',
            'data' => now()->toDateString(),
            'hora_inicio' => '18:00',
            'hora_fim' => '19:30',
            'tipo_treino' => 'Técnico',
            'club_id' => 'bscn',
            'session_status' => 'published',
            'criado_por' => $actor->id,
        ]);

        app(PrepareTrainingAthletesAction::class)->executeForUsers($training, [(string) $athlete->id]);

        $before = $training->athleteRecords()->where('user_id', $athlete->id)->firstOrFail();
        $this->assertTrue((bool) $before->presente);
        $this->assertSame('presente', $before->estado);

        $request = Request::create('/desportivo/cais', 'GET', [
            'date' => $training->data->toDateString(),
            'training_id' => (string) $training->id,
        ]);
        $payload = app(SportsCaisWorkspaceService::class)->payload($request);
        $caisAthlete = collect(data_get($payload, 'selectedSession.athletes'))->firstWhere('id', (string) $athlete->id);

        $this->assertNotNull($caisAthlete);
        $this->assertSame((string) $before->id, data_get($caisAthlete, 'training_athlete_id'));
        $this->assertSame('presente', data_get($caisAthlete, 'status'));

        $updated = app(SportsCaisWorkspaceService::class)->updatePresence($training, $athlete, 'atrasado', $actor);

        $this->assertSame((string) $before->id, (string) $updated->id);
        $this->assertSame('atrasado', $updated->estado);
        $this->assertTrue((bool) $updated->presente);
        $this->assertSame(1, $training->athleteRecords()->where('user_id', $athlete->id)->count());
    }
}

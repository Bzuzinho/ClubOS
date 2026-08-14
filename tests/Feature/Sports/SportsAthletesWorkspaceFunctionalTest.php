<?php

namespace Tests\Feature\Sports;

use App\Models\SportsAthleteParticipation;
use App\Models\SportsModality;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use App\Services\Desportivo\SportsAthletesWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SportsAthletesWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_workspace_uses_canonical_participation_and_real_training_assignments(): void
    {
        $canonicalAthlete = User::factory()->create([
            'name' => 'Atleta Canónico',
            'estado' => 'ativo',
            'tipo_membro' => ['socio'],
        ]);
        $legacyOnlyAthlete = User::factory()->create([
            'name' => 'Atleta Apenas Legacy',
            'estado' => 'ativo',
            'tipo_membro' => ['atleta'],
        ]);
        $modality = SportsModality::query()
            ->where('club_id', 'bscn')
            ->where('code', 'swimming')
            ->firstOrFail();

        SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn',
            'user_id' => $canonicalAthlete->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => now()->subYear()->toDateString(),
            'source' => 'test',
        ]);

        $group = TrainingGroup::query()->create([
            'club_id' => 'bscn',
            'code' => 'COMP-A',
            'name' => 'Competição A',
            'modality' => 'natacao',
            'sports_modality_id' => $modality->id,
            'active' => true,
        ]);
        TrainingGroupMembership::query()->create([
            'club_id' => 'bscn',
            'training_group_id' => $group->id,
            'user_id' => $canonicalAthlete->id,
            'is_primary' => true,
            'starts_at' => now()->subMonth()->toDateString(),
        ]);

        $training = Training::query()->create([
            'club_id' => 'bscn',
            'numero_treino' => 901,
            'data' => now()->subDays(2)->toDateString(),
            'session_status' => 'completed',
            'tipo_treino' => 'Técnico',
            'volume_planeado_m' => 1800,
        ]);
        TrainingAthlete::query()->create([
            'treino_id' => $training->id,
            'user_id' => $canonicalAthlete->id,
            'presente' => true,
            'estado' => 'presente',
            'volume_real_m' => 1600,
            'rpe' => 6,
        ]);

        $payload = app(SportsAthletesWorkspaceService::class)->workspace();
        $rows = collect($payload['athletes'])->keyBy('id');

        $this->assertTrue($rows->has((string) $canonicalAthlete->id));
        $this->assertFalse($rows->has((string) $legacyOnlyAthlete->id));

        $row = $rows->get((string) $canonicalAthlete->id);
        $this->assertSame('active', $row['state']);
        $this->assertSame(100.0, $row['attendance_30d']);
        $this->assertSame(1600, $row['volume_30d_m']);
        $this->assertSame(6.0, $row['avg_rpe_30d']);
        $this->assertSame('Competição A', $row['groups'][0]['name']);
        $this->assertSame((string) $modality->id, $row['modalities'][0]['id']);
        $this->assertTrue($payload['principles']['canonical_participation']);
        $this->assertTrue($payload['principles']['attendance_is_real_training_assignment']);
        $this->assertFalse($payload['principles']['legacy_medical_json_active']);
    }

    public function test_detail_combines_canonical_profile_and_read_only_analysis(): void
    {
        $athlete = User::factory()->create(['name' => 'Detalhe Atleta']);
        $modality = SportsModality::query()
            ->where('club_id', 'bscn')
            ->where('code', 'swimming')
            ->firstOrFail();

        SportsAthleteParticipation::query()->create([
            'club_id' => 'bscn',
            'user_id' => $athlete->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => now()->subMonth()->toDateString(),
            'source' => 'test',
        ]);

        $detail = app(SportsAthletesWorkspaceService::class)->athlete($athlete);

        $this->assertSame((string) $athlete->id, $detail['athlete']['id']);
        $this->assertSame('active', $detail['athlete']['state']);
        $this->assertTrue($detail['sports_profile']['canonical']);
        $this->assertNotNull($detail['analysis']);
        $this->assertArrayHasKey('medical_document', $detail);
    }

    public function test_dedicated_routes_are_registered(): void
    {
        $this->assertTrue(Route::has('desportivo.atletas.index'));
        $this->assertTrue(Route::has('desportivo.atletas.show'));
    }
}

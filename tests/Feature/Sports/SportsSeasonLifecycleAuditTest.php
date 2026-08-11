<?php

namespace Tests\Feature\Sports;

use App\Models\Season;
use App\Models\SportsModality;
use App\Services\Desportivo\SportsStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SportsSeasonLifecycleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_season_close_and_reopen_append_immutable_lifecycle_events(): void
    {
        $this->assertTrue(Schema::hasTable('sports_season_lifecycle_events'));

        $service = app(SportsStructureService::class);
        $modality = SportsModality::query()->where('code', 'swimming')->firstOrFail();
        $actorId = (string) Str::uuid();
        $season = Season::create([
            'nome' => 'Época 2026/27',
            'ano_temporada' => '2026/27',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'tipo' => 'Principal',
            'estado' => 'Em curso',
            'club_id' => 'bscn',
            'sports_modality_id' => $modality->id,
            'status' => 'active',
        ]);

        $service->closeSeason($season, $actorId);
        $service->reopenSeason($season->fresh(), 'Correção técnica da época', $actorId);

        $this->assertDatabaseCount('sports_season_lifecycle_events', 2);
        $this->assertDatabaseHas('sports_season_lifecycle_events', [
            'club_id' => 'bscn',
            'season_id' => $season->id,
            'from_status' => 'active',
            'to_status' => 'closed',
            'actor_id' => $actorId,
        ]);
        $this->assertDatabaseHas('sports_season_lifecycle_events', [
            'club_id' => 'bscn',
            'season_id' => $season->id,
            'from_status' => 'closed',
            'to_status' => 'active',
            'reason' => 'Correção técnica da época',
            'actor_id' => $actorId,
        ]);
    }
}

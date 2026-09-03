<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\AgeGroup;
use App\Models\AthleteSportsData;
use App\Models\Season;
use App\Models\SportsAthleteParticipation;
use App\Models\SportsAthleteSeasonProfile;
use App\Models\SportsModality;
use App\Models\User;
use App\Services\Desportivo\SportsClubContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AthleteSportsDataCanonicalAgeGroupTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_projection_reads_official_age_group_from_current_canonical_season_profile(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 3));

        $clubId = app(SportsClubContext::class)->id();
        $member = User::factory()->create([
            'tipo_membro' => ['atleta'],
            'ativo_desportivo' => true,
        ]);

        $modality = SportsModality::query()
            ->where('club_id', $clubId)
            ->where('code', 'swimming')
            ->first();

        $modality ??= SportsModality::query()->create([
            'club_id' => $clubId,
            'code' => 'swimming',
            'name' => 'Natação',
            'active' => true,
        ]);

        $ageGroup = AgeGroup::query()->create([
            'club_id' => $clubId,
            'code' => 'junior-canonical-test',
            'nome' => 'Juniores',
            'ativo' => true,
        ]);

        $season = Season::query()->create([
            'nome' => 'Época 2026/2027',
            'ano_temporada' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'tipo' => 'Principal',
            'estado' => 'Em curso',
            'club_id' => $clubId,
            'sports_modality_id' => $modality->id,
            'status' => 'active',
        ]);

        $participation = SportsAthleteParticipation::query()->create([
            'club_id' => $clubId,
            'user_id' => $member->id,
            'sports_modality_id' => $modality->id,
            'active' => true,
            'current_slot' => 'current',
            'starts_at' => '2026-09-01',
        ]);

        SportsAthleteSeasonProfile::query()->create([
            'club_id' => $clubId,
            'user_id' => $member->id,
            'sports_athlete_participation_id' => $participation->id,
            'season_id' => $season->id,
            'sports_modality_id' => $modality->id,
            'official_age_group_id' => $ageGroup->id,
            'calculated_age_group_id' => $ageGroup->id,
            'placement_source' => 'rule',
            'evaluated_at' => now(),
        ]);

        $legacyProjection = AthleteSportsData::query()->create([
            'user_id' => $member->id,
            'escalao_id' => null,
            'ativo' => true,
        ]);

        $this->assertSame((string) $ageGroup->id, (string) $legacyProjection->fresh()->escalao_id);
    }
}

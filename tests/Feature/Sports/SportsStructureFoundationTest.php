<?php

namespace Tests\Feature\Sports;

use App\Models\AgeGroup;
use App\Models\AthleteAgeGroupOverride;
use App\Models\Season;
use App\Models\SeasonAgeGroupRule;
use App\Models\SportsModality;
use App\Models\SportsProgram;
use App\Services\Desportivo\AgeGroupPlacementService;
use App\Services\Desportivo\SportsStructureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SportsStructureFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_f2_schema_is_additive_and_keeps_legacy_lane_tables(): void
    {
        foreach (['sports_modalities','sports_programs','season_programs','season_age_group_rules','training_group_seasons','sports_coach_roles','sports_pools','sports_pool_lanes','athlete_age_group_overrides'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' should exist');
        }
        $this->assertTrue(Schema::hasTable('sports_venue_lanes'));
        $this->assertTrue(Schema::hasColumn('seasons','sports_modality_id'));
        $this->assertTrue(Schema::hasColumn('training_groups','sports_modality_id'));
    }

    public function test_backfill_creates_swimming_modality_but_never_invents_masters_program(): void
    {
        $this->assertDatabaseHas('sports_modalities',['club_id'=>'bscn','code'=>'swimming','name'=>'Natação']);
        $this->assertDatabaseMissing('sports_programs',['code'=>'masters']);
        $this->assertDatabaseMissing('sports_programs',['name'=>'Masters']);
    }

    public function test_program_is_permanent_and_can_be_reused_across_seasons(): void
    {
        $service = app(SportsStructureService::class);
        $modality = SportsModality::query()->where('code','swimming')->firstOrFail();
        $program = $service->createProgram(['sports_modality_id'=>$modality->id,'code'=>'competicao','name'=>'Competição']);
        $this->assertSame('Competição',$program->name);
        $this->assertTrue($program->active);
    }

    public function test_age_group_resolution_uses_season_rule_and_manual_override_wins(): void
    {
        $clubId = 'bscn';
        $modality = SportsModality::query()->where('code','swimming')->firstOrFail();
        $season = Season::create(['nome'=>'2026/27','data_inicio'=>'2026-09-01','data_fim'=>'2027-07-31','ativa'=>true,'club_id'=>$clubId,'sports_modality_id'=>$modality->id,'status'=>'active']);
        $junior = AgeGroup::create(['club_id'=>$clubId,'code'=>'junior','nome'=>'Júnior','idade_minima'=>17,'idade_maxima'=>18,'ativo'=>true]);
        $senior = AgeGroup::create(['club_id'=>$clubId,'code'=>'senior','nome'=>'Sénior','idade_minima'=>19,'idade_maxima'=>99,'ativo'=>true]);
        SeasonAgeGroupRule::create(['club_id'=>$clubId,'season_id'=>$season->id,'sports_modality_id'=>$modality->id,'age_group_id'=>$junior->id,'birth_year_min'=>2008,'birth_year_max'=>2009,'reference_date'=>'2026-12-31','priority'=>10,'active'=>true]);
        $userId = (string) Str::uuid();
        $resolved = app(AgeGroupPlacementService::class)->resolve($clubId,$userId,$season->id,$modality->id,'2008-04-15');
        $this->assertSame($junior->id,$resolved['age_group']->id);
        $this->assertSame('rule',$resolved['source']);
        AthleteAgeGroupOverride::create(['club_id'=>$clubId,'user_id'=>$userId,'season_id'=>$season->id,'sports_modality_id'=>$modality->id,'age_group_id'=>$senior->id,'reason'=>'Decisão técnica','active'=>true,'effective_at'=>now()]);
        $resolved = app(AgeGroupPlacementService::class)->resolve($clubId,$userId,$season->id,$modality->id,'2008-04-15');
        $this->assertSame($senior->id,$resolved['age_group']->id);
        $this->assertSame('override',$resolved['source']);
    }
}

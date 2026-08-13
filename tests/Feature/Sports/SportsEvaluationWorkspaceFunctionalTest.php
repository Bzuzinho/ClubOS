<?php

declare(strict_types=1);

namespace Tests\Feature\Sports;

use App\Models\SportsEvaluationAnswer;
use App\Models\SportsEvaluationCampaignAthlete;
use App\Models\SportsEvaluationCriterion;
use App\Models\SportsEvaluationModelVersion;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use App\Services\Desportivo\SportsEvaluationWorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SportsEvaluationWorkspaceFunctionalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sports.club_id', 'bscn');
    }

    public function test_model_is_versioned_and_published_versions_are_immutable(): void
    {
        $actor = User::factory()->create();
        $service = app(SportsEvaluationWorkspaceService::class);
        $model = $service->createModel(['name'=>'Avaliação global'], $actor);
        $version = $model->versions()->firstOrFail();
        $section = $service->createSection($version, ['name'=>'Técnica','weight'=>100]);
        $criterion = $service->createCriterion($section, ['name'=>'Eficiência','response_type'=>'scale','min_value'=>1,'max_value'=>5,'weight'=>100]);

        $service->publishVersion($version->fresh());

        $this->assertDatabaseHas('sports_evaluation_model_versions', ['id'=>$version->id,'state'=>'published']);
        $this->expectException(ValidationException::class);
        $service->updateCriterion($criterion->fresh(), ['name'=>'Alterado']);
    }

    public function test_fork_clones_published_structure_without_rewriting_original(): void
    {
        $actor = User::factory()->create();
        $service = app(SportsEvaluationWorkspaceService::class);
        $model = $service->createModel(['name'=>'Avaliação global'], $actor);
        $v1 = $model->versions()->firstOrFail();
        $section = $service->createSection($v1, ['name'=>'Técnica','weight'=>100]);
        $service->createCriterion($section, ['name'=>'Eficiência','response_type'=>'scale','min_value'=>1,'max_value'=>5,'weight'=>100]);
        $service->publishVersion($v1->fresh());

        $v2 = $service->forkVersion($v1->fresh(), $actor);
        $newCriterion = $v2->sections()->firstOrFail()->criteria()->firstOrFail();
        $service->updateCriterion($newCriterion, ['name'=>'Eficiência técnica']);

        $this->assertSame(2, $v2->version_number);
        $this->assertSame('Eficiência', SportsEvaluationCriterion::query()->whereHas('section', fn($q)=>$q->where('evaluation_model_version_id',$v1->id))->firstOrFail()->name);
        $this->assertSame('Eficiência técnica', $newCriterion->fresh()->name);
    }

    public function test_campaign_materializes_active_group_members_at_publication(): void
    {
        [$actor,$athlete,$group,$service,$version] = $this->publishedFixture();
        TrainingGroupMembership::query()->create([
            'club_id'=>'bscn','training_group_id'=>$group->id,'user_id'=>$athlete->id,
            'is_primary'=>true,'starts_at'=>now()->subMonth()->toDateString(),'created_by'=>$actor->id,
        ]);
        $campaign = $service->createCampaign([
            'evaluation_model_version_id'=>$version->id,'name'=>'Setembro','starts_at'=>now()->toDateString(),'group_ids'=>[$group->id],
        ], $actor);

        $service->publishCampaign($campaign->fresh());

        $this->assertDatabaseHas('sports_evaluation_campaign_athletes', ['campaign_id'=>$campaign->id,'user_id'=>$athlete->id,'state'=>'pending']);
    }

    public function test_completed_evaluation_persists_answer_snapshots(): void
    {
        [$actor,$athlete,$group,$service,$version] = $this->publishedFixture();
        TrainingGroupMembership::query()->create(['club_id'=>'bscn','training_group_id'=>$group->id,'user_id'=>$athlete->id,'is_primary'=>true,'starts_at'=>now()->subDay()->toDateString(),'created_by'=>$actor->id]);
        $campaign = $service->createCampaign(['evaluation_model_version_id'=>$version->id,'name'=>'Inicial','starts_at'=>now()->toDateString(),'group_ids'=>[$group->id]],$actor);
        $service->publishCampaign($campaign->fresh());
        $campaignAthlete = SportsEvaluationCampaignAthlete::query()->where('campaign_id',$campaign->id)->where('user_id',$athlete->id)->firstOrFail();
        $evaluation = $service->evaluationFor($campaignAthlete,$actor);
        $criterion = $version->sections()->firstOrFail()->criteria()->firstOrFail();

        $service->completeEvaluation($evaluation, [
            'answers'=>[(string)$criterion->id=>['value'=>4,'comment'=>'Boa evolução']],
            'summary'=>'Evolução consistente','objectives'=>'Melhorar viragens',
        ], $actor);

        $answer = SportsEvaluationAnswer::query()->where('evaluation_id',$evaluation->id)->firstOrFail();
        $this->assertSame('Eficiência', $answer->criterion_name_snapshot);
        $this->assertSame('Técnica', $answer->section_name_snapshot);
        $this->assertSame('scale', $answer->response_type_snapshot);
        $this->assertSame('completed', $evaluation->fresh()->state);
        $this->assertSame('completed', $campaignAthlete->fresh()->state);
    }

    public function test_required_criterion_blocks_completion_when_unanswered(): void
    {
        [$actor,$athlete,$group,$service,$version] = $this->publishedFixture();
        TrainingGroupMembership::query()->create(['club_id'=>'bscn','training_group_id'=>$group->id,'user_id'=>$athlete->id,'is_primary'=>true,'starts_at'=>now()->subDay()->toDateString(),'created_by'=>$actor->id]);
        $campaign = $service->createCampaign(['evaluation_model_version_id'=>$version->id,'name'=>'Inicial','starts_at'=>now()->toDateString(),'group_ids'=>[$group->id]],$actor);
        $service->publishCampaign($campaign->fresh());
        $evaluation = $service->evaluationFor(SportsEvaluationCampaignAthlete::query()->where('campaign_id',$campaign->id)->firstOrFail(),$actor);

        $this->expectException(ValidationException::class);
        $service->completeEvaluation($evaluation, ['answers'=>[]], $actor);
    }

    public function test_routes_include_full_criterion_crud_and_evaluation_lifecycle(): void
    {
        $source = file_get_contents(base_path('routes/desportivo_evaluations.php'));
        $this->assertStringContainsString("Route::post('/seccoes/{section}/criterios'", $source);
        $this->assertStringContainsString("Route::put('/criterios/{criterion}'", $source);
        $this->assertStringContainsString("Route::delete('/criterios/{criterion}'", $source);
        $this->assertStringContainsString("/concluir'", $source);
        $this->assertStringContainsString("/reabrir'", $source);
    }

    private function publishedFixture(): array
    {
        $actor = User::factory()->create();
        $athlete = User::factory()->create(['estado'=>'ativo','tipo_membro'=>['atleta'],'ativo_desportivo'=>true]);
        $group = TrainingGroup::query()->create(['club_id'=>'bscn','code'=>'A','name'=>'Competição A','modality'=>'natacao','active'=>true,'created_by'=>$actor->id]);
        $service = app(SportsEvaluationWorkspaceService::class);
        $model = $service->createModel(['name'=>'Avaliação global'], $actor);
        $version = SportsEvaluationModelVersion::query()->where('evaluation_model_id',$model->id)->firstOrFail();
        $section = $service->createSection($version, ['name'=>'Técnica','weight'=>100]);
        $service->createCriterion($section, ['name'=>'Eficiência','response_type'=>'scale','min_value'=>1,'max_value'=>5,'weight'=>100,'required'=>true]);
        $service->publishVersion($version->fresh());
        return [$actor,$athlete,$group,$service,$version->fresh()];
    }
}

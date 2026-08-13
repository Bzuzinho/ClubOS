<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\SportsEvaluation;
use App\Models\SportsEvaluationCampaign;
use App\Models\SportsEvaluationCampaignAthlete;
use App\Models\SportsEvaluationCriterion;
use App\Models\SportsEvaluationModel;
use App\Models\SportsEvaluationModelVersion;
use App\Models\SportsEvaluationSection;
use App\Models\User;
use App\Services\Desportivo\SportsEvaluationWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsEvaluationWorkspaceController extends Controller
{
    public function __construct(private readonly SportsEvaluationWorkspaceService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Desportivo/EvaluationsWorkspace', $this->service->workspace($request));
    }

    public function storeModel(Request $request): RedirectResponse
    {
        $data=$request->validate(['name'=>'required|string|max:255','description'=>'nullable|string']);
        $this->service->createModel($data,$request->user());
        return back();
    }

    public function updateModel(Request $request, SportsEvaluationModel $model): RedirectResponse
    {
        $data=$request->validate(['name'=>'sometimes|required|string|max:255','description'=>'nullable|string']);
        $this->service->updateModel($model,$data,$request->user());
        return back();
    }

    public function destroyModel(SportsEvaluationModel $model): RedirectResponse
    {
        $this->service->archiveModel($model); return back();
    }

    public function forkVersion(Request $request, SportsEvaluationModelVersion $version): RedirectResponse
    {
        $this->service->forkVersion($version,$request->user()); return back();
    }

    public function publishVersion(SportsEvaluationModelVersion $version): RedirectResponse
    {
        $this->service->publishVersion($version); return back();
    }

    public function storeSection(Request $request, SportsEvaluationModelVersion $version): RedirectResponse
    {
        $data=$request->validate(['name'=>'required|string|max:255','description'=>'nullable|string','weight'=>'nullable|numeric|min:0|max:100','sort_order'=>'nullable|integer|min:0','active'=>'nullable|boolean']);
        $this->service->createSection($version,$data); return back();
    }

    public function updateSection(Request $request, SportsEvaluationSection $section): RedirectResponse
    {
        $data=$request->validate(['name'=>'sometimes|required|string|max:255','description'=>'nullable|string','weight'=>'nullable|numeric|min:0|max:100','sort_order'=>'nullable|integer|min:0','active'=>'nullable|boolean']);
        $this->service->updateSection($section,$data); return back();
    }

    public function destroySection(SportsEvaluationSection $section): RedirectResponse
    {
        $this->service->deleteSection($section); return back();
    }

    public function storeCriterion(Request $request, SportsEvaluationSection $section): RedirectResponse
    {
        $data=$this->criterionData($request);
        $this->service->createCriterion($section,$data); return back();
    }

    public function updateCriterion(Request $request, SportsEvaluationCriterion $criterion): RedirectResponse
    {
        $this->service->updateCriterion($criterion,$this->criterionData($request,false)); return back();
    }

    public function destroyCriterion(SportsEvaluationCriterion $criterion): RedirectResponse
    {
        $this->service->deleteCriterion($criterion); return back();
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        $data=$request->validate([
            'evaluation_model_version_id'=>'required|uuid','season_id'=>'nullable|uuid','name'=>'required|string|max:255','description'=>'nullable|string',
            'starts_at'=>'nullable|date','due_at'=>'nullable|date|after_or_equal:starts_at','group_ids'=>'required|array|min:1','group_ids.*'=>'uuid',
        ]);
        $this->service->createCampaign($data,$request->user()); return back();
    }

    public function publishCampaign(SportsEvaluationCampaign $campaign): RedirectResponse
    {
        $this->service->publishCampaign($campaign); return back();
    }

    public function startEvaluation(Request $request, SportsEvaluationCampaignAthlete $campaignAthlete): JsonResponse
    {
        return response()->json($this->evaluationPayload($this->service->evaluationFor($campaignAthlete,$request->user())));
    }

    public function saveEvaluation(Request $request, SportsEvaluation $evaluation): JsonResponse
    {
        $data=$request->validate(['answers'=>'nullable|array','summary'=>'nullable|string','objectives'=>'nullable|string']);
        return response()->json($this->evaluationPayload($this->service->saveEvaluation($evaluation,$data,$request->user())));
    }

    public function completeEvaluation(Request $request, SportsEvaluation $evaluation): JsonResponse
    {
        $data=$request->validate(['answers'=>'nullable|array','summary'=>'nullable|string','objectives'=>'nullable|string']);
        return response()->json($this->evaluationPayload($this->service->completeEvaluation($evaluation,$data,$request->user())));
    }

    public function reopenEvaluation(Request $request, SportsEvaluation $evaluation): JsonResponse
    {
        $data=$request->validate(['reason'=>'required|string|max:2000']);
        return response()->json($this->evaluationPayload($this->service->reopenEvaluation($evaluation,$data['reason'],$request->user())));
    }

    public function athleteHistory(User $athlete): JsonResponse
    {
        return response()->json($this->service->athleteHistory($athlete));
    }

    private function criterionData(Request $request, bool $requireName=true): array
    {
        return $request->validate([
            'name'=>($requireName?'required':'sometimes|required').'|string|max:255','description'=>'nullable|string',
            'response_type'=>'sometimes|required|in:scale,number,choice,text,boolean','min_value'=>'nullable|numeric','max_value'=>'nullable|numeric|gte:min_value',
            'options_json'=>'nullable|array','weight'=>'nullable|numeric|min:0|max:100','required'=>'nullable|boolean','allow_comment'=>'nullable|boolean',
            'sort_order'=>'nullable|integer|min:0','active'=>'nullable|boolean',
        ]);
    }

    private function evaluationPayload(SportsEvaluation $evaluation): array
    {
        $evaluation->loadMissing(['answers','athlete.dadosPessoais','campaign.version.sections.criteria']);
        return [
            'id'=>(string)$evaluation->id,'state'=>$evaluation->state,'athlete_id'=>(string)$evaluation->athlete_user_id,
            'athlete'=>$evaluation->athlete?->dadosPessoais?->nome_completo??$evaluation->athlete?->name??'Atleta',
            'summary'=>$evaluation->summary,'objectives'=>$evaluation->objectives,'completed_at'=>$evaluation->completed_at?->toIso8601String(),
            'sections'=>$evaluation->campaign->version->sections->where('active',true)->whereNull('archived_at')->map(fn($section)=>[
                'id'=>(string)$section->id,'name'=>$section->name,'description'=>$section->description,'weight'=>(float)$section->weight,
                'criteria'=>$section->criteria->where('active',true)->whereNull('archived_at')->map(fn($criterion)=>[
                    'id'=>(string)$criterion->id,'name'=>$criterion->name,'description'=>$criterion->description,'response_type'=>$criterion->response_type,
                    'min_value'=>$criterion->min_value,'max_value'=>$criterion->max_value,'options'=>$criterion->options_json??[],
                    'weight'=>(float)$criterion->weight,'required'=>$criterion->required,'allow_comment'=>$criterion->allow_comment,
                ])->values(),
            ])->values(),
            'answers'=>$evaluation->answers->mapWithKeys(fn($answer)=>[(string)$answer->criterion_id=>[
                'value'=>$answer->value_number??$answer->value_text??$answer->value_choice??$answer->value_boolean,'comment'=>$answer->comment,
            ]]),
        ];
    }
}

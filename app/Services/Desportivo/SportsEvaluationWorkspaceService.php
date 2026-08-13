<?php

namespace App\Services\Desportivo;

use App\Models\SportsEvaluation;
use App\Models\SportsEvaluationAnswer;
use App\Models\SportsEvaluationCampaign;
use App\Models\SportsEvaluationCampaignAthlete;
use App\Models\SportsEvaluationCriterion;
use App\Models\SportsEvaluationModel;
use App\Models\SportsEvaluationModelVersion;
use App\Models\SportsEvaluationSection;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SportsEvaluationWorkspaceService
{
    private const RESPONSE_TYPES = ['scale', 'number', 'choice', 'text', 'boolean'];

    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberIdentityDisplayResolver $identityDisplayResolver,
    ) {}

    public function workspace(Request $request): array
    {
        $view = in_array($request->string('view')->toString(), ['campaigns', 'athletes', 'models'], true)
            ? $request->string('view')->toString()
            : 'campaigns';

        $models = SportsEvaluationModel::query()
            ->where('club_id', $this->clubContext->id())
            ->whereNull('archived_at')
            ->with(['versions.sections.criteria'])
            ->orderBy('name')
            ->get();

        $campaigns = SportsEvaluationCampaign::query()
            ->where('club_id', $this->clubContext->id())
            ->with(['version.modelDefinition', 'groups', 'athletes.evaluation'])
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->get();

        $athleteIds = SportsEvaluationCampaignAthlete::query()
            ->whereHas('campaign', fn ($q) => $q->where('club_id', $this->clubContext->id()))
            ->distinct()->pluck('user_id');
        $athletes = User::query()->whereIn('id', $athleteIds)->with('dadosPessoais')->get();
        $names = $this->identityDisplayResolver->mapDisplayNames($athletes);

        return [
            'view' => $view,
            'models' => $models->map(fn (SportsEvaluationModel $model): array => $this->modelPayload($model))->values(),
            'campaigns' => $campaigns->map(fn (SportsEvaluationCampaign $campaign): array => $this->campaignPayload($campaign))->values(),
            'athletes' => $athletes->map(fn (User $user): array => [
                'id' => (string) $user->id,
                'name' => $names[(string) $user->id] ?? $this->identityDisplayResolver->displayNameOrFallback($user, 'Atleta'),
                'evaluation_count' => SportsEvaluation::query()->where('athlete_user_id', $user->id)->where('state', 'completed')->count(),
            ])->sortBy('name')->values(),
            'groups' => TrainingGroup::query()->forClub($this->clubContext->id())->active()->orderBy('name')->get(['id','name'])
                ->map(fn (TrainingGroup $group): array => ['id'=>(string)$group->id,'name'=>$group->name])->values(),
        ];
    }

    public function createModel(array $data, User $actor): SportsEvaluationModel
    {
        return DB::transaction(function () use ($data, $actor): SportsEvaluationModel {
            $model = SportsEvaluationModel::query()->create([
                'club_id' => $this->clubContext->id(),
                'name' => trim((string) ($data['name'] ?? '')),
                'description' => $this->nullableText($data['description'] ?? null),
                'state' => 'draft',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            SportsEvaluationModelVersion::query()->create([
                'evaluation_model_id' => $model->id,
                'version_number' => 1,
                'state' => 'draft',
                'created_by' => $actor->id,
            ]);
            return $model->fresh(['versions.sections.criteria']);
        }, 3);
    }

    public function updateModel(SportsEvaluationModel $model, array $data, User $actor): SportsEvaluationModel
    {
        $this->assertModelClub($model);
        $model->forceFill([
            'name' => trim((string) ($data['name'] ?? $model->name)),
            'description' => array_key_exists('description', $data) ? $this->nullableText($data['description']) : $model->description,
            'updated_by' => $actor->id,
        ])->save();
        return $model->fresh(['versions.sections.criteria']);
    }

    public function archiveModel(SportsEvaluationModel $model): void
    {
        $this->assertModelClub($model);
        $model->forceFill(['state'=>'archived','archived_at'=>now()])->save();
    }

    public function forkVersion(SportsEvaluationModelVersion $version, User $actor): SportsEvaluationModelVersion
    {
        $this->assertVersionClub($version);
        if ($version->state === 'draft') return $version->load('sections.criteria');

        return DB::transaction(function () use ($version, $actor): SportsEvaluationModelVersion {
            $version->load('sections.criteria');
            $next = SportsEvaluationModelVersion::query()->create([
                'evaluation_model_id' => $version->evaluation_model_id,
                'version_number' => (int) $version->modelDefinition->versions()->max('version_number') + 1,
                'state' => 'draft',
                'based_on_version_id' => $version->id,
                'created_by' => $actor->id,
            ]);
            foreach ($version->sections as $section) {
                $copy = SportsEvaluationSection::query()->create([
                    'evaluation_model_version_id'=>$next->id,'name'=>$section->name,'description'=>$section->description,
                    'weight'=>$section->weight,'sort_order'=>$section->sort_order,'active'=>$section->active,
                ]);
                foreach ($section->criteria as $criterion) {
                    SportsEvaluationCriterion::query()->create([
                        'evaluation_section_id'=>$copy->id,'name'=>$criterion->name,'description'=>$criterion->description,
                        'response_type'=>$criterion->response_type,'min_value'=>$criterion->min_value,'max_value'=>$criterion->max_value,
                        'options_json'=>$criterion->options_json,'weight'=>$criterion->weight,'required'=>$criterion->required,
                        'allow_comment'=>$criterion->allow_comment,'sort_order'=>$criterion->sort_order,'active'=>$criterion->active,
                    ]);
                }
            }
            return $next->fresh(['sections.criteria']);
        }, 3);
    }

    public function publishVersion(SportsEvaluationModelVersion $version): SportsEvaluationModelVersion
    {
        $this->assertVersionClub($version);
        $this->assertDraftVersion($version);
        $version->load('sections.criteria');
        $activeSections = $version->sections->where('active', true)->whereNull('archived_at');
        if ($activeSections->isEmpty()) throw ValidationException::withMessages(['model'=>'O modelo precisa de pelo menos uma secção ativa.']);
        foreach ($activeSections as $section) {
            if ($section->criteria->where('active', true)->whereNull('archived_at')->isEmpty()) {
                throw ValidationException::withMessages(['model'=>"A secção {$section->name} não tem critérios ativos."]);
            }
        }
        $this->validateWeights($activeSections);
        SportsEvaluationModelVersion::query()->where('evaluation_model_id',$version->evaluation_model_id)->where('state','published')->update(['state'=>'archived','archived_at'=>now()]);
        $version->forceFill(['state'=>'published','published_at'=>now()])->save();
        $version->modelDefinition()->update(['state'=>'published']);
        return $version->fresh(['sections.criteria']);
    }

    public function createSection(SportsEvaluationModelVersion $version, array $data): SportsEvaluationSection
    {
        $this->assertVersionClub($version); $this->assertDraftVersion($version);
        return SportsEvaluationSection::query()->create([
            'evaluation_model_version_id'=>$version->id,'name'=>trim((string)$data['name']),
            'description'=>$this->nullableText($data['description']??null),'weight'=>(float)($data['weight']??0),
            'sort_order'=>(int)($data['sort_order']??($version->sections()->max('sort_order')+1)),'active'=>(bool)($data['active']??true),
        ]);
    }

    public function updateSection(SportsEvaluationSection $section, array $data): SportsEvaluationSection
    {
        $this->assertSectionMutable($section);
        $section->forceFill([
            'name'=>trim((string)($data['name']??$section->name)),
            'description'=>array_key_exists('description',$data)?$this->nullableText($data['description']):$section->description,
            'weight'=>(float)($data['weight']??$section->weight),'sort_order'=>(int)($data['sort_order']??$section->sort_order),
            'active'=>(bool)($data['active']??$section->active),
        ])->save();
        return $section->fresh('criteria');
    }

    public function deleteSection(SportsEvaluationSection $section): void
    {
        $this->assertSectionMutable($section);
        $used = SportsEvaluationAnswer::query()->whereIn('criterion_id',$section->criteria()->pluck('id'))->exists();
        if ($used) $section->forceFill(['active'=>false,'archived_at'=>now()])->save(); else $section->delete();
    }

    public function createCriterion(SportsEvaluationSection $section, array $data): SportsEvaluationCriterion
    {
        $this->assertSectionMutable($section);
        return SportsEvaluationCriterion::query()->create($this->criterionData($section,$data));
    }

    public function updateCriterion(SportsEvaluationCriterion $criterion, array $data): SportsEvaluationCriterion
    {
        $this->assertCriterionMutable($criterion);
        $criterion->forceFill($this->criterionData($criterion->section,$data,$criterion))->save();
        return $criterion->fresh();
    }

    public function deleteCriterion(SportsEvaluationCriterion $criterion): void
    {
        $this->assertCriterionMutable($criterion);
        if ($criterion->answers()->exists()) $criterion->forceFill(['active'=>false,'archived_at'=>now()])->save(); else $criterion->delete();
    }

    public function createCampaign(array $data, User $actor): SportsEvaluationCampaign
    {
        $version = SportsEvaluationModelVersion::query()->with('modelDefinition')->findOrFail((string)$data['evaluation_model_version_id']);
        $this->assertVersionClub($version);
        if ($version->state !== 'published') throw ValidationException::withMessages(['version'=>'Seleciona uma versão publicada.']);
        return DB::transaction(function () use ($data,$actor,$version): SportsEvaluationCampaign {
            $campaign = SportsEvaluationCampaign::query()->create([
                'club_id'=>$this->clubContext->id(),'evaluation_model_version_id'=>$version->id,
                'season_id'=>$data['season_id']??null,'name'=>trim((string)$data['name']),
                'description'=>$this->nullableText($data['description']??null),'starts_at'=>$data['starts_at']??null,
                'due_at'=>$data['due_at']??null,'state'=>'draft','created_by'=>$actor->id,
            ]);
            $campaign->groups()->sync(collect($data['group_ids']??[])->filter()->unique()->values()->all());
            return $campaign->fresh(['version.modelDefinition','groups','athletes']);
        },3);
    }

    public function publishCampaign(SportsEvaluationCampaign $campaign): SportsEvaluationCampaign
    {
        $this->assertCampaignClub($campaign);
        if (! in_array($campaign->state,['draft','planned'],true)) throw ValidationException::withMessages(['campaign'=>'A campanha não pode ser publicada neste estado.']);
        $date = $campaign->starts_at ?? now()->toDateString();
        $groupIds = $campaign->groups()->pluck('training_groups.id');
        if ($groupIds->isEmpty()) throw ValidationException::withMessages(['groups'=>'Seleciona pelo menos um grupo.']);
        DB::transaction(function () use ($campaign,$date,$groupIds): void {
            $athleteIds = TrainingGroupMembership::query()->where('club_id',$this->clubContext->id())->whereIn('training_group_id',$groupIds)
                ->where(fn($q)=>$q->whereNull('starts_at')->orWhereDate('starts_at','<=',$date))
                ->where(fn($q)=>$q->whereNull('ends_at')->orWhereDate('ends_at','>=',$date))
                ->distinct()->pluck('user_id');
            foreach ($athleteIds as $userId) SportsEvaluationCampaignAthlete::query()->firstOrCreate(
                ['campaign_id'=>$campaign->id,'user_id'=>$userId],['state'=>'pending','included_at'=>now()]
            );
            $campaign->forceFill(['state'=>'active','published_at'=>now()])->save();
        },3);
        return $campaign->fresh(['version.modelDefinition','groups','athletes.athlete','athletes.evaluation']);
    }

    public function evaluationFor(SportsEvaluationCampaignAthlete $campaignAthlete, User $actor): SportsEvaluation
    {
        $campaignAthlete->load('campaign.version.sections.criteria');
        $this->assertCampaignClub($campaignAthlete->campaign);
        if ($campaignAthlete->state === 'excluded') throw ValidationException::withMessages(['athlete'=>'Este atleta está excluído da campanha.']);
        $evaluation = SportsEvaluation::query()->firstOrCreate(['campaign_athlete_id'=>$campaignAthlete->id],[
            'campaign_id'=>$campaignAthlete->campaign_id,'athlete_user_id'=>$campaignAthlete->user_id,
            'evaluator_user_id'=>$actor->id,'state'=>'draft','started_at'=>now(),
        ]);
        if ($campaignAthlete->state === 'pending') $campaignAthlete->forceFill(['state'=>'draft'])->save();
        return $evaluation->fresh(['answers','campaign.version.sections.criteria','athlete.dadosPessoais']);
    }

    public function saveEvaluation(SportsEvaluation $evaluation, array $data, User $actor): SportsEvaluation
    {
        $this->assertEvaluationMutable($evaluation);
        $evaluation->load('campaign.version.sections.criteria');
        $criteria = $evaluation->campaign->version->sections->where('active',true)->whereNull('archived_at')
            ->flatMap(fn($section)=>$section->criteria->where('active',true)->whereNull('archived_at')->map(fn($criterion)=>[$section,$criterion]));
        DB::transaction(function () use ($evaluation,$data,$actor,$criteria): void {
            foreach ($data['answers']??[] as $criterionId=>$answer) {
                $pair = $criteria->first(fn($pair)=>(string)$pair[1]->id===(string)$criterionId); if(!$pair) continue;
                [$section,$criterion]=$pair; $normalized=$this->normalizeAnswer($criterion,is_array($answer)?$answer:[]);
                SportsEvaluationAnswer::query()->updateOrCreate(['evaluation_id'=>$evaluation->id,'criterion_id'=>$criterion->id],[
                    'criterion_name_snapshot'=>$criterion->name,'section_name_snapshot'=>$section->name,
                    'response_type_snapshot'=>$criterion->response_type,'weight_snapshot'=>$criterion->weight,
                    'min_value_snapshot'=>$criterion->min_value,'max_value_snapshot'=>$criterion->max_value,
                    'options_snapshot'=>$criterion->options_json,...$normalized,
                ]);
            }
            $evaluation->forceFill([
                'summary'=>$this->nullableText($data['summary']??$evaluation->summary),
                'objectives'=>$this->nullableText($data['objectives']??$evaluation->objectives),
                'evaluator_user_id'=>$actor->id,
            ])->save();
        },3);
        return $evaluation->fresh(['answers','campaign.version.sections.criteria']);
    }

    public function completeEvaluation(SportsEvaluation $evaluation, array $data, User $actor): SportsEvaluation
    {
        $this->saveEvaluation($evaluation,$data,$actor);
        $evaluation->load('campaign.version.sections.criteria','answers','campaignAthlete');
        $answers=$evaluation->answers->keyBy(fn($row)=>(string)$row->criterion_id);
        foreach ($evaluation->campaign->version->sections->where('active',true)->whereNull('archived_at') as $section) {
            foreach ($section->criteria->where('active',true)->whereNull('archived_at')->where('required',true) as $criterion) {
                $answer=$answers->get((string)$criterion->id);
                if(!$answer || !$this->answerHasValue($answer)) throw ValidationException::withMessages(['answers'=>"Falta responder a {$criterion->name}."]);
            }
        }
        DB::transaction(function () use ($evaluation): void {
            $evaluation->forceFill(['state'=>'completed','completed_at'=>now()])->save();
            $evaluation->campaignAthlete->forceFill(['state'=>'completed'])->save();
        },3);
        return $evaluation->fresh(['answers','campaign.version.sections.criteria']);
    }

    public function reopenEvaluation(SportsEvaluation $evaluation, string $reason, User $actor): SportsEvaluation
    {
        $this->assertEvaluationClub($evaluation);
        if ($evaluation->state !== 'completed') throw ValidationException::withMessages(['evaluation'=>'Só avaliações concluídas podem ser reabertas.']);
        if (trim($reason)==='') throw ValidationException::withMessages(['reason'=>'Indica o motivo da reabertura.']);
        $evaluation->forceFill(['state'=>'draft','completed_at'=>null,'reopened_at'=>now(),'reopened_by'=>$actor->id,'reopen_reason'=>trim($reason)])->save();
        $evaluation->campaignAthlete()->update(['state'=>'draft']);
        return $evaluation->fresh(['answers']);
    }

    public function athleteHistory(User $athlete): Collection
    {
        $evaluations = SportsEvaluation::query()->where('athlete_user_id',$athlete->id)->where('state','completed')
            ->whereHas('campaign',fn($q)=>$q->where('club_id',$this->clubContext->id()))
            ->with(['campaign.version.modelDefinition','answers'])->orderByDesc('completed_at')->get();
        return $evaluations->map(fn(SportsEvaluation $evaluation): array=>[
            'id'=>(string)$evaluation->id,'campaign'=>$evaluation->campaign->name,
            'model'=>$evaluation->campaign->version->modelDefinition->name,'completed_at'=>$evaluation->completed_at?->toIso8601String(),
            'summary'=>$evaluation->summary,'objectives'=>$evaluation->objectives,
            'sections'=>$evaluation->answers->groupBy('section_name_snapshot')->map(fn($rows,$name)=>[
                'name'=>$name,'score'=>$rows->whereNotNull('value_number')->avg('value_number'),
            ])->values(),
        ])->values();
    }

    private function criterionData(SportsEvaluationSection $section, array $data, ?SportsEvaluationCriterion $existing=null): array
    {
        $type=(string)($data['response_type']??$existing?->response_type??'scale');
        if(!in_array($type,self::RESPONSE_TYPES,true)) throw ValidationException::withMessages(['response_type'=>'Tipo de resposta inválido.']);
        return [
            'evaluation_section_id'=>$section->id,'name'=>trim((string)($data['name']??$existing?->name??'')),
            'description'=>array_key_exists('description',$data)?$this->nullableText($data['description']):$existing?->description,
            'response_type'=>$type,'min_value'=>$data['min_value']??$existing?->min_value,'max_value'=>$data['max_value']??$existing?->max_value,
            'options_json'=>$data['options_json']??$existing?->options_json,'weight'=>(float)($data['weight']??$existing?->weight??0),
            'required'=>(bool)($data['required']??$existing?->required??true),'allow_comment'=>(bool)($data['allow_comment']??$existing?->allow_comment??true),
            'sort_order'=>(int)($data['sort_order']??$existing?->sort_order??($section->criteria()->max('sort_order')+1)),
            'active'=>(bool)($data['active']??$existing?->active??true),
        ];
    }

    private function normalizeAnswer(SportsEvaluationCriterion $criterion, array $answer): array
    {
        $value=$answer['value']??null; $payload=['value_number'=>null,'value_text'=>null,'value_boolean'=>null,'value_choice'=>null,'comment'=>$this->nullableText($answer['comment']??null)];
        if($criterion->response_type==='scale'||$criterion->response_type==='number') {
            if($value!==null&&$value!==''&&!is_numeric($value)) throw ValidationException::withMessages(['answers'=>"{$criterion->name} exige um número."]);
            if($value!==null&&$value!=='') { $number=(float)$value; if($criterion->min_value!==null&&$number<(float)$criterion->min_value) throw ValidationException::withMessages(['answers'=>"{$criterion->name} está abaixo do mínimo."]); if($criterion->max_value!==null&&$number>(float)$criterion->max_value) throw ValidationException::withMessages(['answers'=>"{$criterion->name} está acima do máximo."]); $payload['value_number']=$number; }
        } elseif($criterion->response_type==='boolean') $payload['value_boolean']=$value===null||$value===''?null:(bool)$value;
        elseif($criterion->response_type==='choice') { if($value!==null&&$value!==''&&!collect($criterion->options_json??[])->map('strval')->contains((string)$value)) throw ValidationException::withMessages(['answers'=>"Opção inválida em {$criterion->name}."]); $payload['value_choice']=$value===null||$value===''?null:(string)$value; }
        else $payload['value_text']=$this->nullableText($value);
        return $payload;
    }

    private function answerHasValue(SportsEvaluationAnswer $answer): bool
    {
        return $answer->value_number!==null||$answer->value_text!==null||$answer->value_boolean!==null||$answer->value_choice!==null;
    }

    private function validateWeights(Collection $sections): void
    {
        $sectionWeights=$sections->sum(fn($row)=>(float)$row->weight);
        if($sectionWeights>0&&abs($sectionWeights-100)>0.01) throw ValidationException::withMessages(['weights'=>'Os pesos das secções devem totalizar 100%.']);
        foreach($sections as $section){$criteria=$section->criteria->where('active',true)->whereNull('archived_at');$sum=$criteria->sum(fn($row)=>(float)$row->weight);if($sum>0&&abs($sum-100)>0.01)throw ValidationException::withMessages(['weights'=>"Os pesos dos critérios em {$section->name} devem totalizar 100%."]);}
    }

    private function modelPayload(SportsEvaluationModel $model): array
    {
        $latest=$model->versions->sortByDesc('version_number')->first();
        return ['id'=>(string)$model->id,'name'=>$model->name,'description'=>$model->description,'state'=>$model->state,
            'latest_version'=>$latest?['id'=>(string)$latest->id,'number'=>$latest->version_number,'state'=>$latest->state,'sections'=>$latest->sections->map(fn($section)=>[
                'id'=>(string)$section->id,'name'=>$section->name,'description'=>$section->description,'weight'=>(float)$section->weight,'active'=>$section->active,'archived_at'=>$section->archived_at?->toIso8601String(),
                'criteria'=>$section->criteria->map(fn($criterion)=>['id'=>(string)$criterion->id,'name'=>$criterion->name,'description'=>$criterion->description,'response_type'=>$criterion->response_type,'min_value'=>$criterion->min_value,'max_value'=>$criterion->max_value,'options'=>$criterion->options_json??[],'weight'=>(float)$criterion->weight,'required'=>$criterion->required,'allow_comment'=>$criterion->allow_comment,'active'=>$criterion->active,'archived_at'=>$criterion->archived_at?->toIso8601String()])->values(),
            ])->values()]:null];
    }

    private function campaignPayload(SportsEvaluationCampaign $campaign): array
    {
        $counts=$campaign->athletes->countBy('state');
        return ['id'=>(string)$campaign->id,'name'=>$campaign->name,'description'=>$campaign->description,'state'=>$campaign->state,
            'starts_at'=>$campaign->starts_at?->toDateString(),'due_at'=>$campaign->due_at?->toDateString(),'model'=>$campaign->version?->modelDefinition?->name,
            'version'=>$campaign->version?->version_number,'groups'=>$campaign->groups->map(fn($g)=>['id'=>(string)$g->id,'name'=>$g->name])->values(),
            'athlete_count'=>$campaign->athletes->count(),'pending_count'=>(int)($counts['pending']??0),'draft_count'=>(int)($counts['draft']??0),'completed_count'=>(int)($counts['completed']??0),'excluded_count'=>(int)($counts['excluded']??0)];
    }

    private function assertDraftVersion(SportsEvaluationModelVersion $version): void { if($version->state!=='draft') throw ValidationException::withMessages(['version'=>'Cria uma nova versão de trabalho antes de editar um modelo publicado.']); }
    private function assertSectionMutable(SportsEvaluationSection $section): void { $section->loadMissing('version.modelDefinition');$this->assertVersionClub($section->version);$this->assertDraftVersion($section->version); }
    private function assertCriterionMutable(SportsEvaluationCriterion $criterion): void { $criterion->loadMissing('section.version.modelDefinition');$this->assertSectionMutable($criterion->section); }
    private function assertModelClub(SportsEvaluationModel $model): void { if((string)$model->club_id!==$this->clubContext->id()) abort(404); }
    private function assertVersionClub(SportsEvaluationModelVersion $version): void { $version->loadMissing('modelDefinition.versions');$this->assertModelClub($version->modelDefinition); }
    private function assertCampaignClub(SportsEvaluationCampaign $campaign): void { if((string)$campaign->club_id!==$this->clubContext->id()) abort(404); }
    private function assertEvaluationClub(SportsEvaluation $evaluation): void { $evaluation->loadMissing('campaign');$this->assertCampaignClub($evaluation->campaign); }
    private function assertEvaluationMutable(SportsEvaluation $evaluation): void { $this->assertEvaluationClub($evaluation);if($evaluation->state!=='draft')throw ValidationException::withMessages(['evaluation'=>'A avaliação está concluída. Reabre-a antes de editar.']); }
    private function nullableText(mixed $value): ?string { $text=trim((string)($value??''));return $text===''?null:$text; }
}

<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\AgeGroup;
use App\Models\AthleteAgeGroupOverride;
use App\Models\Season;
use App\Models\SeasonAgeGroupRule;
use App\Models\SportsCoachRole;
use App\Models\SportsModality;
use App\Models\SportsPool;
use App\Models\SportsProgram;
use App\Models\SportsVenue;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupSeason;
use App\Services\Desportivo\SportsStructureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SportsStructureController extends Controller
{
    public function __construct(private readonly SportsStructureService $service) {}

    public function index(): Response
    {
        $clubId = $this->service->clubId();

        return Inertia::render('Desportivo/Estrutura/Index', [
            'modalities' => SportsModality::forClub($clubId)->with('programs')->orderBy('name')->get(),
            'programs' => SportsProgram::forClub($clubId)->with('modality')->orderBy('name')->get(),
            'seasons' => Season::query()->where('club_id', $clubId)->with(['modality', 'programs.program'])->orderByDesc('data_inicio')->get(),
            'ageGroups' => AgeGroup::query()->where('club_id', $clubId)->orderBy('idade_minima')->orderBy('nome')->get(),
            'ageGroupRules' => SeasonAgeGroupRule::query()->where('club_id', $clubId)->with(['season', 'modality', 'ageGroup'])->orderByDesc('priority')->get(),
            'groups' => TrainingGroup::forClub($clubId)->with(['modalityDefinition', 'ageGroups', 'seasonConfigurations.program'])->orderBy('name')->get(),
            'groupSeasons' => TrainingGroupSeason::query()->where('club_id', $clubId)->with(['group', 'season', 'program'])->get(),
            'coachRoles' => SportsCoachRole::forClub($clubId)->orderBy('name')->get(),
            'locations' => SportsVenue::forClub($clubId)->with(['pools.lanes'])->orderBy('name')->get(),
        ]);
    }

    public function storeModality(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('sports_modalities', 'code')->where('club_id', $this->service->clubId())],
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);
        $this->service->createModality($data, $request->user()?->id);

        return back()->with('success', 'Modalidade criada.');
    }

    public function updateModality(Request $request, SportsModality $modality): RedirectResponse
    {
        $data = $request->validate([
            'code' => 'sometimes|required|string|max:64',
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);
        $this->service->updateModality($modality, $data, $request->user()?->id);

        return back()->with('success', 'Modalidade atualizada.');
    }

    public function destroyModality(Request $request, SportsModality $modality): RedirectResponse
    {
        $this->service->retireModality($modality, $request->user()?->id);

        return back()->with('success', 'Modalidade removida ou arquivada conforme o histórico.');
    }

    public function storeProgram(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sports_modality_id' => 'required|uuid',
            'code' => 'required|string|max:64',
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);
        $this->service->createProgram($data, $request->user()?->id);

        return back()->with('success', 'Programa criado.');
    }

    public function updateProgram(Request $request, SportsProgram $program): RedirectResponse
    {
        $data = $request->validate([
            'code' => 'sometimes|required|string|max:64',
            'name' => 'sometimes|required|string|max:150',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);
        $this->service->updateProgram($program, $data, $request->user()?->id);

        return back()->with('success', 'Programa atualizado.');
    }

    public function destroyProgram(Request $request, SportsProgram $program): RedirectResponse
    {
        $this->service->retireProgram($program, $request->user()?->id);

        return back()->with('success', 'Programa removido ou arquivado conforme o histórico.');
    }

    public function syncSeasonProgram(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'season_id' => 'required|uuid',
            'sports_program_id' => 'required|uuid',
            'active' => 'boolean',
            'notes' => 'nullable|string',
        ]);
        $this->service->syncSeasonProgram($data, $request->user()?->id);

        return back()->with('success', 'Programa associado à época.');
    }

    public function closeSeason(Request $request, Season $season): RedirectResponse
    {
        $this->service->closeSeason($season, $request->user()?->id);

        return back()->with('success', 'Época encerrada.');
    }

    public function reopenSeason(Request $request, Season $season): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:3|max:2000']);
        $this->service->reopenSeason($season, $data['reason'], $request->user()?->id);

        return back()->with('success', 'Época reaberta com histórico de decisão.');
    }

    public function storeAgeGroupRule(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'season_id' => 'required|uuid',
            'sports_modality_id' => 'required|uuid',
            'age_group_id' => 'required|uuid',
            'gender' => 'nullable|string|max:32',
            'birth_year_min' => 'nullable|integer',
            'birth_year_max' => 'nullable|integer|gte:birth_year_min',
            'age_min' => 'nullable|integer|min:0',
            'age_max' => 'nullable|integer|gte:age_min',
            'reference_date' => 'nullable|date',
            'priority' => 'nullable|integer',
            'active' => 'boolean',
        ]);
        $this->service->createAgeGroupRule($data, $request->user()?->id);

        return back()->with('success', 'Regra de escalão criada.');
    }

    public function destroyAgeGroupRule(Request $request, SeasonAgeGroupRule $rule): RedirectResponse
    {
        $this->service->retireAgeGroupRule($rule, $request->user()?->id);

        return back()->with('success', 'Regra de escalão desativada.');
    }

    public function storeAgeGroupOverride(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|uuid',
            'season_id' => 'required|uuid',
            'sports_modality_id' => 'required|uuid',
            'age_group_id' => 'required|uuid',
            'reason' => 'required|string|min:3|max:2000',
            'effective_at' => 'nullable|date',
        ]);
        $this->service->createAgeGroupOverride($data, $request->user()?->id);

        return back()->with('success', 'Override técnico de escalão registado.');
    }

    public function destroyAgeGroupOverride(Request $request, AthleteAgeGroupOverride $override): RedirectResponse
    {
        $this->service->endAgeGroupOverride($override, $request->user()?->id);

        return back()->with('success', 'Override técnico terminado sem apagar o histórico.');
    }

    public function storeGroupSeason(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'training_group_id' => 'required|uuid',
            'season_id' => 'required|uuid',
            'sports_program_id' => 'nullable|uuid',
            'active' => 'boolean',
            'notes' => 'nullable|string',
        ]);
        $this->service->syncGroupSeason($data, $request->user()?->id);

        return back()->with('success', 'Grupo configurado para a época.');
    }

    public function storeMembership(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'training_group_season_id' => 'required|uuid',
            'user_id' => 'required|uuid',
            'is_primary' => 'boolean',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'notes' => 'nullable|string|max:2000',
        ]);
        $this->service->assignMembershipWithSeasonContext($data, $request->user()?->id);

        return back()->with('success', 'Atleta associado ao grupo com contexto de época.');
    }

    public function storeCoachRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('sports_coach_roles', 'code')->where('club_id', $this->service->clubId())],
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
        ]);
        $this->service->createCoachRole($data, $request->user()?->id);

        return back()->with('success', 'Função técnica criada.');
    }

    public function storePool(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sports_venue_id' => 'required|uuid',
            'pool_type_config_id' => 'nullable|uuid',
            'code' => 'required|string|max:80',
            'name' => 'required|string|max:150',
            'length_m' => 'nullable|numeric|min:0',
            'indoor' => 'nullable|boolean',
            'capacity' => 'nullable|integer|min:0',
            'active' => 'boolean',
        ]);
        $this->service->createPool($data, $request->user()?->id);

        return back()->with('success', 'Piscina criada.');
    }

    public function storeLane(Request $request, SportsPool $pool): RedirectResponse
    {
        $data = $request->validate([
            'lane_number' => 'required|integer|min:1',
            'name' => 'nullable|string|max:100',
            'capacity' => 'nullable|integer|min:1',
            'active' => 'boolean',
        ]);
        $this->service->addLane($pool, $data, $request->user()?->id);

        return back()->with('success', 'Pista criada.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\AgeGroup;
use App\Models\Season;
use App\Models\SportsCoachRole;
use App\Models\SportsPool;
use App\Models\SportsPoolLane;
use App\Models\SportsVenue;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupCoach;
use App\Models\TrainingGroupMembership;
use App\Services\Desportivo\SportsStructureWorkspaceQueryService;
use App\Services\Desportivo\SportsStructureWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class SportsStructureWorkspaceController extends Controller
{
    public function __construct(
        private readonly SportsStructureWorkspaceService $service,
        private readonly SportsStructureWorkspaceQueryService $query,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Desportivo/Estrutura/Index', $this->query->payload());
    }

    public function storeSeason(Request $request): RedirectResponse
    {
        $data = $request->validate($this->seasonRules());
        $this->service->createSeason($data, $request->user()?->id);
        return back()->with('success', 'Época criada.');
    }

    public function updateSeason(Request $request, Season $season): RedirectResponse
    {
        $data = $request->validate($this->seasonRules(true));
        $this->service->updateSeason($season, $data, $request->user()?->id);
        return back()->with('success', 'Época atualizada.');
    }

    public function destroySeason(Request $request, Season $season): RedirectResponse
    {
        $this->service->retireSeason($season, $request->user()?->id);
        return back()->with('success', 'Época removida ou arquivada de acordo com o histórico.');
    }

    public function storeAgeGroup(Request $request): RedirectResponse
    {
        $this->service->createAgeGroup($request->validate($this->ageGroupRules()));
        return back()->with('success', 'Escalão criado.');
    }

    public function updateAgeGroup(Request $request, AgeGroup $ageGroup): RedirectResponse
    {
        $this->service->updateAgeGroup($ageGroup, $request->validate($this->ageGroupRules($ageGroup)));
        return back()->with('success', 'Escalão atualizado.');
    }

    public function destroyAgeGroup(AgeGroup $ageGroup): RedirectResponse
    {
        $this->service->retireAgeGroup($ageGroup);
        return back()->with('success', 'Escalão removido ou arquivado de acordo com o histórico.');
    }

    public function storeGroup(Request $request): RedirectResponse
    {
        $this->service->createGroup($request->validate($this->groupRules()), $request->user()?->id);
        return back()->with('success', 'Grupo criado.');
    }

    public function updateGroup(Request $request, TrainingGroup $group): RedirectResponse
    {
        $this->service->updateGroup($group, $request->validate($this->groupRules($group)), $request->user()?->id);
        return back()->with('success', 'Grupo atualizado.');
    }

    public function destroyGroup(Request $request, TrainingGroup $group): RedirectResponse
    {
        $this->service->retireGroup($group, $request->user()?->id);
        return back()->with('success', 'Grupo removido ou arquivado de acordo com o histórico.');
    }

    public function updateMembership(Request $request, TrainingGroupMembership $membership): RedirectResponse
    {
        $data = $request->validate([
            'training_group_season_id' => 'required|uuid',
            'is_primary' => 'required|boolean',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
            'notes' => 'nullable|string|max:2000',
        ]);
        $this->service->updateMembership($membership, $data);
        return back()->with('success', 'Associação do atleta atualizada.');
    }

    public function endMembership(Request $request, TrainingGroupMembership $membership): RedirectResponse
    {
        $data = $request->validate(['ends_at' => 'required|date']);
        $this->service->endMembership($membership, $data['ends_at']);
        return back()->with('success', 'Associação do atleta terminada sem apagar o histórico.');
    }

    public function updateCoachRole(Request $request, SportsCoachRole $role): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('sports_coach_roles', 'code')->where('club_id', $this->service->clubId())->ignore($role->id)],
            'name' => 'required|string|max:150',
            'description' => 'nullable|string',
            'active' => 'boolean',
        ]);
        $this->service->updateCoachRole($role, $data, $request->user()?->id);
        return back()->with('success', 'Função técnica atualizada.');
    }

    public function destroyCoachRole(Request $request, SportsCoachRole $role): RedirectResponse
    {
        $this->service->retireCoachRole($role, $request->user()?->id);
        return back()->with('success', 'Função técnica removida ou arquivada.');
    }

    public function storeCoach(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'training_group_season_id' => 'required|uuid',
            'user_id' => 'required|uuid|exists:users,id',
            'sports_coach_role_id' => 'required|uuid',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);
        $this->service->assignCoach($data, $request->user()?->id);
        return back()->with('success', 'Técnico atribuído ao grupo.');
    }

    public function updateCoach(Request $request, TrainingGroupCoach $coach): RedirectResponse
    {
        $data = $request->validate([
            'training_group_season_id' => 'required|uuid',
            'sports_coach_role_id' => 'required|uuid',
            'starts_at' => 'required|date',
            'ends_at' => 'nullable|date|after_or_equal:starts_at',
        ]);
        $this->service->updateCoach($coach, $data);
        return back()->with('success', 'Atribuição técnica atualizada.');
    }

    public function endCoach(Request $request, TrainingGroupCoach $coach): RedirectResponse
    {
        $data = $request->validate(['ends_at' => 'required|date']);
        $this->service->endCoach($coach, $data['ends_at']);
        return back()->with('success', 'Atribuição técnica terminada sem apagar o histórico.');
    }

    public function storeVenue(Request $request): RedirectResponse
    {
        $this->service->createVenue($request->validate($this->venueRules()), $request->user()?->id);
        return back()->with('success', 'Local criado.');
    }

    public function updateVenue(Request $request, SportsVenue $venue): RedirectResponse
    {
        $this->service->updateVenue($venue, $request->validate($this->venueRules($venue)), $request->user()?->id);
        return back()->with('success', 'Local atualizado.');
    }

    public function destroyVenue(Request $request, SportsVenue $venue): RedirectResponse
    {
        $this->service->retireVenue($venue, $request->user()?->id);
        return back()->with('success', 'Local removido ou arquivado.');
    }

    public function updatePool(Request $request, SportsPool $pool): RedirectResponse
    {
        $data = $request->validate([
            'sports_venue_id' => 'required|uuid',
            'code' => ['required', 'string', 'max:80', Rule::unique('sports_pools', 'code')->where(fn ($query) => $query->where('club_id', $this->service->clubId())->where('sports_venue_id', $request->input('sports_venue_id')))->ignore($pool->id)],
            'name' => 'required|string|max:150',
            'length_m' => 'nullable|numeric|min:0',
            'indoor' => 'nullable|boolean',
            'capacity' => 'nullable|integer|min:0',
            'active' => 'boolean',
        ]);
        $this->service->updatePool($pool, $data, $request->user()?->id);
        return back()->with('success', 'Piscina/área atualizada.');
    }

    public function destroyPool(Request $request, SportsPool $pool): RedirectResponse
    {
        $this->service->retirePool($pool, $request->user()?->id);
        return back()->with('success', 'Piscina/área arquivada.');
    }

    public function updateLane(Request $request, SportsPoolLane $lane): RedirectResponse
    {
        $data = $request->validate([
            'lane_number' => ['required', 'integer', 'min:1', Rule::unique('sports_pool_lanes', 'lane_number')->where('sports_pool_id', $lane->sports_pool_id)->ignore($lane->id)],
            'name' => 'nullable|string|max:100',
            'capacity' => 'nullable|integer|min:1',
            'active' => 'boolean',
        ]);
        $this->service->updateLane($lane, $data, $request->user()?->id);
        return back()->with('success', 'Pista atualizada.');
    }

    public function destroyLane(Request $request, SportsPoolLane $lane): RedirectResponse
    {
        $this->service->retireLane($lane, $request->user()?->id);
        return back()->with('success', 'Pista arquivada.');
    }

    private function seasonRules(bool $update = false): array
    {
        return [
            'sports_modality_id' => ($update ? 'sometimes|' : '').'required|uuid',
            'nome' => ($update ? 'sometimes|' : '').'required|string|max:150',
            'ano_temporada' => ($update ? 'sometimes|' : '').'required|string|max:20',
            'data_inicio' => ($update ? 'sometimes|' : '').'required|date',
            'data_fim' => ($update ? 'sometimes|' : '').'required|date|after_or_equal:data_inicio',
            'tipo' => 'nullable|string|max:30',
            'status' => 'nullable|in:planned,active,closed,archived',
            'descricao' => 'nullable|string|max:4000',
        ];
    }

    private function ageGroupRules(?AgeGroup $ageGroup = null): array
    {
        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('age_groups', 'code')->where('club_id', $this->service->clubId())->ignore($ageGroup?->id)],
            'nome' => 'required|string|max:150',
            'descricao' => 'nullable|string|max:2000',
            'idade_minima' => 'nullable|integer|min:0',
            'idade_maxima' => 'nullable|integer|gte:idade_minima',
            'ano_minimo' => 'nullable|integer',
            'ano_maximo' => 'nullable|integer|gte:ano_minimo',
            'sexo' => 'nullable|string|max:32',
            'ativo' => 'boolean',
        ];
    }

    private function groupRules(?TrainingGroup $group = null): array
    {
        return [
            'sports_modality_id' => 'required|uuid',
            'code' => ['required', 'string', 'max:64', Rule::unique('training_groups', 'code')->where('club_id', $this->service->clubId())->ignore($group?->id)],
            'name' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'active' => 'boolean',
            'age_group_ids' => 'array',
            'age_group_ids.*' => 'uuid',
        ];
    }

    private function venueRules(?SportsVenue $venue = null): array
    {
        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('sports_venues', 'code')->where('club_id', $this->service->clubId())->ignore($venue?->id)],
            'name' => 'required|string|max:150',
            'venue_type' => 'required|string|max:64',
            'address' => 'nullable|string|max:1000',
            'active' => 'boolean',
        ];
    }
}

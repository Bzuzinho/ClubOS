<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Macrocycle;
use App\Models\Mesocycle;
use App\Models\Microcycle;
use App\Models\SportsObjective;
use App\Models\Training;
use App\Models\TrainingRecurrence;
use App\Services\Desportivo\SportsPlanningWorkspaceQueryService;
use App\Services\Desportivo\SportsPlanningWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SportsPlanningWorkspaceController extends Controller
{
    public function __construct(
        private readonly SportsPlanningWorkspaceService $service,
        private readonly SportsPlanningWorkspaceQueryService $query,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Desportivo/Planeamento/Index', $this->query->payload($request));
    }

    public function storeMacro(Request $request): RedirectResponse
    {
        $data = $request->validate($this->macroRules());
        $this->service->createMacrocycle($data, $request->user());
        return $this->back($request, 'Macrociclo criado.');
    }

    public function updateMacro(Request $request, Macrocycle $macro): RedirectResponse
    {
        $data = $request->validate($this->macroRules());
        $this->service->updateMacrocycle($macro, $data, $request->user());
        return $this->back($request, 'Macrociclo atualizado.');
    }

    public function destroyMacro(Request $request, Macrocycle $macro): RedirectResponse
    {
        $this->service->archiveMacrocycle($macro, $request->user());
        return $this->back($request, 'Macrociclo removido/arquivado.');
    }

    public function storeMeso(Request $request): RedirectResponse
    {
        $data = $request->validate($this->mesoRules());
        $this->service->createMesocycle($data, $request->user());
        return $this->back($request, 'Mesociclo criado.');
    }

    public function updateMeso(Request $request, Mesocycle $meso): RedirectResponse
    {
        $data = $request->validate($this->mesoRules());
        $this->service->updateMesocycle($meso, $data, $request->user());
        return $this->back($request, 'Mesociclo atualizado.');
    }

    public function destroyMeso(Request $request, Mesocycle $meso): RedirectResponse
    {
        $this->service->archiveMesocycle($meso, $request->user());
        return $this->back($request, 'Mesociclo removido/arquivado.');
    }

    public function storeMicro(Request $request): RedirectResponse
    {
        $data = $request->validate($this->microRules());
        $this->service->createMicrocycle($data, $request->user());
        return $this->back($request, 'Microciclo criado.');
    }

    public function updateMicro(Request $request, Microcycle $micro): RedirectResponse
    {
        $data = $request->validate($this->microRules());
        $this->service->updateMicrocycle($micro, $data, $request->user());
        return $this->back($request, 'Microciclo atualizado.');
    }

    public function destroyMicro(Request $request, Microcycle $micro): RedirectResponse
    {
        $this->service->archiveMicrocycle($micro, $request->user());
        return $this->back($request, 'Microciclo removido/arquivado.');
    }

    public function storeSession(Request $request): RedirectResponse
    {
        $data = $request->validate($this->sessionRules());
        $this->service->createSession($data, $request->user());
        return $this->back($request, 'Sessão planeada.');
    }

    public function updateSession(Request $request, Training $training): RedirectResponse
    {
        $data = $request->validate($this->sessionRules(update: true));
        $this->service->updateSession($training, $data, $request->user());
        return $this->back($request, 'Planeamento da sessão atualizado.');
    }

    public function storeRecurrence(Request $request): RedirectResponse
    {
        $data = $request->validate($this->recurrenceRules());
        $this->service->createRecurrence($data, $request->user());
        return $this->back($request, 'Recorrência criada.');
    }

    public function updateRecurrence(Request $request, TrainingRecurrence $recurrence): RedirectResponse
    {
        $data = $request->validate($this->recurrenceRules());
        $this->service->updateRecurrence($recurrence, $data, $request->user());
        return $this->back($request, 'Recorrência atualizada. As sessões já geradas não foram reescritas.');
    }

    public function destroyRecurrence(Request $request, TrainingRecurrence $recurrence): RedirectResponse
    {
        $this->service->archiveRecurrence($recurrence, $request->user());
        return $this->back($request, 'Recorrência arquivada.');
    }

    public function generateRecurrence(Request $request, TrainingRecurrence $recurrence): RedirectResponse
    {
        $data = $request->validate(['until' => 'required|date', 'season_id' => 'nullable|uuid']);
        $result = $this->service->generateRecurrence($recurrence, $data['until'], $request->user());
        $message = sprintf(
            'Recorrência gerada: %d criada(s), %d já existente(s), %d bloqueada(s).',
            count($result['created']),
            count($result['skipped']),
            count($result['blocked'])
        );
        return $this->back($request, $message);
    }

    public function storeObjective(Request $request): RedirectResponse
    {
        $data = $request->validate($this->objectiveRules());
        $this->service->createObjective($data, $request->user());
        return $this->back($request, 'Objetivo criado.');
    }

    public function reviseObjective(Request $request, SportsObjective $objective): RedirectResponse
    {
        $data = $request->validate($this->objectiveRules(revision: true));
        $this->service->reviseObjective($objective, $data, $request->user());
        return $this->back($request, 'Nova versão do objetivo criada.');
    }

    private function macroRules(): array
    {
        return [
            'epoca_id' => 'required|uuid|exists:seasons,id',
            'nome' => 'required|string|max:255',
            'tipo' => 'required|string|max:50',
            'data_inicio' => 'required|date',
            'data_fim' => 'required|date|after_or_equal:data_inicio',
            'objetivo_principal' => 'nullable|string|max:255',
            'objetivo_secundario' => 'nullable|string|max:255',
        ];
    }

    private function mesoRules(): array
    {
        return [
            'season_id' => 'nullable|uuid',
            'macrociclo_id' => 'required|uuid|exists:macrocycles,id',
            'nome' => 'required|string|max:255',
            'data_inicio' => 'required|date',
            'data_fim' => 'required|date|after_or_equal:data_inicio',
            'objetivo_principal' => 'required|string|max:255',
            'objetivo_secundario' => 'nullable|string|max:255',
        ];
    }

    private function microRules(): array
    {
        return [
            'season_id' => 'nullable|uuid',
            'mesociclo_id' => 'required|uuid|exists:mesocycles,id',
            'semana' => 'required|string|max:100',
            'data_inicio' => 'required|date',
            'data_fim' => 'required|date|after_or_equal:data_inicio',
            'volume_previsto' => 'nullable|integer|min:0',
            'objetivo_principal' => 'nullable|string|max:255',
            'objetivo_secundario' => 'nullable|string|max:255',
            'is_recovery_week' => 'boolean',
            'notas' => 'nullable|string',
        ];
    }

    private function sessionRules(bool $update = false): array
    {
        $required = $update ? 'sometimes|required' : 'required';
        return [
            'season_id' => 'required|uuid|exists:seasons,id',
            'microciclo_id' => 'required|uuid|exists:microcycles,id',
            'data' => "{$required}|date",
            'hora_inicio' => "{$required}|date_format:H:i",
            'hora_fim' => "{$required}|date_format:H:i|after:hora_inicio",
            'sports_venue_id' => 'nullable|uuid|exists:sports_venues,id',
            'sports_pool_id' => 'nullable|uuid|exists:sports_pools,id',
            'responsavel_id' => 'nullable|uuid|exists:users,id',
            'training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id',
            'tipo_treino' => 'nullable|string|max:100',
            'instrucao' => 'nullable|string',
            'session_status' => 'nullable|in:draft,published',
            'volume_planeado_m' => 'nullable|integer|min:0',
            'athlete_ids' => 'nullable|array',
            'athlete_ids.*' => 'uuid|exists:users,id',
            'training_groups' => 'nullable|array',
            'training_groups.*.training_group_id' => 'required|uuid|exists:training_groups,id',
            'training_groups.*.training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id',
            'training_groups.*.instruction' => 'nullable|string',
            'training_groups.*.lanes' => 'nullable|array',
            'training_groups.*.lanes.*.lane_id' => 'required|uuid|exists:sports_pool_lanes,id',
            'training_groups.*.lanes.*.planned_capacity' => 'nullable|integer|min:1|max:999',
        ];
    }

    private function recurrenceRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'starts_on' => 'required|date',
            'ends_on' => 'nullable|date|after_or_equal:starts_on',
            'frequency' => 'required|in:daily,weekly',
            'interval' => 'nullable|integer|min:1|max:52',
            'weekdays' => 'nullable|array',
            'weekdays.*' => 'integer|min:1|max:7',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'season_id' => 'required|uuid|exists:seasons,id',
            'macrocycle_id' => 'nullable|uuid|exists:macrocycles,id',
            'mesocycle_id' => 'nullable|uuid|exists:mesocycles,id',
            'microcycle_id' => 'required|uuid|exists:microcycles,id',
            'sports_venue_id' => 'nullable|uuid|exists:sports_venues,id',
            'sports_pool_id' => 'nullable|uuid|exists:sports_pools,id',
            'responsavel_id' => 'nullable|uuid|exists:users,id',
            'training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id',
            'instruction' => 'nullable|string',
            'training_type' => 'nullable|string|max:100',
            'session_status_template' => 'nullable|in:draft,published',
            'groups' => 'nullable|array',
            'groups.*.training_group_id' => 'required|uuid|exists:training_groups,id',
            'groups.*.training_plan_version_id' => 'nullable|uuid|exists:training_plan_versions,id',
            'groups.*.instruction' => 'nullable|string',
            'groups.*.lanes' => 'nullable|array',
            'groups.*.lanes.*.lane_id' => 'required|uuid|exists:sports_pool_lanes,id',
            'groups.*.lanes.*.planned_capacity' => 'nullable|integer|min:1|max:999',
        ];
    }

    private function objectiveRules(bool $revision = false): array
    {
        $base = [
            'season_id' => 'nullable|uuid',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'objective_type' => 'required|in:text,measurable',
            'target_value' => 'nullable|numeric',
            'target_text' => 'nullable|string|max:255',
            'target_unit' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ];
        if (! $revision) {
            $base += [
                'target_type' => 'required|in:season,age_group',
                'target_id' => 'required|uuid',
                'modality' => 'nullable|string|max:64',
                'starts_at' => 'nullable|date',
                'due_at' => 'nullable|date',
            ];
        }
        return $base;
    }

    private function back(Request $request, string $message): RedirectResponse
    {
        $seasonId = $request->input('season_id') ?: $request->input('epoca_id') ?: $request->query('season_id');
        return redirect()->route('desportivo.planeamento', array_filter(['season_id' => $seasonId]))
            ->with('success', $message);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\StoreTrainingRequest;
use App\Http\Requests\Sports\UpdateTrainingRequest;
use App\Models\Training;
use App\Services\Desportivo\CreateTrainingAction;
use App\Services\Desportivo\Queries\GetTrainingDashboardSummary;
use App\Services\Desportivo\Queries\GetTrainingPoolDeckView;
use App\Services\Desportivo\SportsClubContext;
use App\Services\Desportivo\UpdateTrainingScheduleAction;
use Illuminate\Http\JsonResponse;

class TrainingController extends Controller
{
    public function __construct(
        private readonly CreateTrainingAction $createTrainingAction,
        private readonly UpdateTrainingScheduleAction $updateTrainingScheduleAction,
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /**
     * GET /api/desportivo/trainings
     * Retorna lista de treinos com dados essenciais.
     */
    public function index(): JsonResponse
    {
        $trainings = Training::query()
            ->where('club_id', $this->clubContext->id())
            ->with([
                'ageGroups:id',
                'venue:id,name',
                'sessionGroups.group:id,name',
                'sessionGroups.lanes:id,name,lane_number',
            ])
            ->withCount([
                'athleteRecords as num_atletas',
                'athleteRecords as presente_count' => fn ($query) => $query->where('presente', true),
            ])
            ->orderBy('data', 'desc')
            ->limit(100)
            ->get()
            ->map(fn ($training) => [
                'id' => $training->id,
                'numero_treino' => $training->numero_treino,
                'data' => $training->data,
                'hora_inicio' => $training->hora_inicio,
                'hora_fim' => $training->hora_fim,
                'tipo_treino' => $training->tipo_treino,
                'descricao_treino' => $training->descricao_treino,
                'volume_planeado_m' => $training->volume_planeado_m ?? 0,
                'escaloes' => $training->ageGroups->pluck('id')->values()->all(),
                'venue' => $training->venue,
                'groups' => $training->sessionGroups,
                'schedule_review_required' => (bool) $training->schedule_review_required,
                'schedule_conflicts' => $training->schedule_conflicts_snapshot ?? [],
                'num_atletas' => $training->num_atletas,
                'presente_count' => $training->presente_count,
            ]);

        return response()->json($trainings);
    }

    /**
     * POST /api/desportivo/trainings
     * Cria um treino através do mesmo fluxo canónico usado pelo módulo web.
     */
    public function store(StoreTrainingRequest $request): JsonResponse
    {
        $training = $this->createTrainingAction->execute(
            $request->validated(),
            $request->user(),
        );

        return response()->json(
            $training->load([
                'ageGroups:id',
                'venue',
                'sessionGroups.group',
                'sessionGroups.planVersion',
                'sessionGroups.lanes',
            ]),
            201
        );
    }

    /**
     * GET /api/desportivo/trainings/{id}
     * Retorna um treino com detalhe completo.
     */
    public function show(Training $training): JsonResponse
    {
        $this->assertTenant($training);

        $poolDeckView = app(GetTrainingPoolDeckView::class)($training->id);
        $summary = app(GetTrainingDashboardSummary::class)($training->id);

        return response()->json([
            'training' => $poolDeckView['training'],
            'athletes' => $poolDeckView['athlete_records'],
            'summary' => $summary,
        ]);
    }

    /**
     * PUT /api/desportivo/trainings/{id}
     * Atualiza o planeamento da sessão através do domínio canónico.
     */
    public function update(UpdateTrainingRequest $request, Training $training): JsonResponse
    {
        $training = $this->updateTrainingScheduleAction->execute(
            $training,
            $request->validated(),
            $request->user(),
        );

        return response()->json($training);
    }

    /**
     * DELETE /api/desportivo/trainings/{id}
     * Elimina um treino e suas presenças.
     */
    public function destroy(Training $training): JsonResponse
    {
        $this->assertTenant($training);

        $training->athleteRecords()->delete();
        $training->metrics()->delete();
        $training->delete();

        return response()->json(['message' => 'Treino eliminado com sucesso']);
    }

    private function assertTenant(Training $training): void
    {
        abort_unless((string) $training->club_id === $this->clubContext->id(), 404);
    }
}

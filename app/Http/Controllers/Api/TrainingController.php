<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\StoreTrainingRequest;
use App\Http\Requests\Sports\UpdateTrainingRequest;
use App\Models\Training;
use App\Services\Desportivo\CreateTrainingAction;
use App\Services\Desportivo\Queries\GetTrainingDashboardSummary;
use App\Services\Desportivo\Queries\GetTrainingPoolDeckView;
use Illuminate\Http\JsonResponse;

class TrainingController extends Controller
{
    public function __construct(
        private readonly CreateTrainingAction $createTrainingAction,
    ) {
    }

    /**
     * GET /api/desportivo/trainings
     * Retorna lista de treinos com dados essenciais.
     */
    public function index(): JsonResponse
    {
        $trainings = Training::with(['ageGroups:id'])
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
                'tipo_treino' => $training->tipo_treino,
                'descricao_treino' => $training->descricao_treino,
                'volume_planeado_m' => $training->volume_planeado_m ?? 0,
                'escaloes' => $training->ageGroups->pluck('id')->values()->all(),
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

        return response()->json($training->load('ageGroups:id'), 201);
    }

    /**
     * GET /api/desportivo/trainings/{id}
     * Retorna um treino com detalhe completo.
     */
    public function show(Training $training): JsonResponse
    {
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
     * Atualiza um treino.
     */
    public function update(UpdateTrainingRequest $request, Training $training): JsonResponse
    {
        $validated = $request->validated();

        $training->update(collect($validated)->except('escaloes')->all());

        if (array_key_exists('escaloes', $validated)) {
            $training->ageGroups()->sync($validated['escaloes'] ?? []);
        }

        return response()->json($training->load('ageGroups:id'));
    }

    /**
     * DELETE /api/desportivo/trainings/{id}
     * Elimina um treino e suas presenças.
     */
    public function destroy(Training $training): JsonResponse
    {
        $training->athleteRecords()->delete();
        $training->metrics()->delete();
        $training->delete();

        return response()->json(['message' => 'Treino eliminado com sucesso']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\Desportivo\CompetitionLifecycleService;
use App\Services\Desportivo\Queries\GetCompetitionListSummary;
use App\Services\Desportivo\Queries\GetCompetitionResultsView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CompetitionController extends Controller
{
    public function __construct(
        private readonly GetCompetitionListSummary $listSummary,
        private readonly GetCompetitionResultsView $resultsView,
        private readonly CompetitionLifecycleService $lifecycleService,
    ) {
    }

    public function index(): JsonResponse
    {
        $competitions = ($this->listSummary)(-1)
            ->map(fn ($comp) => [
                'id' => $comp->id,
                'titulo' => $comp->nome,
                'nome' => $comp->nome,
                'data_inicio' => $comp->data_inicio,
                'data_fim' => $comp->data_fim,
                'local' => $comp->local,
                'tipo' => $comp->tipo,
                'tipo_prova' => $comp->tipo,
                'status' => $comp->status,
                'evento_id' => $comp->eventProjection?->event_id ?: $comp->evento_id,
                'projection_status' => $comp->eventProjection?->status,
                'total_provas' => (int) ($comp->total_provas ?? 0),
                'total_resultados' => (int) ($comp->total_resultados ?? 0),
                'total_inscritos' => (int) ($comp->total_inscritos ?? 0),
            ]);

        return response()->json($competitions);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);

        $validated = $request->validate([
            'nome' => ['required', 'string', 'max:255'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['nullable', 'date', 'after_or_equal:data_inicio'],
            'local' => ['nullable', 'string'],
            'tipo_prova' => ['nullable', 'string'],
        ]);

        $competition = $this->lifecycleService->create([
            'nome' => $validated['nome'],
            'data_inicio' => $validated['data_inicio'],
            'data_fim' => $validated['data_fim'] ?? null,
            'local' => $validated['local'] ?? 'N/A',
            'tipo' => $validated['tipo_prova'] ?? 'prova',
        ], $actor);

        return response()->json($competition, 201);
    }

    public function show(Competition $competition): JsonResponse
    {
        $view = ($this->resultsView)($competition->id);
        $competition = $view['competition'];

        return response()->json([
            'id' => $competition->id,
            'nome' => $competition->nome,
            'data_inicio' => $competition->data_inicio,
            'data_fim' => $competition->data_fim,
            'local' => $competition->local,
            'tipo_prova' => $competition->tipo,
            'status' => $competition->status,
            'evento_id' => $competition->eventProjection?->event_id ?: $competition->evento_id,
            'projection_status' => $competition->eventProjection?->status,
            'provas' => $view['provas'],
            'team_results' => $view['team_results'],
        ]);
    }

    public function update(Request $request, Competition $competition): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);

        $validated = $request->validate([
            'nome' => ['sometimes', 'string', 'max:255'],
            'data_inicio' => ['sometimes', 'date'],
            'data_fim' => ['sometimes', 'nullable', 'date'],
            'local' => ['sometimes', 'nullable', 'string'],
            'tipo_prova' => ['sometimes', 'string'],
            'status' => ['sometimes', Rule::in(['scheduled', 'cancelled', 'completed', 'archived'])],
            'cancellation_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $changes = collect($validated)->except('tipo_prova')->all();
        if (array_key_exists('tipo_prova', $validated)) {
            $changes['tipo'] = $validated['tipo_prova'];
        }

        $competition = $this->lifecycleService->update($competition, $changes, $actor);

        return response()->json($competition);
    }

    public function destroy(Request $request, Competition $competition): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);

        $competition = $this->lifecycleService->archive($competition, $actor);

        return response()->json([
            'message' => 'Competição arquivada',
            'competition' => $competition,
        ]);
    }
}

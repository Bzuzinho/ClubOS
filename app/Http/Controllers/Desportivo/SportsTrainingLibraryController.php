<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\TrainingPlan;
use App\Services\Desportivo\SportsClubContext;
use App\Services\Desportivo\SportsTrainingLibraryQueryService;
use App\Services\Desportivo\TrainingPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsTrainingLibraryController extends Controller
{
    public function __construct(
        private readonly SportsTrainingLibraryQueryService $query,
        private readonly TrainingPlanService $plans,
        private readonly SportsClubContext $clubContext,
    ) {
    }

    public function index(): Response
    {
        return Inertia::render('Desportivo/Index', ['tab' => 'biblioteca'] + $this->query->payload());
    }

    public function store(Request $request): RedirectResponse
    {
        $this->plans->create($this->validatePayload($request, true), $request->user());
        return redirect()->route('desportivo.biblioteca')->with('success', 'Plano criado na Biblioteca.');
    }

    public function revise(Request $request, TrainingPlan $plan): RedirectResponse
    {
        $payload = $this->validatePayload($request, false);
        $this->plans->revise($plan, $payload, $request->user(), $payload['motivo_revisao'] ?? null);
        return redirect()->route('desportivo.biblioteca')->with('success', 'Nova versão do plano criada.');
    }

    public function duplicate(Request $request, TrainingPlan $plan): RedirectResponse
    {
        $validated = $request->validate(['nome' => 'nullable|string|max:255', 'codigo' => 'nullable|string|max:80']);
        $this->plans->duplicate($plan, $request->user(), $validated);
        return redirect()->route('desportivo.biblioteca')->with('success', 'Plano duplicado como novo rascunho.');
    }

    public function archive(TrainingPlan $plan): RedirectResponse
    {
        $this->plans->archive($plan);
        return redirect()->route('desportivo.biblioteca')->with('success', 'Plano arquivado sem eliminar o histórico.');
    }

    public function restore(string $plan): RedirectResponse
    {
        $model = TrainingPlan::withTrashed()->findOrFail($plan);
        abort_unless((string) $model->club_id === $this->clubContext->id(), 404);
        $model->restore();
        $model->forceFill(['estado' => 'draft'])->save();
        return redirect()->route('desportivo.biblioteca')->with('success', 'Plano reativado como rascunho.');
    }

    /** @return array<string,mixed> */
    private function validatePayload(Request $request, bool $creating): array
    {
        return $request->validate([
            'nome' => ($creating ? 'required' : 'sometimes') . '|string|max:255',
            'codigo' => 'nullable|string|max:80',
            'descricao' => 'nullable|string',
            'sports_modality_id' => 'nullable|uuid|exists:sports_modalities,id',
            'tags' => 'nullable|array|max:20',
            'tags.*' => 'string|max:60',
            'estado' => 'nullable|in:draft,published',
            'publicar' => 'nullable|boolean',
            'tipo_treino' => 'nullable|string|max:100',
            'descricao_treino' => 'nullable|string',
            'notas_gerais' => 'nullable|string',
            'instrucao' => 'nullable|string',
            'motivo_revisao' => 'nullable|string|max:1000',
            'metadados' => 'nullable|array',
            'blocks' => 'required|array|min:1|max:50',
            'blocks.*.nome' => 'required|string|max:100',
            'blocks.*.rondas' => 'required|integer|min:1|max:99',
            'blocks.*.notas' => 'nullable|string|max:1000',
            'blocks.*.series' => 'required|array|min:1|max:100',
            'blocks.*.series.*.repeticoes' => 'nullable|integer|min:1|max:999',
            'blocks.*.series.*.distancia_m' => 'nullable|integer|min:1|max:100000',
            'blocks.*.series.*.exercicio' => 'nullable|string|max:255',
            'blocks.*.series.*.sports_stroke_id' => 'nullable|uuid|exists:sports_strokes,id',
            'blocks.*.series.*.training_zone_config_id' => 'nullable|uuid|exists:training_zone_configs,id',
            'blocks.*.series.*.intervalo' => 'nullable|string|max:50',
            'blocks.*.series.*.saida' => 'nullable|string|max:50',
            'blocks.*.series.*.timing_mode' => 'nullable|in:none,each_rep,whole_series',
            'blocks.*.series.*.material_ids' => 'nullable|array',
            'blocks.*.series.*.material_ids.*' => 'uuid|exists:sports_training_materials,id',
            'blocks.*.series.*.observacoes' => 'nullable|string|max:1000',
        ]);
    }
}

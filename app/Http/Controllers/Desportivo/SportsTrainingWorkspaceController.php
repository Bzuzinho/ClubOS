<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Models\TrainingPlanVersion;
use App\Services\Desportivo\SportsClubContext;
use App\Services\Desportivo\SportsTrainingWorkspaceQueryService;
use App\Services\Desportivo\TrainingSessionOperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsTrainingWorkspaceController extends Controller
{
    public function __construct(
        private readonly SportsTrainingWorkspaceQueryService $query,
        private readonly TrainingSessionOperationService $operations,
        private readonly SportsClubContext $clubContext,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Desportivo/Treinos/Index', $this->query->payload($request));
    }

    public function cancel(Request $request, Training $training): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:2000']);
        $this->operations->cancel($training, $data['reason'], $request->user());
        return $this->back($request, 'Sessão cancelada. O histórico foi preservado.');
    }

    public function applyPlanVersion(Request $request, Training $training): RedirectResponse
    {
        $data = $request->validate(['training_plan_version_id' => 'required|uuid|exists:training_plan_versions,id','reason' => 'nullable|string|max:2000']);
        $version = TrainingPlanVersion::query()->where('club_id', $this->clubContext->id())->findOrFail($data['training_plan_version_id']);
        $this->operations->applyPlanVersion($training, $version, $request->user(), $data['reason'] ?? null);
        return $this->back($request, 'Versão do plano aplicada apenas a esta sessão.');
    }

    public function overrideSnapshot(Request $request, Training $training): RedirectResponse
    {
        $data = $request->validate([
            'reason' => 'required|string|max:2000','blocks' => 'required|array|min:1','blocks.*.name' => 'required|string|max:120',
            'blocks.*.rounds' => 'required|integer|min:1|max:99','blocks.*.series' => 'required|array|min:1',
            'blocks.*.series.*.repeticoes' => 'required|integer|min:1|max:999','blocks.*.series.*.distancia_m' => 'nullable|integer|min:0|max:100000',
            'blocks.*.series.*.exercicio' => 'nullable|string|max:255','blocks.*.series.*.training_zone_config_id' => 'nullable|uuid',
            'blocks.*.series.*.sports_stroke_id' => 'nullable|uuid','blocks.*.series.*.zona_intensidade' => 'nullable|string|max:30',
            'blocks.*.series.*.estilo' => 'nullable|string|max:80','blocks.*.series.*.intervalo' => 'nullable|string|max:50',
            'blocks.*.series.*.saida' => 'nullable|string|max:50','blocks.*.series.*.timing_mode' => 'required|in:none,each_rep,whole_series',
            'blocks.*.series.*.material_ids' => 'nullable|array','blocks.*.series.*.material_ids.*' => 'uuid','blocks.*.series.*.material' => 'nullable|array',
            'blocks.*.series.*.observacoes' => 'nullable|string',
        ]);
        $this->operations->overrideSnapshot($training, $data['blocks'], $data['reason'], $request->user());
        return $this->back($request, 'Snapshot técnico adaptado apenas nesta sessão.');
    }

    private function back(Request $request, string $message): RedirectResponse
    {
        $seasonId = $request->input('season_id') ?: $request->query('season_id');
        return redirect()->route('desportivo.treinos', array_filter(['season_id' => $seasonId]))->with('success', $message);
    }
}

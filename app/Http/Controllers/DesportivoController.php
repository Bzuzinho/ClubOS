<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Desportivo\SportsCaisWorkspaceController;
use App\Http\Requests\Sports\StoreTrainingMetricRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Transitional route compatibility shell.
 *
 * The former Desportivo runtime was physically removed after the canonical
 * workspaces cutover. This class contains no sports queries or writes. It
 * exists only while historical route declarations are being retired from the
 * monolithic web route file.
 */
final class DesportivoController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('desportivo.dashboard.index');
    }

    public function planeamento(): RedirectResponse
    {
        return redirect()->route('desportivo.planeamento.index');
    }

    public function treinos(): RedirectResponse
    {
        return redirect()->route('desportivo.treinos.index');
    }

    public function presencas(Request $request): RedirectResponse
    {
        $parameters = [];
        if ($request->filled('training_id')) {
            $parameters['training_id'] = $request->string('training_id')->toString();
        }

        return redirect()->route('desportivo.cais', $parameters);
    }

    public function cais(): RedirectResponse
    {
        return redirect()->route('desportivo.cais');
    }

    public function competicoes(): RedirectResponse
    {
        return redirect()->route('desportivo.competicoes.index');
    }

    public function relatorios(): RedirectResponse
    {
        return redirect()->route('desportivo.analise.index');
    }

    public function storeSeason(): never { $this->retired(); }
    public function updateSeason(): never { $this->retired(); }
    public function deleteSeason(): never { $this->retired(); }
    public function storeMacrocycle(): never { $this->retired(); }
    public function updateMacrocycle(): never { $this->retired(); }
    public function deleteMacrocycle(): never { $this->retired(); }
    public function storeMesocycle(): never { $this->retired(); }
    public function updateMesocycle(): never { $this->retired(); }
    public function deleteMesocycle(): never { $this->retired(); }
    public function storeTraining(): never { $this->retired(); }
    public function scheduleTraining(): never { $this->retired(); }
    public function updateTraining(): never { $this->retired(); }
    public function duplicateTraining(): never { $this->retired(); }
    public function deleteTraining(): never { $this->retired(); }
    public function updateTrainingPresencas(): never { $this->retired(); }
    public function addAthleteToTraining(): never { $this->retired(); }
    public function removeAthleteFromTraining(): never { $this->retired(); }
    public function updatePresencas(): never { $this->retired(); }
    public function markAllPresent(): never { $this->retired(); }
    public function clearAllPresences(): never { $this->retired(); }

    public function getCaisMetrics(Request $request): JsonResponse
    {
        return app(SportsCaisWorkspaceController::class)->metrics($request);
    }

    public function storeCaisMetrics(StoreTrainingMetricRequest $request): JsonResponse
    {
        return app(SportsCaisWorkspaceController::class)->storeMetrics($request);
    }

    private function retired(): never
    {
        abort(410, 'Endpoint Desportivo legacy retirado. Utilize a workspace canónica correspondente.');
    }
}

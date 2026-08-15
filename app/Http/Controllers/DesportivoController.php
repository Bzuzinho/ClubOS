<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Desportivo\SportsAnalysisWorkspaceController;
use App\Http\Controllers\Desportivo\SportsCaisWorkspaceController;
use App\Http\Controllers\Desportivo\SportsCompetitionWorkspaceController;
use App\Http\Controllers\Desportivo\SportsDashboardWorkspaceController;
use App\Http\Controllers\Desportivo\SportsPlanningWorkspaceController;
use App\Http\Controllers\Desportivo\SportsTrainingWorkspaceController;
use App\Http\Requests\Sports\StoreTrainingMetricRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;

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
    public function index(): Response
    {
        return app(SportsDashboardWorkspaceController::class)->index();
    }

    public function planeamento(Request $request): Response
    {
        return app(SportsPlanningWorkspaceController::class)->index($request);
    }

    public function treinos(Request $request): Response
    {
        return app(SportsTrainingWorkspaceController::class)->index($request);
    }

    public function presencas(Request $request): Response
    {
        return app(SportsCaisWorkspaceController::class)->index($request);
    }

    public function cais(Request $request): Response
    {
        return app(SportsCaisWorkspaceController::class)->index($request);
    }

    public function competicoes(Request $request): Response
    {
        return app(SportsCompetitionWorkspaceController::class)->index($request);
    }

    public function relatorios(): Response
    {
        return app(SportsAnalysisWorkspaceController::class)->index();
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

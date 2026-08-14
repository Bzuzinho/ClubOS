<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Models\TrainingGroup;
use App\Models\User;
use App\Services\Desportivo\SportsAnalysisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsAnalysisWorkspaceController extends Controller
{
    public function __construct(private readonly SportsAnalysisWorkspaceService $workspace)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Desportivo/AnalysisWorkspace', $this->workspace->workspace());
    }

    public function athlete(Request $request, User $athlete): JsonResponse
    {
        $weeks = (int) $request->integer('weeks', 12);
        return response()->json($this->workspace->athlete($athlete, $weeks));
    }

    public function group(Request $request, TrainingGroup $group): JsonResponse
    {
        $weeks = (int) $request->integer('weeks', 12);
        return response()->json($this->workspace->group($group, $weeks));
    }

    public function competition(Competition $competition): JsonResponse
    {
        return response()->json($this->workspace->competition($competition));
    }
}

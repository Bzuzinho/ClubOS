<?php

declare(strict_types=1);

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\Desportivo\SportsCompetitionWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsCompetitionWorkspaceController extends Controller
{
    public function __construct(private readonly SportsCompetitionWorkspaceService $workspace)
    {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Desportivo/CompetitionsWorkspace', $this->workspace->workspace($request));
    }

    public function show(Competition $competition): JsonResponse
    {
        return response()->json($this->workspace->detail($competition));
    }
}

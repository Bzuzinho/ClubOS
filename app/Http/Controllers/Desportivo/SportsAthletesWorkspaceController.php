<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Desportivo\SportsAthletesWorkspaceService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

final class SportsAthletesWorkspaceController extends Controller
{
    public function __construct(private readonly SportsAthletesWorkspaceService $workspace)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Desportivo/AthletesWorkspace', $this->workspace->workspace());
    }

    public function show(User $athlete): JsonResponse
    {
        return response()->json($this->workspace->athlete($athlete));
    }
}

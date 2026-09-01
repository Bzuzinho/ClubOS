<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\StoreTeamResultRequest;
use App\Models\Competition;
use App\Models\TeamResult;
use App\Services\Desportivo\SportsClubContext;
use Illuminate\Http\JsonResponse;

class TeamResultController extends Controller
{
    public function __construct(private readonly SportsClubContext $clubContext)
    {
    }

    public function index(): JsonResponse
    {
        $rows = TeamResult::with('competition')
            ->whereHas('competition', fn ($query) => $query->forClub($this->clubContext->id()))
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        return response()->json($rows);
    }

    public function store(StoreTeamResultRequest $request): JsonResponse
    {
        $validated = $request->validated();

        Competition::query()
            ->forClub($this->clubContext->id())
            ->findOrFail($validated['competicao_id']);

        $teamResult = TeamResult::query()->create($validated);

        return response()->json($teamResult, 201);
    }

    public function destroy(TeamResult $teamResult): JsonResponse
    {
        $teamResult = TeamResult::query()
            ->whereKey($teamResult->id)
            ->whereHas('competition', fn ($query) => $query->forClub($this->clubContext->id()))
            ->firstOrFail();

        $teamResult->delete();

        return response()->json(['message' => 'Resultado de equipa removido']);
    }
}

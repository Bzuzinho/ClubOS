<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Competition;
use App\Services\Desportivo\SportsResultsWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsResultsWorkspaceController extends Controller
{
    public function __construct(private readonly SportsResultsWorkspaceService $workspace)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Desportivo/ResultsWorkspace', $this->workspace->workspace());
    }

    public function show(Competition $competition): JsonResponse
    {
        return response()->json($this->workspace->detail($competition));
    }

    public function bulkStore(Request $request, Competition $competition): JsonResponse
    {
        $data = $request->validate([
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.prova_id' => ['required', 'uuid', 'exists:provas,id'],
            'rows.*.user_id' => ['required', 'uuid', 'exists:users,id'],
            'rows.*.tempo_oficial' => ['nullable', 'numeric', 'min:0'],
            'rows.*.posicao' => ['nullable', 'integer', 'min:1'],
            'rows.*.pontos_fina' => ['nullable', 'integer', 'min:0'],
            'rows.*.status' => ['required', 'in:ok,dsq,dns,dnf'],
            'rows.*.observacoes' => ['nullable', 'string'],
            'rows.*.splits' => ['sometimes', 'array'],
            'rows.*.splits.*.distance_m' => ['required', 'integer', 'min:1'],
            'rows.*.splits.*.time' => ['required', 'numeric', 'min:0'],
        ]);

        return response()->json([
            'saved_ids' => $this->workspace->saveBulk($competition, $data['rows']),
            'workspace' => $this->workspace->detail($competition),
        ]);
    }
}

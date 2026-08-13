<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Services\Desportivo\SportsRecordsWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsRecordsWorkspaceController extends Controller
{
    public function __construct(private readonly SportsRecordsWorkspaceService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Desportivo/RecordsWorkspace', $this->service->workspace($request));
    }

    public function training(Training $training): JsonResponse
    {
        return response()->json($this->service->trainingDetail($training));
    }

    public function athlete(Request $request, string $athlete): JsonResponse
    {
        return response()->json($this->service->athleteTimeline($athlete, [
            'from'=>$request->date('from')?->toDateString(),
            'to'=>$request->date('to')?->toDateString(),
        ]));
    }
}

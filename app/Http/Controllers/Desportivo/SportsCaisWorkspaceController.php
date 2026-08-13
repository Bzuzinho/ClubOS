<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Models\User;
use App\Services\Desportivo\SportsCaisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsCaisWorkspaceController extends Controller
{
    public function __construct(private readonly SportsCaisWorkspaceService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Desportivo/CaisWorkspace', $this->service->payload($request));
    }

    public function presence(Request $request, Training $training, User $athlete): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['status' => 'required|in:presente,ausente,dispensado,atrasado']);
        $record = $this->service->updatePresence($training, $athlete, $data['status'], $request->user());
        if ($request->expectsJson()) {
            return response()->json(['status' => $record->estado, 'present' => (bool) $record->presente]);
        }
        return back()->with('success', 'Presença atualizada.');
    }

    public function quick(Request $request, Training $training, User $athlete): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:96', 'value' => 'nullable']);
        return response()->json($this->service->saveQuick($training, $athlete, $data['code'], $data['value'] ?? null, $request->user()));
    }

    public function register(Request $request, Training $training, User $athlete): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:presente,ausente,dispensado,atrasado',
            'behavior' => 'nullable|string|max:120',
            'material' => 'nullable|string|max:120',
            'technical_note' => 'nullable|string|max:5000',
            'advice' => 'nullable|string|max:5000',
            'metrics' => 'nullable|array',
            'metrics.*.code' => 'required|string|max:96',
            'metrics.*.value' => 'nullable',
        ]);
        return response()->json($this->service->saveRegister($training, $athlete, $data, $request->user()));
    }
}

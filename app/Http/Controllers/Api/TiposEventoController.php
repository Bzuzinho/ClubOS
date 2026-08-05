<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EventType;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TiposEventoController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $eventTypes = EventType::orderBy('nome')->get();
        return response()->json($eventTypes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nome' => 'required|string|max:255',
            'descricao' => 'nullable|string',
            'categoria' => 'nullable|string|max:50',
            'cor' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
            'ativo' => 'nullable|boolean',
            'gera_taxa' => 'nullable|boolean',
            'permite_convocatoria' => 'nullable|boolean',
            'gera_presencas' => 'nullable|boolean',
            'requer_transporte' => 'nullable|boolean',
            'visibilidade_default' => 'nullable|in:publico,privado,restrito',
        ]);

        $validated['ativo'] = $validated['ativo'] ?? true;
        $eventType = EventType::create($validated);

        return response()->json($eventType, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(EventType $eventType): JsonResponse
    {
        return response()->json($eventType);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, EventType $eventType): JsonResponse
    {
        $validated = $request->validate([
            'nome' => 'sometimes|string|max:255',
            'descricao' => 'nullable|string',
            'categoria' => 'nullable|string|max:50',
            'cor' => 'sometimes|string|max:20',
            'icon' => 'sometimes|string|max:50',
            'ativo' => 'sometimes|boolean',
            'gera_taxa' => 'sometimes|boolean',
            'permite_convocatoria' => 'sometimes|boolean',
            'gera_presencas' => 'sometimes|boolean',
            'requer_transporte' => 'sometimes|boolean',
            'visibilidade_default' => 'sometimes|in:publico,privado,restrito',
        ]);

        $eventType->update($validated);

        return response()->json($eventType);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(EventType $eventType): JsonResponse
    {
        $eventType->delete();

        return response()->json(['message' => 'Tipo de evento eliminado com sucesso']);
    }
}

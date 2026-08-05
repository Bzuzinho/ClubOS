<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Models\Event;
use App\Services\Eventos\EventLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EventsController extends Controller
{
    public function __construct(
        private readonly EventLifecycleService $eventLifecycleService,
    ) {
    }

    /**
     * GET /api/events
     */
    public function index(Request $request): JsonResponse
    {
        $query = Event::with(['tipoConfig', 'costCenter', 'creator', 'ageGroups']);

        // Filter by type if provided
        if ($request->has('tipo')) {
            $query->where('tipo', $request->get('tipo'));
        }

        // Filter by status if provided
        if ($request->has('estado')) {
            $query->where('estado', $request->get('estado'));
        }

        // Filter by date range
        if ($request->has('data_inicio')) {
            $query->where('data_inicio', '>=', $request->get('data_inicio'));
        }
        if ($request->has('data_fim')) {
            $query->where('data_inicio', '<=', $request->get('data_fim'));
        }

        $events = $query->orderBy('data_inicio', 'desc')->get()
            ->each(fn (Event $event) => $event->append('escaloes_elegiveis'));
        
        return response()->json($events);
    }

    /**
     * POST /api/events
     */
    public function store(StoreEventRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $ageGroupIds = $validated['escaloes_elegiveis'] ?? [];
        unset($validated['escaloes_elegiveis'], $validated['evento_pai_id']);

        $validated['descricao'] = $validated['descricao'] ?? '';
        $validated['criado_por'] = $request->user()->id;
        $validated['estado'] = $validated['estado'] ?? 'rascunho';

        $event = $this->eventLifecycleService->create($validated, $ageGroupIds);
        
        return response()->json($event, 201);
    }

    /**
     * GET /api/events/{id}
     */
    public function show(string $id): JsonResponse
    {
        $event = Event::with(['tipoConfig', 'costCenter', 'creator', 'participants', 'ageGroups'])
            ->findOrFail($id)
            ->append('escaloes_elegiveis');
        return response()->json($event);
    }

    /**
     * PUT /api/events/{id}
     */
    public function update(UpdateEventRequest $request, string $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $validated = $request->validated();
        $ageGroupIds = $validated['escaloes_elegiveis'] ?? [];
        unset($validated['escaloes_elegiveis'], $validated['criado_por'], $validated['evento_pai_id']);

        $event = $this->eventLifecycleService->update($event, $validated, $ageGroupIds);
        
        return response()->json($event);
    }

    /**
     * DELETE /api/events/{id}
     */
    public function destroy(string $id): JsonResponse
    {
        $event = Event::findOrFail($id);
        $this->eventLifecycleService->delete($event);
        
        return response()->json(['message' => 'Event deleted successfully']);
    }
}

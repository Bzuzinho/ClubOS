<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\User;
use App\Services\Eventos\EventParticipantEligibilityService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class EventAttendancesController extends Controller
{
    public function __construct(
        private readonly EventParticipantEligibilityService $participantEligibilityService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = EventAttendance::with(['event', 'user', 'registeredBy']);

        if ($request->has('evento_id')) {
            $query->where('evento_id', $request->get('evento_id'));
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->get('user_id'));
        }

        $attendances = $query->orderBy('registado_em', 'desc')->get();
        return response()->json($attendances);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'evento_id' => 'required|uuid|exists:events,id',
            'user_id' => 'required|uuid|exists:users,id',
            'estado' => 'required|in:presente,ausente,justificado,pendente',
            'hora_chegada' => 'nullable|date_format:H:i',
            'observacoes' => 'nullable|string',
            'provas' => 'nullable|array',
            'registado_por' => 'nullable|uuid|exists:users,id',
            'registado_em' => 'nullable|date',
        ]);

        $validated['registado_por'] = $validated['registado_por'] ?? auth()->id();
        $validated['registado_em'] = $validated['registado_em'] ?? now();

        $this->ensureAttendancesEditable($validated['evento_id']);
        $this->participantEligibilityService->assertEligible(
            Event::query()->findOrFail($validated['evento_id']),
            User::query()->with('athleteSportsData:id,user_id,escalao_id')->findOrFail($validated['user_id'])
        );

        $attendance = EventAttendance::create($validated);
        
        return response()->json($attendance, 201);
    }

    public function show(string $id): JsonResponse
    {
        $attendance = EventAttendance::with(['event', 'user', 'registeredBy'])->findOrFail($id);
        return response()->json($attendance);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $attendance = EventAttendance::findOrFail($id);
        $this->ensureAttendancesEditable($attendance->evento_id);
        
        $validated = $request->validate([
            'estado' => 'sometimes|in:presente,ausente,justificado,pendente',
            'hora_chegada' => 'sometimes|date_format:H:i',
            'observacoes' => 'sometimes|string',
            'provas' => 'sometimes|array',
        ]);

        $attendance->update($validated);
        return response()->json($attendance);
    }

    public function destroy(string $id): JsonResponse
    {
        $attendance = EventAttendance::findOrFail($id);
        $this->ensureAttendancesEditable($attendance->evento_id);
        $attendance->delete();
        return response()->json(['message' => 'Attendance record deleted successfully']);
    }

    /**
     * Get attendances for a specific event
     */
    public function getEventAttendances(string $eventId): JsonResponse
    {
        $attendances = EventAttendance::with(['user', 'event'])
            ->where('evento_id', $eventId)
            ->orderBy('registado_em', 'desc')
            ->get();
        
        return response()->json($attendances);
    }

    /**
     * Create attendance for an event
     */
    public function createEventAttendance(Request $request, string $eventId): JsonResponse
    {
        $this->ensureAttendancesEditable($eventId);

        $validated = $request->validate([
            'user_id' => 'required|uuid|exists:users,id',
            'estado' => 'required|in:presente,ausente,justificado,pendente',
            'hora_chegada' => 'nullable|date_format:H:i',
            'observacoes' => 'nullable|string',
            'provas' => 'nullable|array',
            'data_presenca' => 'nullable|date',
        ]);

        $registadoPor = auth()->id() ?? $validated['user_id'];
        $this->participantEligibilityService->assertEligible(
            Event::query()->findOrFail($eventId),
            User::query()->with('athleteSportsData:id,user_id,escalao_id')->findOrFail($validated['user_id'])
        );

        $attendance = EventAttendance::updateOrCreate(
            [
                'evento_id' => $eventId,
                'user_id' => $validated['user_id'],
            ],
            [
                'estado' => $validated['estado'],
                'hora_chegada' => $validated['hora_chegada'] ?? null,
                'observacoes' => $validated['observacoes'] ?? null,
                'provas' => $validated['provas'] ?? [],
                'registado_por' => $registadoPor,
                'registado_em' => now(),
            ]
        );

        return response()->json($attendance, 200);
    }

    /**
     * Update attendance provas for a user in an event
     */
    public function updateUserProvas(Request $request, string $eventId, string $userId): JsonResponse
    {
        $this->ensureAttendancesEditable($eventId);
        $this->participantEligibilityService->assertEligible(
            Event::query()->findOrFail($eventId),
            User::query()->with('athleteSportsData:id,user_id,escalao_id')->findOrFail($userId)
        );

        $validated = $request->validate([
            'provas' => 'required|array',
        ]);

        $registadoPor = auth()->id() ?? $userId;

        $attendance = EventAttendance::updateOrCreate(
            [
                'evento_id' => $eventId,
                'user_id' => $userId,
            ],
            [
                'estado' => 'pendente',
                'provas' => $validated['provas'],
                'registado_por' => $registadoPor,
                'registado_em' => now(),
            ]
        );

        return response()->json($attendance);
    }

    /**
     * Delete attendance for a user in an event
     */
    public function deleteUserAttendance(string $eventId, string $userId): JsonResponse
    {
        $this->ensureAttendancesEditable($eventId);

        EventAttendance::where('evento_id', $eventId)
            ->where('user_id', $userId)
            ->delete();

        return response()->json(['message' => 'Attendance record deleted successfully']);
    }

    private function ensureAttendancesEditable(string $eventId): void
    {
        $event = Event::with('trainings')->findOrFail($eventId);

        if (!$event->canEditAttendances()) {
            abort(403, 'As presencas deste treino sao geridas no modulo Desportivo.');
        }
    }
}

<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingPoolDeckTimer;
use App\Services\Desportivo\PoolDeckMetricService;
use App\Services\Desportivo\PoolDeckSessionService;
use App\Services\Desportivo\PoolDeckTimerService;
use App\Services\Desportivo\Queries\GetPoolDeckWorkspace;
use App\Services\Desportivo\Queries\GetTrainingPoolDeckView;
use App\Services\Desportivo\TrainingScheduleExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PoolDeckController extends Controller
{
    public function workspace(Request $request, GetPoolDeckWorkspace $workspace): JsonResponse
    {
        return response()->json($workspace($request));
    }

    public function open(
        Training $training,
        Request $request,
        PoolDeckSessionService $service,
        GetTrainingPoolDeckView $view,
    ): JsonResponse {
        $service->open($training, $request->user());

        return response()->json($view((string) $training->id));
    }

    public function updateAthlete(
        Training $training,
        TrainingAthlete $trainingAthlete,
        Request $request,
        PoolDeckSessionService $service,
        GetTrainingPoolDeckView $view,
    ): JsonResponse {
        abort_unless((string) $trainingAthlete->treino_id === (string) $training->id, 404);

        $service->updateAthlete($trainingAthlete, $request->all(), $request->user());

        return response()->json($view((string) $training->id));
    }

    public function metric(
        Training $training,
        Request $request,
        PoolDeckMetricService $service,
        GetTrainingPoolDeckView $view,
    ): JsonResponse {
        $metric = $service->record($training, $request->all(), $request->user());

        return response()->json([
            'metric_id' => $metric->id,
            'workspace' => $view((string) $training->id),
        ], 201);
    }

    public function startTimer(
        Training $training,
        Request $request,
        PoolDeckTimerService $service,
        GetTrainingPoolDeckView $view,
    ): JsonResponse {
        $timer = $service->start($training, $request->all(), $request->user());

        return response()->json([
            'timer_id' => $timer->id,
            'workspace' => $view((string) $training->id),
        ], 201);
    }

    public function timerEvent(
        Training $training,
        TrainingPoolDeckTimer $timer,
        string $event,
        Request $request,
        PoolDeckTimerService $service,
        GetTrainingPoolDeckView $view,
    ): JsonResponse {
        abort_unless((string) $timer->training_id === (string) $training->id, 404);

        $service->event($timer, $event, $request->all(), $request->user());

        return response()->json($view((string) $training->id));
    }

    public function exception(
        Training $training,
        Request $request,
        TrainingScheduleExceptionService $service,
        GetTrainingPoolDeckView $view,
    ): JsonResponse {
        $validated = $request->validate([
            'exception_type' => 'required|string|in:lane_change,group_change,venue_change,time_change',
            'before_state' => 'nullable|array',
            'after_state' => 'required|array',
            'reason' => 'required|string|max:2000',
        ]);

        $service->record(
            $training,
            $validated['exception_type'],
            $validated['before_state'] ?? null,
            $validated['after_state'],
            $validated['reason'],
            $request->user(),
        );

        return response()->json($view((string) $training->id));
    }

    public function close(
        Training $training,
        Request $request,
        PoolDeckSessionService $service,
        GetTrainingPoolDeckView $view,
    ): JsonResponse {
        $service->close($training, $request->user());

        return response()->json($view((string) $training->id));
    }
}
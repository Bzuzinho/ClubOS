<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Models\SportsLiveMeasurement;
use App\Models\SportsLiveMetricDefinition;
use App\Models\SportsLiveMonitoring;
use App\Models\Training;
use App\Models\TrainingSeries;
use App\Models\User;
use App\Services\Desportivo\SportsLiveWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class SportsLiveWorkspaceController extends Controller
{
    public function __construct(private readonly SportsLiveWorkspaceService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Desportivo/LiveWorkspace', $this->service->payload($request));
    }

    public function startPlanned(Request $request, Training $training): JsonResponse
    {
        $data = $request->validate(['series_id'=>'required|uuid','training_athlete_ids'=>'required|array|min:1','training_athlete_ids.*'=>'required|uuid','client_measurement_id'=>'nullable|string|max:120']);
        $series = TrainingSeries::query()->findOrFail($data['series_id']);
        return response()->json($this->service->startPlanned($training,$series,$data['training_athlete_ids'],$request->user(),$data['client_measurement_id'] ?? null));
    }

    public function startFree(Request $request, Training $training, User $athlete): JsonResponse
    {
        $data = $request->validate(['client_measurement_id'=>'nullable|string|max:120']);
        return response()->json($this->service->startFree($training,$athlete,$request->user(),$data['client_measurement_id'] ?? null));
    }

    public function next(Request $request, SportsLiveMonitoring $monitoring): JsonResponse
    {
        $data = $request->validate(['client_measurement_id'=>'nullable|string|max:120']);
        return response()->json($this->service->next($monitoring,$request->user(),$data['client_measurement_id'] ?? null));
    }

    public function split(Request $request, SportsLiveMeasurement $measurement, User $athlete): JsonResponse
    {
        $data = $request->validate(['elapsed_ms'=>'required|integer|min:0','occurred_at'=>'required|date','client_event_id'=>'required|string|max:160']);
        return response()->json($this->service->split($measurement,$athlete,(int)$data['elapsed_ms'],$data['occurred_at'],$data['client_event_id'],$request->user()));
    }

    public function stop(Request $request, SportsLiveMeasurement $measurement, User $athlete): JsonResponse
    {
        $data = $request->validate(['elapsed_ms'=>'required|integer|min:0','occurred_at'=>'required|date','client_event_id'=>'required|string|max:160']);
        return response()->json($this->service->stop($measurement,$athlete,(int)$data['elapsed_ms'],$data['occurred_at'],$data['client_event_id'],$request->user()));
    }

    public function stopAll(Request $request, SportsLiveMeasurement $measurement): JsonResponse
    {
        $data = $request->validate(['elapsed_ms'=>'required|integer|min:0','occurred_at'=>'required|date','client_event_id'=>'required|string|max:120']);
        return response()->json($this->service->stopAll($measurement,(int)$data['elapsed_ms'],$data['occurred_at'],$data['client_event_id'],$request->user()));
    }

    public function complete(SportsLiveMonitoring $monitoring): JsonResponse
    {
        return response()->json($this->service->complete($monitoring));
    }

    public function destroy(SportsLiveMonitoring $monitoring): JsonResponse
    {
        return response()->json($this->service->cancel($monitoring));
    }

    public function classify(Request $request, SportsLiveMeasurement $measurement, User $athlete): JsonResponse
    {
        $data = $request->validate(['total_distance_m'=>'required|integer|min:1|max:100000','sports_stroke_id'=>'nullable|uuid','stroke_label'=>'required|string|max:120']);
        return response()->json($this->service->classifyFree($measurement,$athlete,(int)$data['total_distance_m'],$data['sports_stroke_id'] ?? null,$data['stroke_label'],$request->user()));
    }

    public function saveMetric(Request $request, Training $training, User $athlete): JsonResponse
    {
        $data = $request->validate(['metric_definition_id'=>'required|uuid','value'=>'nullable','note'=>'nullable|string|max:5000','training_series_id'=>'nullable|uuid','live_measurement_id'=>'nullable|uuid']);
        $definition = SportsLiveMetricDefinition::query()->findOrFail($data['metric_definition_id']);
        return response()->json($this->service->saveMetric($training,$athlete,$definition,$data['value'] ?? null,$data['note'] ?? null,$data['training_series_id'] ?? null,$data['live_measurement_id'] ?? null,$request->user()));
    }

    public function metricHistory(Training $training, User $athlete, SportsLiveMetricDefinition $definition): JsonResponse
    {
        return response()->json(['records'=>$this->service->metricHistory($training,$athlete,$definition)]);
    }

    public function voidLatestMetric(Request $request, Training $training, User $athlete, SportsLiveMetricDefinition $definition): JsonResponse
    {
        return response()->json(['record'=>$this->service->voidLatestMetric($training,$athlete,$definition,$request->user())]);
    }
}

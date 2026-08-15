<?php

namespace App\Http\Controllers\Desportivo;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\StoreTrainingMetricRequest;
use App\Models\Training;
use App\Models\TrainingAthlete;
use App\Models\TrainingMetric;
use App\Models\User;
use App\Services\Desportivo\SportsCaisWorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function metrics(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'treino_id' => 'required|uuid|exists:trainings,id',
            'user_id' => 'required|uuid|exists:users,id',
        ]);

        if (! $this->isAssigned($validated['treino_id'], $validated['user_id'])) {
            return response()->json(['message' => 'Atleta não elegível para este treino.'], 422);
        }

        return response()->json(['rows' => $this->metricRows($validated['treino_id'], $validated['user_id'])]);
    }

    public function storeMetrics(StoreTrainingMetricRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (! $this->isAssigned($validated['treino_id'], $validated['user_id'])) {
            return response()->json(['message' => 'Atleta não elegível para este treino.'], 422);
        }

        $normalizedRows = collect($validated['rows'])
            ->map(fn (array $row): array => [
                'metrica' => $this->normalizeMetricValue($row['metrica'] ?? null),
                'valor' => $this->normalizeMetricValue($row['valor'] ?? null),
                'tempo' => $this->normalizeMetricValue($row['tempo'] ?? null),
                'observacao' => $this->normalizeMetricValue($row['observacao'] ?? null),
            ])
            ->filter(fn (array $row): bool => collect($row)->contains(fn ($value): bool => $value !== null))
            ->values();

        $authId = $request->user()?->id;

        DB::transaction(function () use ($validated, $normalizedRows, $authId): void {
            TrainingMetric::query()
                ->where('treino_id', $validated['treino_id'])
                ->where('user_id', $validated['user_id'])
                ->delete();

            foreach ($normalizedRows as $index => $row) {
                TrainingMetric::query()->create([
                    'treino_id' => $validated['treino_id'],
                    'user_id' => $validated['user_id'],
                    'ordem' => $index + 1,
                    'metrica' => $row['metrica'],
                    'valor' => $row['valor'],
                    'tempo' => $row['tempo'],
                    'observacao' => $row['observacao'],
                    'registado_por' => $authId,
                    'atualizado_por' => $authId,
                ]);
            }
        });

        return response()->json([
            'message' => 'Métricas de Cais guardadas com sucesso.',
            'rows' => $this->metricRows($validated['treino_id'], $validated['user_id']),
        ]);
    }

    private function isAssigned(string $trainingId, string $userId): bool
    {
        return TrainingAthlete::query()
            ->where('treino_id', $trainingId)
            ->where('user_id', $userId)
            ->exists();
    }

    private function metricRows(string $trainingId, string $userId)
    {
        return TrainingMetric::query()
            ->where('treino_id', $trainingId)
            ->where('user_id', $userId)
            ->orderBy('ordem')
            ->get()
            ->map(fn (TrainingMetric $row): array => [
                'id' => $row->id,
                'metrica' => (string) ($row->metrica ?? ''),
                'valor' => (string) ($row->valor ?? ''),
                'tempo' => (string) ($row->tempo ?? ''),
                'observacao' => (string) ($row->observacao ?? ''),
            ])
            ->values();
    }

    private function normalizeMetricValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

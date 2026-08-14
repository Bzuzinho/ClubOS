<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\StoreResultRequest;
use App\Models\CompetitionRegistration;
use App\Models\Result;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CompetitionResultController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $competitionId = $request->query('competition_id');

        $results = Result::with(['athlete', 'prova.competition', 'splits'])
            ->when($competitionId, fn ($query) => $query->whereHas('prova', fn ($provaQuery) => $provaQuery->where('competicao_id', $competitionId)))
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get()
            ->map(fn (Result $result) => $this->payload($result));

        return response()->json($results);
    }

    public function show(Result $competitionResult): JsonResponse
    {
        return response()->json($this->payload($competitionResult->load(['athlete', 'prova.competition', 'splits'])));
    }

    public function store(StoreResultRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $provaId = $validated['prova_id'] ?? null;

        if (! $provaId) {
            throw ValidationException::withMessages([
                'prova_id' => 'O resultado exige uma prova canónica já existente no programa da competição.',
            ]);
        }

        if (! CompetitionRegistration::query()->where('prova_id', $provaId)->where('user_id', $validated['user_id'])->exists()) {
            throw ValidationException::withMessages([
                'user_id' => 'O atleta não está inscrito nesta prova.',
            ]);
        }

        $result = Result::query()->updateOrCreate([
            'prova_id' => $provaId,
            'user_id' => $validated['user_id'],
        ], [
            'tempo_oficial' => $validated['tempo_oficial'],
            'posicao' => $validated['posicao'] ?? null,
            'pontos_fina' => $validated['pontos_fina'] ?? null,
            'status' => ($validated['desclassificado'] ?? false) ? 'dsq' : 'ok',
            'desclassificado' => $validated['desclassificado'] ?? false,
            'observacoes' => $validated['observacoes'] ?? null,
        ]);

        return response()->json($this->payload($result->load(['athlete', 'prova.competition', 'splits'])), 201);
    }

    public function update(Request $request, Result $competitionResult): JsonResponse
    {
        $validated = $request->validate([
            'tempo_oficial' => 'nullable|numeric|min:0',
            'posicao' => 'nullable|integer|min:1',
            'pontos_fina' => 'nullable|integer|min:0',
            'status' => 'nullable|in:ok,dsq,dns,dnf',
            'desclassificado' => 'nullable|boolean',
            'observacoes' => 'nullable|string',
        ]);

        if (isset($validated['status'])) {
            $validated['desclassificado'] = $validated['status'] === 'dsq';
        } elseif (array_key_exists('desclassificado', $validated)) {
            $validated['status'] = $validated['desclassificado'] ? 'dsq' : 'ok';
        }

        $competitionResult->update($validated);

        return response()->json($this->payload($competitionResult->fresh(['athlete', 'prova.competition', 'splits'])));
    }

    public function destroy(Result $competitionResult): JsonResponse
    {
        $competitionResult->delete();

        return response()->json(['message' => 'Resultado eliminado']);
    }

    private function payload(Result $result): array
    {
        return [
            'id' => $result->id,
            'prova_id' => $result->prova_id,
            'competition_id' => $result->prova?->competition?->id,
            'competition_nome' => $result->prova?->competition?->nome,
            'user_id' => $result->user_id,
            'user_nome' => $result->athlete?->nome_completo,
            'prova' => trim(($result->prova?->distancia_m ?? 0).'m '.($result->prova?->estilo ?? '')),
            'tempo' => $result->tempo_oficial,
            'tempo_oficial' => $result->tempo_oficial,
            'colocacao' => $result->posicao,
            'posicao' => $result->posicao,
            'pontos_fina' => $result->pontos_fina,
            'status' => $result->status ?: ($result->desclassificado ? 'dsq' : 'ok'),
            'desqualificado' => $result->desclassificado ?? false,
            'observacoes' => $result->observacoes,
            'splits' => $result->splits->map(fn ($split) => [
                'distance_m' => $split->distancia_parcial_m,
                'time' => $split->tempo_parcial,
            ])->values(),
            'created_at' => $result->created_at,
            'updated_at' => $result->updated_at,
        ];
    }
}

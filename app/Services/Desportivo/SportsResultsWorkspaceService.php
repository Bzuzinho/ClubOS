<?php

namespace App\Services\Desportivo;

use App\Models\Competition;
use App\Models\CompetitionRegistration;
use App\Models\Prova;
use App\Models\Result;
use App\Models\ResultSplit;
use App\Models\TeamResult;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SportsResultsWorkspaceService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberIdentityDisplayResolver $identity,
    ) {
    }

    public function workspace(): array
    {
        $competitions = Competition::query()
            ->forClub($this->clubContext->id())
            ->withCount(['provas', 'teamResults'])
            ->with(['provas.registrations', 'provas.results'])
            ->orderByDesc('data_inicio')
            ->limit(150)
            ->get();

        return [
            'competitions' => $competitions->map(function (Competition $competition): array {
                $registrations = $competition->provas->flatMap->registrations;
                $results = $competition->provas->flatMap->results;
                $expectedKeys = $registrations->map(fn (CompetitionRegistration $row): string => $row->prova_id.'|'.$row->user_id)->unique();
                $resultKeys = $results->map(fn (Result $row): string => $row->prova_id.'|'.$row->user_id)->unique();

                return [
                    'id' => (string) $competition->id,
                    'name' => (string) $competition->nome,
                    'starts_at' => optional($competition->data_inicio)->format('Y-m-d') ?: (string) $competition->data_inicio,
                    'location' => $competition->local,
                    'status' => (string) $competition->status,
                    'race_count' => (int) $competition->provas_count,
                    'expected_count' => $expectedKeys->count(),
                    'result_count' => $resultKeys->count(),
                    'pending_count' => $expectedKeys->diff($resultKeys)->count(),
                    'podium_count' => $results->whereIn('posicao', [1, 2, 3])->count(),
                    'dsq_count' => $results->where('status', 'dsq')->count() + $results->whereNull('status')->where('desclassificado', true)->count(),
                    'points_total' => (int) $results->sum('pontos_fina'),
                    'team_result_count' => (int) $competition->team_results_count,
                ];
            })->values()->all(),
            'status_options' => ['ok', 'dsq', 'dns', 'dnf'],
        ];
    }

    public function detail(Competition $competition): array
    {
        $competition = Competition::query()
            ->forClub($this->clubContext->id())
            ->with([
                'provas.registrations.athlete',
                'provas.results.athlete',
                'provas.results.splits',
                'teamResults',
            ])
            ->findOrFail($competition->id);

        $expected = collect();
        $allResults = collect();

        foreach ($competition->provas->sortBy('ordem_prova') as $race) {
            $resultByUser = $race->results->keyBy('user_id');
            foreach ($race->registrations as $registration) {
                $result = $resultByUser->get($registration->user_id);
                $expected->push($this->expectedRow($race, $registration, $result));
            }
            foreach ($race->results as $result) {
                $allResults->push($this->resultPayload($result));
            }
        }

        $expectedKeys = $expected->pluck('key');
        $extra = $allResults->reject(fn (array $row): bool => $expectedKeys->contains($row['prova_id'].'|'.$row['user_id']))->values();

        return [
            'competition' => [
                'id' => (string) $competition->id,
                'name' => (string) $competition->nome,
                'starts_at' => optional($competition->data_inicio)->format('Y-m-d') ?: (string) $competition->data_inicio,
                'location' => $competition->local,
                'status' => (string) $competition->status,
            ],
            'program' => $competition->provas->sortBy('ordem_prova')->map(fn (Prova $race): array => [
                'id' => (string) $race->id,
                'order' => $race->ordem_prova,
                'distance_m' => (int) $race->distancia_m,
                'stroke' => (string) $race->estilo,
                'gender' => $race->genero,
                'registrations' => $race->registrations->count(),
                'results' => $race->results->count(),
                'pending' => max(0, $race->registrations->count() - $race->results->unique('user_id')->count()),
            ])->values()->all(),
            'expected_rows' => $expected->values()->all(),
            'extra_results' => $extra->all(),
            'team_results' => $competition->teamResults->map(fn (TeamResult $row): array => [
                'id' => (string) $row->id,
                'team' => (string) $row->equipa,
                'position' => $row->classificacao,
                'points' => $row->pontos,
                'notes' => $row->observacoes,
            ])->values()->all(),
            'stats' => [
                'expected' => $expected->count(),
                'results' => $expected->whereNotNull('result')->count(),
                'pending' => $expected->whereNull('result')->count(),
                'podiums' => $allResults->whereIn('position', [1, 2, 3])->count(),
                'dsq' => $allResults->where('status', 'dsq')->count(),
                'points' => (int) $allResults->sum('points'),
            ],
        ];
    }

    public function saveBulk(Competition $competition, array $rows): array
    {
        $competition = Competition::query()->forClub($this->clubContext->id())->findOrFail($competition->id);

        return DB::transaction(function () use ($competition, $rows): array {
            $saved = [];

            foreach ($rows as $index => $row) {
                $race = Prova::query()
                    ->where('competicao_id', $competition->id)
                    ->find($row['prova_id'] ?? null);

                if (! $race) {
                    throw ValidationException::withMessages(["rows.$index.prova_id" => 'A prova não pertence à competição selecionada.']);
                }

                $userId = (string) ($row['user_id'] ?? '');
                $registered = CompetitionRegistration::query()
                    ->where('prova_id', $race->id)
                    ->where('user_id', $userId)
                    ->exists();

                if (! $registered) {
                    throw ValidationException::withMessages(["rows.$index.user_id" => 'O atleta não está inscrito nesta prova.']);
                }

                $status = strtolower((string) ($row['status'] ?? 'ok'));
                if (! in_array($status, ['ok', 'dsq', 'dns', 'dnf'], true)) {
                    throw ValidationException::withMessages(["rows.$index.status" => 'Estado competitivo inválido.']);
                }

                $time = $row['tempo_oficial'] ?? null;
                if ($status === 'ok' && ($time === null || $time === '')) {
                    throw ValidationException::withMessages(["rows.$index.tempo_oficial" => 'O tempo oficial é obrigatório para um resultado OK.']);
                }

                $result = Result::query()->updateOrCreate([
                    'prova_id' => $race->id,
                    'user_id' => $userId,
                ], [
                    'tempo_oficial' => ($time === '' || $time === null) ? null : (float) $time,
                    'posicao' => filled($row['posicao'] ?? null) ? (int) $row['posicao'] : null,
                    'pontos_fina' => filled($row['pontos_fina'] ?? null) ? (int) $row['pontos_fina'] : null,
                    'status' => $status,
                    'desclassificado' => $status === 'dsq',
                    'observacoes' => $row['observacoes'] ?? null,
                ]);

                if (array_key_exists('splits', $row)) {
                    ResultSplit::query()->where('resultado_id', $result->id)->delete();
                    foreach (collect($row['splits'] ?? [])->sortBy('distance_m') as $splitIndex => $split) {
                        $distance = (int) ($split['distance_m'] ?? 0);
                        $splitTime = $split['time'] ?? null;
                        if ($distance <= 0 || $distance > (int) $race->distancia_m || $splitTime === null || $splitTime === '') {
                            continue;
                        }
                        ResultSplit::query()->create([
                            'resultado_id' => $result->id,
                            'distancia_parcial_m' => $distance,
                            'tempo_parcial' => (float) $splitTime,
                        ]);
                    }
                }

                $saved[] = (string) $result->id;
            }

            return $saved;
        });
    }

    private function expectedRow(Prova $race, CompetitionRegistration $registration, ?Result $result): array
    {
        return [
            'key' => $race->id.'|'.$registration->user_id,
            'prova_id' => (string) $race->id,
            'race' => [
                'label' => trim($race->distancia_m.'m '.$race->estilo),
                'distance_m' => (int) $race->distancia_m,
                'stroke' => (string) $race->estilo,
                'gender' => $race->genero,
            ],
            'user_id' => (string) $registration->user_id,
            'athlete' => $registration->athlete ? $this->identity->displayName($registration->athlete) : 'Atleta indisponível',
            'registration_state' => $registration->estado,
            'result' => $result ? $this->resultPayload($result) : null,
        ];
    }

    private function resultPayload(Result $result): array
    {
        $status = (string) ($result->status ?: ($result->desclassificado ? 'dsq' : 'ok'));

        return [
            'id' => (string) $result->id,
            'prova_id' => (string) $result->prova_id,
            'user_id' => (string) $result->user_id,
            'athlete' => $result->athlete ? $this->identity->displayName($result->athlete) : 'Atleta indisponível',
            'official_time' => $result->tempo_oficial === null ? null : (float) $result->tempo_oficial,
            'position' => $result->posicao,
            'points' => $result->pontos_fina,
            'status' => $status,
            'notes' => $result->observacoes,
            'splits' => $result->splits->sortBy('distancia_parcial_m')->map(fn (ResultSplit $split): array => [
                'distance_m' => (int) $split->distancia_parcial_m,
                'time' => (float) $split->tempo_parcial,
            ])->values()->all(),
        ];
    }
}

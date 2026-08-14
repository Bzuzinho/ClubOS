<?php

declare(strict_types=1);

namespace App\Services\Desportivo;

use App\Models\Competition;
use App\Models\CompetitionFinancialObligation;
use App\Models\CompetitionRegistration;
use App\Models\ConvocationGroup;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class SportsCompetitionWorkspaceService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly MemberIdentityDisplayResolver $displayResolver,
    ) {
    }

    /** @return array<string,mixed> */
    public function workspace(Request $request): array
    {
        $query = Competition::query()
            ->forClub($this->clubContext->id())
            ->with(['eventProjection', 'financePolicy.costCenter'])
            ->withCount(['provas', 'results']);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search): void {
                $q->where('nome', 'like', '%'.$search.'%')
                    ->orWhere('local', 'like', '%'.$search.'%');
            });
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($type = $request->query('type')) {
            $query->where('tipo', $type);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('data_inicio', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('data_inicio', '<=', $to);
        }

        $competitions = $query->orderBy('data_inicio')->limit(150)->get();
        $ids = $competitions->pluck('id')->all();

        $registrationCounts = CompetitionRegistration::query()
            ->join('provas', 'provas.id', '=', 'competition_registrations.prova_id')
            ->whereIn('provas.competicao_id', $ids)
            ->selectRaw('provas.competicao_id as competition_id, count(*) as aggregate')
            ->groupBy('provas.competicao_id')
            ->pluck('aggregate', 'competition_id');

        $obligations = CompetitionFinancialObligation::query()
            ->where('club_id', $this->clubContext->id())
            ->whereIn('competition_id', $ids)
            ->get()
            ->groupBy('competition_id');

        $rows = $competitions->map(function (Competition $competition) use ($registrationCounts, $obligations): array {
            $competitionObligations = $obligations->get($competition->id, collect());
            $readiness = $this->readiness($competition, $competitionObligations);

            return [
                'id' => (string) $competition->id,
                'name' => $competition->nome,
                'location' => $competition->local,
                'starts_at' => optional($competition->data_inicio)->format('Y-m-d'),
                'ends_at' => optional($competition->data_fim)->format('Y-m-d'),
                'type' => $competition->tipo,
                'status' => $competition->status,
                'projection_status' => $competition->eventProjection?->status,
                'event_id' => $competition->eventProjection?->event_id,
                'race_count' => (int) ($competition->provas_count ?? 0),
                'result_count' => (int) ($competition->results_count ?? 0),
                'registration_count' => (int) ($registrationCounts[$competition->id] ?? 0),
                'financial_amount' => round((float) $competitionObligations->sum(fn ($row) => (float) ($row->manual_amount ?? $row->calculated_amount ?? 0)), 2),
                'readiness' => $readiness,
            ];
        })->values();

        return [
            'competitions' => $rows,
            'filters' => [
                'search' => $request->query('search'),
                'status' => $request->query('status'),
                'type' => $request->query('type'),
                'from' => $request->query('from'),
                'to' => $request->query('to'),
            ],
            'status_options' => ['scheduled', 'completed', 'cancelled', 'archived'],
            'type_options' => Competition::query()->forClub($this->clubContext->id())->whereNotNull('tipo')->distinct()->orderBy('tipo')->pluck('tipo')->values(),
        ];
    }

    /** @return array<string,mixed> */
    public function detail(Competition $competition): array
    {
        $competition = Competition::query()
            ->forClub($this->clubContext->id())
            ->with([
                'eventProjection.event',
                'financePolicy.costCenter',
                'provas.registrations.athlete',
                'provas.results',
                'teamResults',
            ])
            ->findOrFail($competition->id);

        $eventId = $competition->eventProjection?->event_id;
        $convocations = $eventId
            ? ConvocationGroup::query()->where('evento_id', $eventId)->with(['convocationAthletes.atleta'])->orderByDesc('created_at')->get()
            : collect();

        $registrationRows = $competition->provas
            ->flatMap(fn ($race) => $race->registrations->map(fn ($registration) => [$race, $registration]));

        $athletes = $registrationRows->pluck(1)->pluck('athlete')->filter()->unique('id')->values();
        $convocationAthletes = $convocations->flatMap->convocationAthletes->pluck('atleta')->filter()->unique('id')->values();
        $names = $this->displayResolver->mapDisplayNames($athletes->concat($convocationAthletes)->unique('id')->values());

        $obligations = CompetitionFinancialObligation::query()
            ->where('club_id', $this->clubContext->id())
            ->where('competition_id', $competition->id)
            ->with(['athlete', 'invoice'])
            ->get();

        return [
            'competition' => [
                'id' => (string) $competition->id,
                'name' => $competition->nome,
                'location' => $competition->local,
                'starts_at' => optional($competition->data_inicio)->format('Y-m-d'),
                'ends_at' => optional($competition->data_fim)->format('Y-m-d'),
                'type' => $competition->tipo,
                'status' => $competition->status,
                'cancellation_reason' => $competition->cancellation_reason,
            ],
            'projection' => [
                'event_id' => $eventId,
                'status' => $competition->eventProjection?->status,
                'manual_review_reason' => $competition->eventProjection?->manual_review_reason,
            ],
            'program' => $competition->provas->sortBy('ordem_prova')->values()->map(fn ($race) => [
                'id' => (string) $race->id,
                'order' => $race->ordem_prova,
                'stroke' => $race->estilo,
                'distance_m' => $race->distancia_m,
                'gender' => $race->genero,
                'age_group_id' => $race->escalao_id,
                'registrations' => $race->registrations->count(),
                'results' => $race->results->count(),
            ]),
            'convocations' => $convocations->map(fn (ConvocationGroup $group) => [
                'id' => (string) $group->id,
                'publication_status' => $group->publication_status,
                'published_at' => optional($group->published_at)->toIso8601String(),
                'meeting_time' => $group->hora_encontro,
                'meeting_place' => $group->local_encontro,
                'athletes' => $group->convocationAthletes->map(fn ($row) => [
                    'user_id' => (string) $row->atleta_id,
                    'name' => $names[$row->atleta_id] ?? 'Membro',
                    'confirmed' => (bool) $row->confirmado,
                    'present' => (bool) $row->presente,
                    'races' => $row->provas ?: [],
                    'relays' => (int) ($row->estafetas ?? 0),
                ])->values(),
            ])->values(),
            'registrations' => $registrationRows->map(function (array $pair) use ($names): array {
                [$race, $registration] = $pair;
                return [
                    'id' => (string) $registration->id,
                    'user_id' => (string) $registration->user_id,
                    'athlete' => $names[$registration->user_id] ?? 'Membro',
                    'race_id' => (string) $race->id,
                    'race' => trim(($race->distancia_m ?: '').' '.($race->estilo ?: '')),
                    'state' => $registration->estado,
                    'amount' => (float) ($registration->valor_inscricao ?? 0),
                ];
            })->values(),
            'finance' => [
                'policy' => $competition->financePolicy ? [
                    'payer_mode' => $competition->financePolicy->payer_mode,
                    'charge_mode' => $competition->financePolicy->charge_mode,
                    'fixed_amount' => $competition->financePolicy->fixed_amount,
                    'per_race_amount' => $competition->financePolicy->per_race_amount,
                    'cost_center' => $competition->financePolicy->costCenter?->nome,
                    'active' => (bool) $competition->financePolicy->active,
                ] : null,
                'obligations' => $obligations->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'user_id' => (string) $row->user_id,
                    'status' => $row->status,
                    'amount' => (float) ($row->manual_amount ?? $row->calculated_amount ?? 0),
                    'invoice_id' => $row->invoice_id,
                ])->values(),
            ],
            'results' => $competition->provas->flatMap(fn ($race) => $race->results->map(fn ($result) => [
                'id' => (string) $result->id,
                'race_id' => (string) $race->id,
                'race' => trim(($race->distancia_m ?: '').' '.($race->estilo ?: '')),
                'user_id' => (string) ($result->user_id ?? ''),
                'time_ms' => $result->tempo_ms ?? null,
                'classification' => $result->classificacao ?? null,
            ]))->values(),
            'team_results' => $competition->teamResults->values(),
            'readiness' => $this->readiness($competition, $obligations),
        ];
    }

    /** @param Collection<int,mixed> $obligations */
    private function readiness(Competition $competition, Collection $obligations): array
    {
        if (in_array($competition->status, ['completed', 'cancelled', 'archived'], true)) {
            return ['state' => 'closed', 'issues' => []];
        }

        $issues = [];
        if (! $competition->eventProjection || $competition->eventProjection->status !== 'linked') {
            $issues[] = 'Projeção para Eventos requer atenção';
        }
        $manualReview = $obligations->where('status', 'manual_review')->count();
        if ($manualReview > 0) {
            $issues[] = $manualReview.' obrigação(ões) financeira(s) em revisão';
        }

        return ['state' => $issues === [] ? 'ready' : 'attention', 'issues' => $issues];
    }
}

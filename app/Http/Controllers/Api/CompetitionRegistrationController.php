<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sports\StoreCompetitionRegistrationRequest;
use App\Models\CompetitionFinancialObligation;
use App\Models\CompetitionRegistration;
use App\Services\Desportivo\CreateCompetitionRegistrationAction;
use App\Services\Desportivo\DeleteCompetitionRegistrationAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class CompetitionRegistrationController extends Controller
{
    public function __construct(
        private CreateCompetitionRegistrationAction $createCompetitionRegistrationAction,
        private DeleteCompetitionRegistrationAction $deleteCompetitionRegistrationAction,
    ) {
    }

    public function index(): JsonResponse
    {
        $rows = CompetitionRegistration::with(['prova.competition', 'athlete'])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get();

        $this->attachFinancialObligations($rows);

        return response()->json($rows);
    }

    public function store(StoreCompetitionRegistrationRequest $request): JsonResponse
    {
        $registration = $this->createCompetitionRegistrationAction->execute($request->validated())
            ->loadMissing(['prova.competition', 'athlete']);

        $this->attachFinancialObligations(collect([$registration]));

        return response()->json($registration, 201);
    }

    public function destroy(CompetitionRegistration $competitionRegistration): JsonResponse
    {
        $this->deleteCompetitionRegistrationAction->execute($competitionRegistration);

        return response()->json(['message' => 'Inscricao removida']);
    }

    /** @param Collection<int,CompetitionRegistration> $registrations */
    private function attachFinancialObligations(Collection $registrations): void
    {
        $pairs = $registrations
            ->filter(fn (CompetitionRegistration $row): bool => $row->prova?->competition !== null)
            ->map(fn (CompetitionRegistration $row): array => [
                'competition_id' => (string) $row->prova->competition->id,
                'user_id' => (string) $row->user_id,
            ])
            ->unique(fn (array $row): string => $row['competition_id'].'|'.$row['user_id'])
            ->values();

        if ($pairs->isEmpty()) {
            return;
        }

        $competitionIds = $pairs->pluck('competition_id')->unique()->values();
        $userIds = $pairs->pluck('user_id')->unique()->values();

        $obligations = CompetitionFinancialObligation::query()
            ->with('invoice.items')
            ->whereIn('competition_id', $competitionIds->all())
            ->whereIn('user_id', $userIds->all())
            ->get()
            ->keyBy(fn (CompetitionFinancialObligation $row): string =>
                (string) $row->competition_id.'|'.(string) $row->user_id
            );

        $registrations->each(function (CompetitionRegistration $registration) use ($obligations): void {
            $competitionId = $registration->prova?->competition?->id;
            if (! $competitionId) {
                return;
            }

            $key = (string) $competitionId.'|'.(string) $registration->user_id;
            $registration->setRelation('financialObligation', $obligations->get($key));
        });
    }
}

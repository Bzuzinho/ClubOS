<?php

namespace App\Services\Desportivo\Queries;

use App\Models\Competition;
use App\Services\Desportivo\SportsClubContext;
use App\Support\LegacySportsGuard;

class GetCompetitionResultsView
{
    public function __construct(
        private LegacySportsGuard $legacySportsGuard,
        private SportsClubContext $clubContext,
    ) {
    }

    public function __invoke(string $competitionId): array
    {
        $query = Competition::query()
            ->forClub($this->clubContext->id())
            ->with([
                'eventProjection',
                'provas.registrations.athlete',
                'provas.results.athlete',
                'teamResults',
            ])
            ->whereKey($competitionId);

        $this->legacySportsGuard->assertNoForbiddenTablesInSql($query->toSql(), self::class);

        $competition = $query->firstOrFail();

        return [
            'competition' => $competition,
            'provas' => $competition->provas,
            'team_results' => $competition->teamResults,
        ];
    }
}

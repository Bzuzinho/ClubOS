<?php

namespace App\Services\Desportivo\Queries;

use App\Models\Competition;
use App\Services\Desportivo\SportsClubContext;
use App\Support\LegacySportsGuard;
use Illuminate\Support\Facades\DB;

class GetCompetitionListSummary
{
    public function __construct(
        private LegacySportsGuard $legacySportsGuard,
        private SportsClubContext $clubContext,
    ) {
    }

    public function __invoke(int $limit = 50)
    {
        $query = Competition::query()
            ->forClub($this->clubContext->id())
            ->select('competitions.*')
            ->with('eventProjection')
            ->withCount([
                'provas as total_provas',
                'results as total_resultados',
            ])
            ->selectSub(
                DB::table('competition_registrations')
                    ->join('provas', 'competition_registrations.prova_id', '=', 'provas.id')
                    ->whereColumn('provas.competicao_id', 'competitions.id')
                    ->selectRaw('COUNT(*)'),
                'total_inscritos'
            )
            ->orderByDesc('data_inicio');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $this->legacySportsGuard->assertNoForbiddenTablesInSql($query->toSql(), self::class);

        return $query->get();
    }
}

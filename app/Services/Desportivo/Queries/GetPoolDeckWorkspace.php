<?php

namespace App\Services\Desportivo\Queries;

use App\Models\Training;
use App\Services\Desportivo\SportsClubContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class GetPoolDeckWorkspace
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly GetTrainingPoolDeckView $trainingView,
    ) {
    }

    /** @return array<string,mixed> */
    public function __invoke(Request $request): array
    {
        $ids = collect($request->input('training_ids', []))
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->unique()
            ->take(12)
            ->values();

        $query = Training::query()
            ->where('club_id', $this->clubContext->id())
            ->orderBy('data')
            ->orderBy('hora_inicio');

        if ($ids->isNotEmpty()) {
            $query->whereIn('id', $ids->all());
        } else {
            $today = CarbonImmutable::today();
            $query->where(function ($query) use ($today): void {
                $query->where('pool_deck_status', 'open')
                    ->orWhereBetween('data', [
                        $today->subDay()->toDateString(),
                        $today->addDay()->toDateString(),
                    ]);
            });
        }

        $trainings = $query->limit(12)->get(['id']);

        return [
            'sessions' => $trainings
                ->map(fn (Training $training) => ($this->trainingView)((string) $training->id))
                ->values(),
            'generated_at' => now()->toIso8601String(),
        ];
    }
}

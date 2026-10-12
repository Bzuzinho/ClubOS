<?php

namespace App\Services\Desportivo;

use App\Models\Microcycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resolve a single session-level microcycle when target allocations agree.
 * Individual athlete allocations override group allocations. No existing
 * session or recurrence is updated by this resolver.
 */
final class SportsSessionCycleResolver
{
    public function __construct(private readonly SportsClubContext $context) {}

    public function resolve(string $seasonId, string $date, array $athleteIds, array $groupIds): ?string
    {
        $club = $this->context->id();
        $athleteIds = array_values(array_unique(array_filter($athleteIds)));
        $groupIds = array_values(array_unique(array_filter($groupIds)));
        if ($athleteIds === [] && $groupIds === []) return null;

        $candidates = Microcycle::query()
            ->where('microcycles.club_id', $club)
            ->where('microcycles.active', true)
            ->whereDate('microcycles.data_inicio', '<=', $date)
            ->whereDate('microcycles.data_fim', '>=', $date)
            ->whereHas('mesocycle.macrocycle', fn ($q) => $q
                ->where('epoca_id', $seasonId)->where('club_id', $club)->where('active', true))
            ->whereHas('mesocycle', fn ($q) => $q->where('active', true))
            ->with('mesocycle.macrocycle')
            ->get();

        $assignments = DB::table('sports_cycle_assignments')
            ->where('club_id', $club)->where('season_id', $seasonId)
            ->where(function ($q) use ($athleteIds, $groupIds) {
                $q->where(fn ($a) => $a->where('target_type', 'athlete')->whereIn('target_id', $athleteIds))
                    ->orWhere(fn ($g) => $g->where('target_type', 'group')->whereIn('target_id', $groupIds));
            })->get();

        $resolveTarget = function (string $type, string $id) use ($assignments, $candidates): ?string {
            $rows = $assignments->where('target_type', $type)->where('target_id', $id);
            foreach (['micro', 'meso', 'macro'] as $level) {
                $assigned = $rows->where('cycle_type', $level)->pluck('cycle_id')->map('strval')->all();
                if ($assigned === []) continue;
                $matching = $candidates->filter(fn ($micro) => in_array((string) match ($level) {
                    'micro' => $micro->id,
                    'meso' => $micro->mesociclo_id,
                    'macro' => $micro->mesocycle->macrociclo_id,
                }, $assigned, true))->pluck('id')->map('strval')->unique()->values()->all();
                if (count($matching) > 1) {
                    throw ValidationException::withMessages(['microciclo_id' => 'Existem vários microciclos aplicáveis ao mesmo destinatário nesta data. Selecione o microciclo manualmente.']);
                }
                if ($matching !== []) return $matching[0];
            }
            return null;
        };

        $individual = [];
        foreach ($athleteIds as $id) {
            $resolved = $resolveTarget('athlete', (string) $id);
            if ($resolved !== null) $individual[] = $resolved;
        }
        $group = [];
        foreach ($groupIds as $id) {
            $resolved = $resolveTarget('group', (string) $id);
            if ($resolved !== null) $group[] = $resolved;
        }

        // A session has only one microcycle reference; do not silently choose
        // a group cycle when an explicitly targeted athlete requires another.
        $values = array_values(array_unique($individual !== [] ? $individual : $group));
        if (count($values) > 1) {
            throw ValidationException::withMessages(['microciclo_id' => 'Os destinatários têm periodizações diferentes. Crie sessões separadas ou escolha explicitamente o microciclo.']);
        }
        if ($individual !== [] && $group !== [] && count(array_unique($group)) > 1) {
            throw ValidationException::withMessages(['microciclo_id' => 'Os grupos têm periodizações distintas. Confirme o microciclo da sessão.']);
        }
        return $values[0] ?? null;
    }
}

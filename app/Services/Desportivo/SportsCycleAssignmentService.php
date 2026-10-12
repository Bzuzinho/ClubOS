<?php

namespace App\Services\Desportivo;

use App\Models\Macrocycle;
use App\Models\Mesocycle;
use App\Models\Microcycle;
use App\Models\Season;
use App\Models\SportsAthleteParticipation;
use App\Models\TrainingGroupSeason;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SportsCycleAssignmentService
{
    public function __construct(private readonly SportsClubContext $context) {}

    public function replace(string $cycleType, string $cycleId, string $targetType, array $targetIds, string $actorId): void
    {
        $club = $this->context->id();
        $cycle = match ($cycleType) {
            'macro' => Macrocycle::query()->where('club_id', $club)->findOrFail($cycleId),
            'meso' => Mesocycle::query()->where('club_id', $club)->with('macrocycle')->findOrFail($cycleId),
            'micro' => Microcycle::query()->where('club_id', $club)->with('mesocycle.macrocycle')->findOrFail($cycleId),
            default => throw ValidationException::withMessages(['cycle_type' => 'Tipo de ciclo inválido.']),
        };
        $seasonId = match ($cycleType) {
            'macro' => $cycle->epoca_id,
            'meso' => $cycle->macrocycle->epoca_id,
            'micro' => $cycle->mesocycle->macrocycle->epoca_id,
        };
        $season = Season::query()->where('club_id', $club)->findOrFail($seasonId);
        if (in_array($season->status, ['closed', 'archived'], true)) {
            throw ValidationException::withMessages(['season_id' => 'A época está encerrada.']);
        }

        $ids = array_values(array_unique($targetIds));
        if ($targetType === 'group') {
            $valid = TrainingGroupSeason::query()->where('club_id', $club)->where('season_id', $seasonId)
                ->where('active', true)->whereIn('training_group_id', $ids)->pluck('training_group_id')->all();
        } elseif ($targetType === 'athlete') {
            $valid = SportsAthleteParticipation::query()->where('club_id', $club)
                ->where('sports_modality_id', $season->sports_modality_id)->active()
                ->whereIn('user_id', $ids)->pluck('user_id')->all();
        } else {
            throw ValidationException::withMessages(['target_type' => 'Âmbito inválido.']);
        }
        if (count(array_unique($valid)) !== count($ids)) {
            throw ValidationException::withMessages(['target_ids' => 'Um ou mais destinatários não pertencem à época/modalidade ativa.']);
        }

        DB::transaction(function () use ($club, $seasonId, $cycleType, $cycleId, $targetType, $ids, $actorId): void {
            DB::table('sports_cycle_assignments')->where('club_id', $club)
                ->where('cycle_type', $cycleType)->where('cycle_id', $cycleId)
                ->where('target_type', $targetType)->delete();
            foreach ($ids as $id) {
                DB::table('sports_cycle_assignments')->insert([
                    'id' => (string) Str::uuid(), 'club_id' => $club, 'season_id' => $seasonId,
                    'cycle_type' => $cycleType, 'cycle_id' => $cycleId,
                    'target_type' => $targetType, 'target_id' => $id,
                    'created_by' => $actorId, 'updated_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }
}

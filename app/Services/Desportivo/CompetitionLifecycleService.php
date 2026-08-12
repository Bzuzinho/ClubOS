<?php

namespace App\Services\Desportivo;

use App\Contracts\Financeiro\CompetitionFinanceGateway;
use App\Models\Competition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompetitionLifecycleService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
        private readonly CompetitionEventProjectionService $projectionService,
        private readonly CompetitionFinanceGateway $financeGateway,
    ) {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data, User $actor): Competition
    {
        return DB::transaction(function () use ($data, $actor): Competition {
            $competition = Competition::query()->create([
                ...$data,
                'club_id' => $this->clubContext->id(),
                'local' => $data['local'] ?? 'N/A',
                'status' => $data['status'] ?? 'scheduled',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->financeGateway->ensureDefaultPolicy((string) $competition->club_id, (string) $competition->id);
            $this->projectionService->sync($competition, $actor);

            return $competition->fresh(['eventProjection', 'evento', 'financePolicy']);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(Competition $competition, array $data, User $actor): Competition
    {
        return DB::transaction(function () use ($competition, $data, $actor): Competition {
            $lockedCompetition = $this->lockOwnedCompetition($competition);

            if ($lockedCompetition->status === 'archived' && ($data['status'] ?? 'archived') !== 'archived') {
                throw ValidationException::withMessages([
                    'status' => 'Uma competição arquivada não pode ser reativada por este fluxo.',
                ]);
            }

            if (($data['status'] ?? null) === 'cancelled' && ! $lockedCompetition->cancelled_at) {
                $data['cancelled_at'] = now();
            }
            if (($data['status'] ?? null) === 'archived' && ! $lockedCompetition->archived_at) {
                $data['archived_at'] = now();
            }

            $data['updated_by'] = $actor->id;
            if (array_key_exists('local', $data) && blank($data['local'])) {
                $data['local'] = 'N/A';
            }

            $lockedCompetition->fill($data);
            $lockedCompetition->save();

            $this->projectionService->sync($lockedCompetition, $actor);

            return $lockedCompetition->fresh(['eventProjection', 'evento', 'financePolicy']);
        });
    }

    public function archive(Competition $competition, User $actor): Competition
    {
        return $this->update($competition, [
            'status' => 'archived',
            'archived_at' => now(),
        ], $actor);
    }

    private function lockOwnedCompetition(Competition $competition): Competition
    {
        return Competition::query()
            ->forClub($this->clubContext->id())
            ->lockForUpdate()
            ->findOrFail($competition->id);
    }
}

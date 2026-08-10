<?php

namespace App\Services\Desportivo;

use App\Models\TrainingGroup;
use App\Models\TrainingGroupMembership;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingGroupMembershipService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    public function assign(
        TrainingGroup $group,
        User $athlete,
        bool $isPrimary,
        mixed $startsAt,
        mixed $endsAt = null,
        ?string $notes = null,
        ?User $actor = null,
    ): TrainingGroupMembership {
        $start = CarbonImmutable::parse($startsAt)->startOfDay();
        $end = $endsAt === null ? null : CarbonImmutable::parse($endsAt)->startOfDay();

        if ($end !== null && $end->lt($start)) {
            throw ValidationException::withMessages([
                'ends_at' => 'A data de fim não pode ser anterior à data de início.',
            ]);
        }

        $clubId = $this->clubContext->id();

        if ((string) $group->club_id !== $clubId) {
            throw ValidationException::withMessages([
                'training_group_id' => 'O grupo de treino não pertence ao clube ativo.',
            ]);
        }

        return DB::transaction(function () use ($group, $athlete, $isPrimary, $start, $end, $notes, $actor, $clubId): TrainingGroupMembership {
            if ($isPrimary) {
                $hasOverlap = TrainingGroupMembership::query()
                    ->where('club_id', $clubId)
                    ->where('user_id', $athlete->id)
                    ->where('is_primary', true)
                    ->whereDate('starts_at', '<=', ($end ?? CarbonImmutable::create(9999, 12, 31))->toDateString())
                    ->where(function ($query) use ($start): void {
                        $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $start->toDateString());
                    })
                    ->lockForUpdate()
                    ->exists();

                if ($hasOverlap) {
                    throw ValidationException::withMessages([
                        'is_primary' => 'O atleta já tem um grupo principal neste período.',
                    ]);
                }
            }

            return TrainingGroupMembership::query()->create([
                'club_id' => $clubId,
                'training_group_id' => $group->id,
                'user_id' => $athlete->id,
                'is_primary' => $isPrimary,
                'starts_at' => $start->toDateString(),
                'ends_at' => $end?->toDateString(),
                'notes' => $notes,
                'created_by' => $actor?->id,
            ]);
        });
    }

    public function close(
        TrainingGroupMembership $membership,
        mixed $endsAt,
    ): TrainingGroupMembership {
        $end = CarbonImmutable::parse($endsAt)->startOfDay();
        $start = CarbonImmutable::parse($membership->starts_at)->startOfDay();

        if ($end->lt($start)) {
            throw ValidationException::withMessages([
                'ends_at' => 'A data de fim não pode ser anterior à data de início.',
            ]);
        }

        $membership->forceFill(['ends_at' => $end->toDateString()])->save();

        return $membership->fresh();
    }
}

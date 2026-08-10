<?php

namespace App\Services\Desportivo;

use App\Models\Training;
use App\Models\TrainingScheduleException;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class TrainingScheduleExceptionService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /** @param array<string,mixed>|null $before
     *  @param array<string,mixed>|null $after
     */
    public function record(
        Training $training,
        string $type,
        ?array $before,
        ?array $after,
        string $reason,
        ?User $actor = null,
    ): TrainingScheduleException {
        if ((string) $training->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'training' => 'A sessão de treino pertence a outro clube.',
            ]);
        }

        if (!in_array($type, ['lane_change', 'group_change', 'venue_change', 'time_change'], true)) {
            throw ValidationException::withMessages([
                'exception_type' => 'Tipo de exceção de planeamento inválido.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'A justificação da exceção é obrigatória.',
            ]);
        }

        return TrainingScheduleException::query()->create([
            'club_id' => $this->clubContext->id(),
            'training_id' => $training->id,
            'exception_type' => $type,
            'before_state' => $before,
            'after_state' => $after,
            'reason' => $reason,
            'recorded_by' => $actor?->id,
            'recorded_at' => now(),
        ]);
    }
}

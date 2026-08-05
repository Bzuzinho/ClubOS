<?php

namespace App\Services\Eventos;

use App\Models\Event;
use App\Models\EventConvocation;
use App\Models\User;
use App\Services\Members\MemberTypeResolver;
use Illuminate\Validation\ValidationException;

class EventParticipantEligibilityService
{
    public function __construct(
        private readonly MemberTypeResolver $memberTypeResolver,
    ) {
    }

    public function assertEligible(Event $event, User $user): void
    {
        $this->assertActiveAthlete($user);

        if (EventConvocation::query()
            ->where('evento_id', $event->id)
            ->where('user_id', $user->id)
            ->exists()) {
            return;
        }

        $eligibleAgeGroupIds = $event->ageGroups()
            ->pluck('age_groups.id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($eligibleAgeGroupIds === []) {
            return;
        }

        $user->loadMissing('athleteSportsData:id,user_id,escalao_id');
        $memberAgeGroupIds = collect(is_array($user->escalao) ? $user->escalao : [$user->escalao])
            ->push($user->athleteSportsData?->escalao_id)
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($memberAgeGroupIds->intersect($eligibleAgeGroupIds)->isEmpty()) {
            throw ValidationException::withMessages([
                'user_id' => 'O atleta selecionado não pertence a um escalão elegível para este evento.',
            ]);
        }
    }

    public function assertActiveAthlete(User $user): void
    {
        $user->loadMissing('userTypes:id,codigo,nome');

        if ((string) $user->estado !== 'ativo' || ! $this->memberTypeResolver->isAthlete($user)) {
            throw ValidationException::withMessages([
                'user_id' => 'O membro selecionado não é um atleta ativo.',
            ]);
        }

        if ($user->ativo_desportivo === false) {
            throw ValidationException::withMessages([
                'user_id' => 'O atleta selecionado não tem atividade desportiva ativa.',
            ]);
        }
    }
}

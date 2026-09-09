<?php

declare(strict_types=1);

namespace App\Services\Eventos;

use App\Contracts\Desportivo\SportsAudienceProvider;
use App\Models\Event;
use App\Models\User;
use App\Services\Members\MemberTypeResolver;
use Illuminate\Support\Facades\DB;

final class EventAudienceResolver
{
    /** @var list<string>|null */
    private ?array $activeUserIds = null;

    /** @var list<string>|null */
    private ?array $activeAthleteIds = null;

    /** @var list<string>|null */
    private ?array $guardianIds = null;

    /** @var array<string, list<string>> */
    private array $ageGroupAthleteIds = [];

    public function __construct(
        private readonly SportsAudienceProvider $sportsAudienceProvider,
        private readonly MemberTypeResolver $memberTypeResolver,
    ) {
    }

    /**
     * Resolve os destinatários a partir da classificação guardada no próprio evento.
     * Este é o contrato partilhado pelo Portal e pela Comunicação.
     *
     * @return list<string>
     */
    public function recipientUserIds(Event $event): array
    {
        $audiences = $event->targetAudiences();

        if (in_array('todos', $audiences, true)) {
            return $this->activeUsers();
        }

        $recipientIds = collect();
        $targetedAthleteIds = [];
        $ageGroupIds = [];

        if (in_array('atletas', $audiences, true)) {
            $ageGroupIds = ($event->relationLoaded('ageGroups')
                ? $event->ageGroups->pluck('id')
                : $event->ageGroups()->pluck('age_groups.id'))
                ->map('strval')
                ->all();

            $targetedAthleteIds = $ageGroupIds === []
                ? $this->activeAthletes()
                : $this->athletesFromAgeGroups($ageGroupIds);
            $recipientIds = $recipientIds->merge($targetedAthleteIds);
        }

        if (in_array('encarregados_educacao', $audiences, true)) {
            $recipientIds = $recipientIds->merge(
                $ageGroupIds === []
                    ? $this->guardians()
                    : $this->guardiansForAthletes($targetedAthleteIds),
            );
        }

        if (in_array('outros_utilizadores', $audiences, true)) {
            $excludedIds = collect($this->activeAthletes())
                ->merge($this->guardians())
                ->unique();

            $recipientIds = $recipientIds->merge(
                collect($this->activeUsers())->diff($excludedIds),
            );
        }

        return $recipientIds
            ->intersect($this->activeUsers())
            ->map('strval')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function includes(Event $event, User $user): bool
    {
        return in_array((string) $user->id, $this->recipientUserIds($event), true);
    }

    /** @return list<string> */
    private function activeUsers(): array
    {
        return $this->activeUserIds ??= User::query()
            ->where(function ($query): void {
                $query->where('estado', 'ativo')->orWhereNull('estado');
            })
            ->orderBy('id')
            ->pluck('id')
            ->map('strval')
            ->all();
    }

    /** @return list<string> */
    private function activeAthletes(): array
    {
        return $this->activeAthleteIds ??= collect($this->sportsAudienceProvider->activeAthleteIds())
            ->map('strval')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $ageGroupIds
     * @return list<string>
     */
    private function athletesFromAgeGroups(array $ageGroupIds): array
    {
        sort($ageGroupIds);
        $cacheKey = implode('|', $ageGroupIds);

        return $this->ageGroupAthleteIds[$cacheKey] ??= collect(
            $this->sportsAudienceProvider->officialAgeGroupMemberIds($ageGroupIds),
        )
            ->map('strval')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function guardians(): array
    {
        if ($this->guardianIds !== null) {
            return $this->guardianIds;
        }

        $linkedGuardianIds = DB::table('user_guardian')
            ->distinct()
            ->pluck('guardian_id')
            ->map('strval');

        $typedGuardianIds = User::query()
            ->with('userTypes:id,codigo,nome')
            ->where(function ($query): void {
                $query->where('estado', 'ativo')->orWhereNull('estado');
            })
            ->get()
            ->filter(fn (User $user): bool => $this->memberTypeResolver->isGuardian($user))
            ->pluck('id')
            ->map('strval');

        return $this->guardianIds = $linkedGuardianIds
            ->merge($typedGuardianIds)
            ->intersect($this->activeUsers())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param list<string> $athleteIds
     * @return list<string>
     */
    private function guardiansForAthletes(array $athleteIds): array
    {
        if ($athleteIds === []) {
            return [];
        }

        return DB::table('user_guardian')
            ->whereIn('user_id', $athleteIds)
            ->distinct()
            ->pluck('guardian_id')
            ->map('strval')
            ->intersect($this->activeUsers())
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}

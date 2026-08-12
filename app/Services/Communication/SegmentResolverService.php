<?php

namespace App\Services\Communication;

use App\Contracts\Desportivo\SportsAudienceProvider;
use App\Models\AgeGroup;
use App\Models\CommunicationDynamicSource;
use App\Models\CommunicationSegment;
use App\Models\EventAttendance;
use App\Models\InAppAlert;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SegmentResolverService
{
    public function __construct(
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
        private readonly MemberDataReadService $memberDataReadService,
        private readonly SportsAudienceProvider $sportsAudienceProvider,
    ) {
    }

    public function resolveRecipients(CommunicationSegment $segment, ?string $channel = null): Collection
    {
        $users = $this->resolveUsers($segment);
        $this->loadCommunicationPersonalData($users);

        $recipients = $users
            ->unique('id')
            ->values()
            ->map(function (User $user) {
                $personal = $this->memberDataReadService->personalPayload($user);
                $contact = $this->normalizedCommunicationContact($personal['contacto'] ?? null);

                return [
                    'user_id' => $user->id,
                    'member_id' => $user->id,
                    'name' => $this->memberIdentityDisplayResolver->displayName($user),
                    'email' => $user->email,
                    'phone' => $contact,
                    'push_token' => null,
                ];
            });

        if ($channel === null) {
            return $recipients;
        }

        return $recipients->filter(function (array $recipient) use ($channel) {
            return match ($channel) {
                'email' => !empty($recipient['email']),
                'sms' => !empty($recipient['phone']),
                'push' => !empty($recipient['push_token']),
                'interno', 'alert_app' => !empty($recipient['user_id']),
                default => true,
            };
        })->values();
    }

    private function resolveUsers(CommunicationSegment $segment): Collection
    {
        $rules = $segment->rules_json ?? [];

        if ($segment->type === 'manual') {
            return $this->resolveManualUsers($rules);
        }

        $source = $this->resolveSourceStrategy($rules);

        return match ($source) {
            'athletes' => $this->usersByIds($this->sportsAudienceProvider->activeAthleteIds()),
            // Guardian is a Membros/person role rather than a Sports participation.
            'guardians' => User::whereJsonContains('tipo_membro', 'encarregado_educacao')->get(),
            'coaches' => $this->usersByIds($this->sportsAudienceProvider->activeCoachIds()),
            'team_members', 'training_group_members' => $this->usersFromTrainingGroup($rules),
            'age_group_members' => $this->usersFromAgeGroups($rules),
            'overdue_payments' => $this->usersWithOverduePayments(),
            'event_participants' => $this->usersFromEvent($rules),
            'users_with_unread_alerts' => $this->usersWithUnreadAlerts(),
            default => User::query()->where('estado', 'ativo')->orWhereNull('estado')->get(),
        };
    }

    private function resolveManualUsers(array $rules): Collection
    {
        $userIds = collect($rules['user_ids'] ?? [])->filter()->map('strval')->unique()->values();
        $ageGroupIds = collect($rules['age_group_ids'] ?? [])->filter()->map('strval')->unique()->values();
        $userTypes = collect($rules['user_types'] ?? [])->filter()->map('strval')->unique()->values();

        if ($userIds->isEmpty() && $ageGroupIds->isEmpty() && $userTypes->isEmpty()) {
            return collect();
        }

        $query = User::query()->where(function ($builder) {
            $builder->where('estado', 'ativo')->orWhereNull('estado');
        });

        if ($userIds->isNotEmpty()) {
            $query->whereIn('id', $userIds->all());
        }

        if ($ageGroupIds->isNotEmpty()) {
            $canonicalAgeGroupUserIds = $this->sportsAudienceProvider->officialAgeGroupMemberIds(
                $ageGroupIds->all(),
                $this->nullableString($rules['season_id'] ?? null),
            );
            $query->whereIn('id', $canonicalAgeGroupUserIds === [] ? ['__none__'] : $canonicalAgeGroupUserIds);
        }

        if ($userTypes->isNotEmpty()) {
            $sportsUserIds = collect();
            $memberRoleTypes = collect();

            foreach ($userTypes as $userType) {
                $normalized = mb_strtolower(trim((string) $userType));

                if (in_array($normalized, ['atleta', 'athlete'], true)) {
                    $sportsUserIds = $sportsUserIds->merge($this->sportsAudienceProvider->activeAthleteIds());
                    continue;
                }

                if (in_array($normalized, ['treinador', 'coach'], true)) {
                    $sportsUserIds = $sportsUserIds->merge($this->sportsAudienceProvider->activeCoachIds());
                    continue;
                }

                $memberRoleTypes->push((string) $userType);
            }

            $sportsUserIds = $sportsUserIds->filter()->unique()->values();
            $query->where(function ($builder) use ($sportsUserIds, $memberRoleTypes): void {
                $hasCondition = false;

                if ($sportsUserIds->isNotEmpty()) {
                    $builder->whereIn('id', $sportsUserIds->all());
                    $hasCondition = true;
                }

                foreach ($memberRoleTypes as $memberRoleType) {
                    if ($hasCondition) {
                        $builder->orWhereJsonContains('tipo_membro', $memberRoleType);
                    } else {
                        $builder->whereJsonContains('tipo_membro', $memberRoleType);
                        $hasCondition = true;
                    }
                }

                if (! $hasCondition) {
                    $builder->whereRaw('1 = 0');
                }
            });
        }

        return $query->get();
    }

    public function estimateRecipients(CommunicationSegment $segment): int
    {
        return $this->resolveRecipients($segment)->count();
    }

    public function resolveAgeGroupLabels(array $ageGroupIds): array
    {
        if ($ageGroupIds === []) {
            return [];
        }

        return AgeGroup::query()
            ->whereIn('id', $ageGroupIds)
            ->orderBy('nome')
            ->pluck('nome')
            ->all();
    }

    private function resolveSourceStrategy(array $rules): string
    {
        $fallbackSource = $rules['source'] ?? 'all_members';
        $sourceId = $rules['source_id'] ?? null;

        if (!$sourceId) {
            return $fallbackSource;
        }

        if (!Schema::hasTable('communication_dynamic_sources')) {
            return $fallbackSource;
        }

        return CommunicationDynamicSource::query()
            ->whereKey($sourceId)
            ->value('strategy') ?? $fallbackSource;
    }

    private function usersFromTrainingGroup(array $rules): Collection
    {
        $trainingGroupId = $this->nullableString($rules['training_group_id'] ?? null);

        if ($trainingGroupId === null) {
            if (filled($rules['team_id'] ?? null)) {
                Log::warning('Legacy communication team audience requires manual migration to training_group_id.', [
                    'team_id' => $rules['team_id'],
                ]);
            }

            return collect();
        }

        return $this->usersByIds($this->sportsAudienceProvider->trainingGroupMemberIds($trainingGroupId));
    }

    private function usersFromAgeGroups(array $rules): Collection
    {
        $ageGroupIds = collect($rules['age_group_ids'] ?? [])
            ->when(empty($rules['age_group_ids'] ?? []), fn ($collection) => $collection->push($rules['age_group_id'] ?? null))
            ->filter()
            ->map('strval')
            ->unique()
            ->values();

        if ($ageGroupIds->isEmpty()) {
            return collect();
        }

        return $this->usersByIds($this->sportsAudienceProvider->officialAgeGroupMemberIds(
            $ageGroupIds->all(),
            $this->nullableString($rules['season_id'] ?? null),
        ));
    }

    private function usersWithOverduePayments(): Collection
    {
        $userIds = Invoice::where('estado_pagamento', '!=', 'pago')
            ->whereDate('data_vencimento', '<', now())
            ->distinct()
            ->pluck('user_id');

        return User::whereIn('id', $userIds)->get();
    }

    private function usersFromEvent(array $rules): Collection
    {
        $eventId = $rules['event_id'] ?? null;

        if (!$eventId) {
            return collect();
        }

        // Event attendance remains valid for non-training Eventos. Training
        // attendance is never resolved from this source after the Sports cutover.
        $userIds = EventAttendance::where('evento_id', $eventId)->distinct()->pluck('user_id');

        return User::whereIn('id', $userIds)->get();
    }

    private function usersWithUnreadAlerts(): Collection
    {
        $userIds = InAppAlert::where('is_read', false)
            ->distinct()
            ->pluck('user_id');

        return User::whereIn('id', $userIds)->get();
    }

    /** @param list<string> $userIds */
    private function usersByIds(array $userIds): Collection
    {
        if ($userIds === []) {
            return collect();
        }

        return User::query()->whereIn('id', $userIds)->get();
    }

    private function loadCommunicationPersonalData(Collection $users): void
    {
        if ($users instanceof EloquentCollection) {
            $users->loadMissing(['dadosPessoais:user_id,nome_completo,contacto']);
        }
    }

    private function normalizedCommunicationContact(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

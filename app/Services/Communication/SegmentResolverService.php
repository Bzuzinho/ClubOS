<?php

namespace App\Services\Communication;

use App\Models\AgeGroup;
use App\Models\CommunicationDynamicSource;
use App\Models\CommunicationSegment;
use App\Models\EventAttendance;
use App\Models\InAppAlert;
use App\Models\Invoice;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Members\MemberDataReadService;
use App\Services\Members\MemberIdentityDisplayResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class SegmentResolverService
{
    private ?bool $usersHaveAgeGroupColumn = null;

    public function __construct(
        private readonly MemberIdentityDisplayResolver $memberIdentityDisplayResolver,
        private readonly MemberDataReadService $memberDataReadService,
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
            'athletes' => User::whereJsonContains('tipo_membro', 'atleta')->get(),
            'guardians' => User::whereJsonContains('tipo_membro', 'encarregado_educacao')->get(),
            'coaches' => User::whereJsonContains('tipo_membro', 'treinador')->get(),
            'team_members' => $this->usersFromTeam($rules),
            'age_group_members' => $this->usersFromAgeGroups($rules),
            'overdue_payments' => $this->usersWithOverduePayments(),
            'event_participants' => $this->usersFromEvent($rules),
            'users_with_unread_alerts' => $this->usersWithUnreadAlerts(),
            default => User::query()->where('estado', 'ativo')->orWhereNull('estado')->get(),
        };
    }

    private function resolveManualUsers(array $rules): Collection
    {
        $userIds = collect($rules['user_ids'] ?? [])->filter()->values();
        $ageGroupIds = collect($rules['age_group_ids'] ?? [])->filter()->values();
        $userTypes = collect($rules['user_types'] ?? [])->filter()->values();

        if ($userIds->isEmpty() && $ageGroupIds->isEmpty() && $userTypes->isEmpty()) {
            return collect();
        }

        $query = User::query()->where(function ($builder) {
            $builder->where('estado', 'ativo')->orWhereNull('estado');
        });

        if ($userIds->isNotEmpty()) {
            $query->whereIn('id', $userIds);
        }

        if ($ageGroupIds->isNotEmpty()) {
            $query->where(function (Builder $builder) use ($ageGroupIds) {
                $this->applyAgeGroupFilter($builder, $ageGroupIds->all());
            });
        }

        if ($userTypes->isNotEmpty()) {
            $query->where(function ($builder) use ($userTypes) {
                foreach ($userTypes as $userType) {
                    $builder->orWhereJsonContains('tipo_membro', $userType);
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

    private function usersFromTeam(array $rules): Collection
    {
        $teamId = $rules['team_id'] ?? null;

        if (!$teamId) {
            return collect();
        }

        $userIds = TeamMember::where('team_id', $teamId)->pluck('user_id');

        return User::whereIn('id', $userIds)->get();
    }

    private function usersFromAgeGroups(array $rules): Collection
    {
        $ageGroupIds = collect($rules['age_group_ids'] ?? [])
            ->when(empty($rules['age_group_ids'] ?? []), fn ($collection) => $collection->push($rules['age_group_id'] ?? null))
            ->filter()
            ->values();

        if ($ageGroupIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->where(function ($query) use ($ageGroupIds) {
                $this->applyAgeGroupFilter($query, $ageGroupIds->all());
            })
            ->get();
    }

    private function applyAgeGroupFilter(Builder $query, array $ageGroupIds): void
    {
        $hasStructuredCondition = false;

        if ($this->usersHaveAgeGroupColumn()) {
            $query->whereIn('age_group_id', $ageGroupIds);
            $hasStructuredCondition = true;
        }

        foreach ($ageGroupIds as $ageGroupId) {
            if ($hasStructuredCondition) {
                $query->orWhereJsonContains('escalao', $ageGroupId);
                continue;
            }

            $query->whereJsonContains('escalao', $ageGroupId);
            $hasStructuredCondition = true;
        }
    }

    private function usersHaveAgeGroupColumn(): bool
    {
        return $this->usersHaveAgeGroupColumn ??= Schema::hasColumn('users', 'age_group_id');
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
}

<?php

declare(strict_types=1);

namespace App\Services\SportsFoundation;

use App\Models\ConvocationGroup;
use App\Models\SportsLegacyCutoverLedger;
use App\Models\Training;
use App\Models\TrainingGroup;
use App\Models\TrainingGroupMembership;
use App\Services\Desportivo\SportsClubContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SportsLegacyCutoverLedgerService
{
    public function __construct(private readonly SportsClubContext $clubContext)
    {
    }

    /** @return array<string,int> */
    public function refresh(): array
    {
        if (! Schema::hasTable('sports_legacy_cutover_ledger')) {
            return [];
        }

        $this->auditTeams();
        $this->auditTeamMembers();
        $this->auditTrainingSessions();
        $this->auditCallUps();
        $this->auditCommunicationTeamSegments();

        return SportsLegacyCutoverLedger::query()
            ->where('club_id', $this->clubContext->id())
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    private function auditTeams(): void
    {
        if (! Schema::hasTable('teams')) {
            return;
        }

        foreach (DB::table('teams')->orderBy('id')->get() as $row) {
            $sourceId = (string) $row->id;
            $target = TrainingGroup::query()
                ->where('club_id', $this->clubContext->id())
                ->whereKey($sourceId)
                ->first();

            $this->record(
                sourceType: 'team',
                sourceId: $sourceId,
                targetType: $target ? 'training_group' : null,
                targetId: $target?->id,
                status: $target ? 'already_canonical' : 'manual_review',
                reason: $target ? 'same_identifier_verified' : 'legacy_team_requires_explicit_group_mapping',
                snapshot: (array) $row,
            );
        }
    }

    private function auditTeamMembers(): void
    {
        if (! Schema::hasTable('team_members')) {
            return;
        }

        foreach (DB::table('team_members')->orderBy('id')->get() as $row) {
            $sourceId = (string) $row->id;
            $teamLedger = SportsLegacyCutoverLedger::query()
                ->where('club_id', $this->clubContext->id())
                ->where('source_type', 'team')
                ->where('source_id', (string) $row->team_id)
                ->first();
            $groupId = $teamLedger?->target_type === 'training_group' ? $teamLedger->target_id : null;

            $target = $groupId
                ? TrainingGroupMembership::query()
                    ->where('club_id', $this->clubContext->id())
                    ->where('training_group_id', $groupId)
                    ->where('user_id', (string) $row->user_id)
                    ->when(
                        filled($row->join_date ?? null),
                        fn ($query) => $query->whereDate('starts_at', (string) $row->join_date)
                    )
                    ->get()
                : collect();

            $matched = $target->count() === 1 ? $target->first() : null;
            $reason = match (true) {
                ! $groupId => 'parent_team_not_mapped',
                $target->count() > 1 => 'multiple_canonical_memberships_match',
                $matched !== null => 'exact_group_user_date_membership_verified',
                default => 'membership_requires_season_context_or_explicit_mapping',
            };

            $this->record(
                sourceType: 'team_member',
                sourceId: $sourceId,
                targetType: $matched ? 'training_group_membership' : null,
                targetId: $matched?->id,
                status: $matched ? 'already_canonical' : 'manual_review',
                reason: $reason,
                snapshot: (array) $row,
            );
        }
    }

    private function auditTrainingSessions(): void
    {
        if (! Schema::hasTable('training_sessions')) {
            return;
        }

        foreach (DB::table('training_sessions')->orderBy('id')->get() as $row) {
            $sourceId = (string) $row->id;
            $target = Training::query()
                ->where('club_id', $this->clubContext->id())
                ->whereKey($sourceId)
                ->first();

            $this->record(
                sourceType: 'training_session',
                sourceId: $sourceId,
                targetType: $target ? 'training' : null,
                targetId: $target?->id,
                status: $target ? 'already_canonical' : 'manual_review',
                reason: $target ? 'same_identifier_verified' : 'legacy_training_session_requires_explicit_training_mapping',
                snapshot: (array) $row,
            );
        }
    }

    private function auditCallUps(): void
    {
        if (! Schema::hasTable('call_ups')) {
            return;
        }

        foreach (DB::table('call_ups')->orderBy('id')->get() as $row) {
            $sourceId = (string) $row->id;
            $sameId = ConvocationGroup::query()->whereKey($sourceId)->first();

            if ($sameId) {
                $this->record(
                    sourceType: 'call_up',
                    sourceId: $sourceId,
                    targetType: 'convocation_group',
                    targetId: $sameId->id,
                    status: 'already_canonical',
                    reason: 'same_identifier_verified',
                    snapshot: (array) $row,
                );
                continue;
            }

            $eventId = filled($row->event_id ?? null) ? (string) $row->event_id : null;
            $athletes = $this->normalizedIdList($row->called_up_athletes ?? null);
            $matches = $eventId === null
                ? collect()
                : ConvocationGroup::query()
                    ->where('evento_id', $eventId)
                    ->get()
                    ->filter(fn (ConvocationGroup $group): bool =>
                        $this->normalizedIdList($group->atletas_ids) === $athletes
                    )
                    ->values();

            $matched = $matches->count() === 1 ? $matches->first() : null;
            $reason = match (true) {
                $eventId === null => 'legacy_call_up_has_no_event',
                $matches->count() > 1 => 'multiple_convocation_groups_match_exact_payload',
                $matched !== null => 'exact_event_and_athlete_set_verified',
                default => 'legacy_call_up_requires_explicit_convocation_mapping',
            };

            $this->record(
                sourceType: 'call_up',
                sourceId: $sourceId,
                targetType: $matched ? 'convocation_group' : null,
                targetId: $matched?->id,
                status: $matched ? 'already_canonical' : 'manual_review',
                reason: $reason,
                snapshot: (array) $row,
            );
        }
    }

    private function auditCommunicationTeamSegments(): void
    {
        if (! Schema::hasTable('communication_segments')) {
            return;
        }

        foreach (DB::table('communication_segments')->orderBy('id')->get() as $row) {
            $rules = $this->decodedArray($row->rules_json ?? null);
            $source = (string) ($rules['source'] ?? '');

            if (! in_array($source, ['team_members', 'training_group_members'], true)) {
                continue;
            }

            $trainingGroupId = is_string($rules['training_group_id'] ?? null)
                ? trim((string) $rules['training_group_id'])
                : '';
            $target = $trainingGroupId !== ''
                ? TrainingGroup::query()
                    ->where('club_id', $this->clubContext->id())
                    ->whereKey($trainingGroupId)
                    ->first()
                : null;

            $this->record(
                sourceType: 'communication_segment_team',
                sourceId: (string) $row->id,
                targetType: $target ? 'training_group' : null,
                targetId: $target?->id,
                status: $target ? 'already_canonical' : 'manual_review',
                reason: $target
                    ? 'training_group_id_verified'
                    : (filled($rules['team_id'] ?? null)
                        ? 'legacy_team_id_requires_explicit_training_group_mapping'
                        : 'training_group_id_missing'),
                snapshot: [
                    'segment_id' => (string) $row->id,
                    'rules_json' => $rules,
                ],
            );
        }
    }

    /** @param array<string,mixed> $snapshot */
    private function record(
        string $sourceType,
        string $sourceId,
        ?string $targetType,
        ?string $targetId,
        string $status,
        string $reason,
        array $snapshot,
    ): void {
        $existing = SportsLegacyCutoverLedger::query()
            ->where('club_id', $this->clubContext->id())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($existing && in_array($existing->status, ['resolved', 'ignored'], true)) {
            $existing->forceFill(['audited_at' => now()])->save();
            return;
        }

        SportsLegacyCutoverLedger::query()->updateOrCreate(
            [
                'club_id' => $this->clubContext->id(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
            ],
            [
                'target_type' => $targetType,
                'target_id' => $targetId,
                'status' => $status,
                'reason' => $reason,
                'source_snapshot' => $snapshot,
                'audited_at' => now(),
                'migrated_at' => $status === 'already_canonical' ? now() : null,
            ]
        );
    }

    /** @return list<string> */
    private function normalizedIdList(mixed $value): array
    {
        $values = is_array($value) ? $value : $this->decodedArray($value);

        return collect($values)
            ->filter(fn ($id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (string $id): string => trim($id))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<string|int,mixed> */
    private function decodedArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

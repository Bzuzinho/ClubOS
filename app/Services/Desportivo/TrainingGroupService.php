<?php

namespace App\Services\Desportivo;

use App\Models\AgeGroup;
use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TrainingGroupService
{
    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, ?User $actor = null): TrainingGroup
    {
        $clubId = $this->clubContext->id();
        $name = trim((string) ($data['name'] ?? ''));
        $code = $this->normalizeNullableString($data['code'] ?? null);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'O nome do grupo de treino é obrigatório.']);
        }

        $this->ensureCodeAvailable($clubId, $code);
        $ageGroupIds = $this->validatedAgeGroupIds(Arr::wrap($data['age_group_ids'] ?? []));

        return DB::transaction(function () use ($data, $actor, $clubId, $name, $code, $ageGroupIds): TrainingGroup {
            $group = TrainingGroup::query()->create([
                'club_id' => $clubId,
                'code' => $code,
                'name' => $name,
                'description' => $data['description'] ?? null,
                'modality' => $data['modality'] ?? 'swimming',
                'active' => $data['active'] ?? true,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->syncAgeGroups($group, $ageGroupIds);

            return $group->fresh(['ageGroups']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(TrainingGroup $group, array $data, ?User $actor = null): TrainingGroup
    {
        $clubId = $this->clubContext->id();
        if ((string) $group->club_id !== $clubId) {
            throw ValidationException::withMessages([
                'training_group_id' => 'O grupo de treino não pertence ao clube ativo.',
            ]);
        }

        $name = trim((string) ($data['name'] ?? $group->name));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'O nome do grupo de treino é obrigatório.']);
        }

        $code = array_key_exists('code', $data)
            ? $this->normalizeNullableString($data['code'])
            : $group->code;
        $this->ensureCodeAvailable($clubId, $code, (string) $group->id);

        $ageGroupIds = array_key_exists('age_group_ids', $data)
            ? $this->validatedAgeGroupIds(Arr::wrap($data['age_group_ids']))
            : null;

        return DB::transaction(function () use ($group, $data, $actor, $name, $code, $ageGroupIds): TrainingGroup {
            $group->fill([
                'code' => $code,
                'name' => $name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $group->description,
                'modality' => $data['modality'] ?? $group->modality,
                'active' => array_key_exists('active', $data) ? (bool) $data['active'] : $group->active,
                'updated_by' => $actor?->id,
            ])->save();

            if ($ageGroupIds !== null) {
                $this->syncAgeGroups($group, $ageGroupIds);
            }

            return $group->fresh(['ageGroups']);
        });
    }

    /** @param list<string> $ageGroupIds */
    private function syncAgeGroups(TrainingGroup $group, array $ageGroupIds): void
    {
        $pivot = collect($ageGroupIds)
            ->mapWithKeys(fn (string $id): array => [$id => ['club_id' => $group->club_id]])
            ->all();

        $group->ageGroups()->sync($pivot);
    }

    private function ensureCodeAvailable(string $clubId, ?string $code, ?string $ignoreId = null): void
    {
        if ($code === null) {
            return;
        }

        $query = TrainingGroup::query()->where('club_id', $clubId)->where('code', $code);
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'code' => 'Já existe um grupo de treino com este código neste clube.',
            ]);
        }
    }

    /** @param list<mixed> $ids
     *  @return list<string>
     */
    private function validatedAgeGroupIds(array $ids): array
    {
        $normalized = collect($ids)
            ->map(static fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($normalized->isEmpty()) {
            return [];
        }

        $existing = AgeGroup::query()->whereKey($normalized->all())->pluck('id')->map(fn ($id) => (string) $id);

        if ($existing->count() !== $normalized->count()) {
            throw ValidationException::withMessages([
                'age_group_ids' => 'Existe pelo menos um escalão inválido.',
            ]);
        }

        return $normalized->all();
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}

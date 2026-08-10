<?php

namespace App\Services\Desportivo;

use App\Models\AgeGroup;
use App\Models\Competition;
use App\Models\Prova;
use App\Models\Season;
use App\Models\SportsObjective;
use App\Models\SportsObjectiveVersion;
use App\Models\TrainingGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SportsObjectiveService
{
    private const TARGET_MODELS = [
        'season' => Season::class,
        'age_group' => AgeGroup::class,
        'training_group' => TrainingGroup::class,
        'athlete' => User::class,
        'competition' => Competition::class,
        'prova' => Prova::class,
    ];

    private const TARGET_TYPES = [
        'club',
        'modality',
        'season',
        'age_group',
        'training_group',
        'athlete',
        'competition',
        'prova',
    ];

    public function __construct(
        private readonly SportsClubContext $clubContext,
    ) {
    }

    /**
     * @param array<string, mixed> $objectiveData
     * @param array<string, mixed> $versionData
     */
    public function create(array $objectiveData, array $versionData, ?User $actor = null): SportsObjective
    {
        $targetType = (string) ($objectiveData['target_type'] ?? '');
        $targetId = $objectiveData['target_id'] ?? null;
        if (in_array($targetType, ['club', 'modality'], true)) {
            $targetId = null;
        }
        $this->validateTarget($targetType, $targetId, $objectiveData['modality'] ?? null);
        $this->validateVersionData($versionData);

        $clubId = $this->clubContext->id();

        return DB::transaction(function () use ($objectiveData, $versionData, $actor, $clubId, $targetType, $targetId): SportsObjective {
            $objective = SportsObjective::query()->create([
                'club_id' => $clubId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'modality' => $objectiveData['modality'] ?? null,
                'status' => $objectiveData['status'] ?? 'active',
                'current_version' => 1,
                'starts_at' => $objectiveData['starts_at'] ?? null,
                'due_at' => $objectiveData['due_at'] ?? null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $this->createVersion($objective, 1, $versionData, $actor);

            return $objective->fresh(['versions', 'latestVersion']);
        });
    }

    /**
     * @param array<string, mixed> $versionData
     */
    public function revise(SportsObjective $objective, array $versionData, ?User $actor = null): SportsObjective
    {
        if ((string) $objective->club_id !== $this->clubContext->id()) {
            throw ValidationException::withMessages([
                'sports_objective_id' => 'O objetivo não pertence ao clube ativo.',
            ]);
        }

        $this->validateVersionData($versionData);

        return DB::transaction(function () use ($objective, $versionData, $actor): SportsObjective {
            /** @var SportsObjective $locked */
            $locked = SportsObjective::query()->lockForUpdate()->findOrFail($objective->id);
            $nextVersion = ((int) $locked->current_version) + 1;

            $this->createVersion($locked, $nextVersion, $versionData, $actor);

            $locked->forceFill([
                'current_version' => $nextVersion,
                'updated_by' => $actor?->id,
            ])->save();

            return $locked->fresh(['versions', 'latestVersion']);
        });
    }

    /**
     * @param array<string, mixed> $versionData
     */
    private function createVersion(
        SportsObjective $objective,
        int $version,
        array $versionData,
        ?User $actor,
    ): SportsObjectiveVersion {
        return SportsObjectiveVersion::query()->create([
            'club_id' => $objective->club_id,
            'sports_objective_id' => $objective->id,
            'version' => $version,
            'title' => trim((string) $versionData['title']),
            'description' => $versionData['description'] ?? null,
            'objective_type' => $versionData['objective_type'] ?? 'text',
            'indicator_key' => $versionData['indicator_key'] ?? null,
            'target_value' => $versionData['target_value'] ?? null,
            'target_text' => $versionData['target_text'] ?? null,
            'target_unit' => $versionData['target_unit'] ?? null,
            'visibility' => Arr::wrap($versionData['visibility'] ?? ['staff']),
            'notes' => $versionData['notes'] ?? null,
            'created_by' => $actor?->id,
        ]);
    }

    private function validateTarget(string $targetType, mixed $targetId, mixed $modality): void
    {
        if (! in_array($targetType, self::TARGET_TYPES, true)) {
            throw ValidationException::withMessages([
                'target_type' => 'Tipo de alvo de objetivo inválido.',
            ]);
        }

        if ($targetType === 'modality' && blank($modality)) {
            throw ValidationException::withMessages([
                'modality' => 'A modalidade é obrigatória para objetivos de modalidade.',
            ]);
        }

        if (in_array($targetType, ['club', 'modality'], true)) {
            return;
        }

        if (blank($targetId)) {
            throw ValidationException::withMessages([
                'target_id' => 'O alvo do objetivo é obrigatório.',
            ]);
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = self::TARGET_MODELS[$targetType];

        if (! $modelClass::query()->whereKey($targetId)->exists()) {
            throw ValidationException::withMessages([
                'target_id' => 'O alvo do objetivo não existe.',
            ]);
        }

        if ($targetType === 'training_group') {
            $belongsToClub = TrainingGroup::query()
                ->whereKey($targetId)
                ->where('club_id', $this->clubContext->id())
                ->exists();

            if (! $belongsToClub) {
                throw ValidationException::withMessages([
                    'target_id' => 'O grupo de treino não pertence ao clube ativo.',
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $versionData
     */
    private function validateVersionData(array $versionData): void
    {
        if (blank($versionData['title'] ?? null)) {
            throw ValidationException::withMessages([
                'title' => 'O título do objetivo é obrigatório.',
            ]);
        }

        $type = (string) ($versionData['objective_type'] ?? 'text');
        if (! in_array($type, ['text', 'measurable'], true)) {
            throw ValidationException::withMessages([
                'objective_type' => 'O objetivo deve ser textual ou mensurável.',
            ]);
        }

        if ($type === 'measurable'
            && blank($versionData['target_value'] ?? null)
            && blank($versionData['target_text'] ?? null)) {
            throw ValidationException::withMessages([
                'target_value' => 'Um objetivo mensurável necessita de um valor ou alvo textual.',
            ]);
        }
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Training extends Model
{
    use HasUuids;

    protected $fillable = [
        'numero_treino',
        'data',
        'hora_inicio',
        'hora_fim',
        'local',
        'epoca_id',
        'macrocycle_id',
        'mesociclo_id',
        'microciclo_id',
        'grupo_escalao_id',
        'escaloes',
        'tipo_treino',
        'volume_planeado_m',
        'notas_gerais',
        'descricao_treino',
        'criado_por',
        'evento_id',
        'atualizado_em',
        'club_id',
        'training_plan_version_id',
        'responsavel_id',
        'session_status',
        'instrucao',
        'plan_applied_at',
        'plan_applied_by',
        'published_at',
        'completed_at',
        'sports_venue_id',
        'training_recurrence_id',
        'recurrence_occurrence_key',
        'schedule_review_required',
        'schedule_conflicts_snapshot',
    ];

    protected $casts = [
        'data' => 'date',
        'escaloes' => 'array',
        'volume_planeado_m' => 'integer',
        'atualizado_em' => 'datetime',
        'plan_applied_at' => 'datetime',
        'published_at' => 'datetime',
        'completed_at' => 'datetime',
        'schedule_review_required' => 'boolean',
        'schedule_conflicts_snapshot' => 'array',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class, 'epoca_id');
    }

    public function microcycle(): BelongsTo
    {
        return $this->belongsTo(Microcycle::class, 'microciclo_id');
    }

    public function macrocycle(): BelongsTo
    {
        if (array_key_exists('macrocycle_id', $this->attributes)) {
            return $this->belongsTo(Macrocycle::class, 'macrocycle_id');
        }

        return $this->belongsTo(Macrocycle::class, 'macrociclo_id');
    }

    public function mesocycle(): BelongsTo
    {
        if (array_key_exists('mesocycle_id', $this->attributes)) {
            return $this->belongsTo(Mesocycle::class, 'mesocycle_id');
        }

        return $this->belongsTo(Mesocycle::class, 'mesociclo_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function responsibleCoach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_id');
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(TrainingPlanVersion::class, 'training_plan_version_id');
    }

    public function planAppliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plan_applied_by');
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(SportsVenue::class, 'sports_venue_id');
    }

    public function recurrence(): BelongsTo
    {
        return $this->belongsTo(TrainingRecurrence::class, 'training_recurrence_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function series(): HasMany
    {
        return $this->hasMany(TrainingSeries::class, 'treino_id')->orderBy('ordem');
    }

    public function sessionGroups(): HasMany
    {
        return $this->hasMany(TrainingSessionGroup::class, 'training_id')->orderBy('sort_order');
    }

    public function scheduleExceptions(): HasMany
    {
        return $this->hasMany(TrainingScheduleException::class, 'training_id')->orderBy('recorded_at');
    }

    public function athletes()
    {
        return $this->belongsToMany(
            User::class,
            'training_athletes',
            'treino_id',
            'user_id'
        )->withTimestamps()
         ->withPivot(['presente', 'estado', 'volume_real_m', 'rpe', 'observacoes_tecnicas', 'registado_por', 'registado_em']);
    }

    public function athleteRecords(): HasMany
    {
        return $this->hasMany(TrainingAthlete::class, 'treino_id');
    }

    public function athleteRecordsByTrainingId(): HasMany
    {
        return $this->hasMany(TrainingAthlete::class, 'training_id');
    }

    public function ageGroups(): BelongsToMany
    {
        return $this->belongsToMany(AgeGroup::class, 'training_age_group', 'treino_id', 'age_group_id')
            ->withTimestamps();
    }

    /**
     * Sync age groups while supporting legacy pivot schemas where `id` is required.
     */
    public function syncAgeGroupsWithPivot(array $ageGroupIds): void
    {
        if (!Schema::hasTable('training_age_group')) {
            return;
        }

        $normalizedIds = collect($ageGroupIds)
            ->filter(fn ($id) => !empty($id))
            ->unique()
            ->values();

        if (Schema::hasColumn('training_age_group', 'id')) {
            $syncPayload = $normalizedIds
                ->mapWithKeys(fn ($id) => [$id => ['id' => (string) Str::uuid()]])
                ->all();

            $this->ageGroups()->sync($syncPayload);
            return;
        }

        $this->ageGroups()->sync($normalizedIds->all());
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(TrainingMetric::class, 'treino_id');
    }

    public function isCompleted(): bool
    {
        return $this->session_status === 'completed' || $this->completed_at !== null;
    }
}

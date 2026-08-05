<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use App\Services\Members\MemberTypeResolver;

class Event extends Model
{
    use HasUuids;


    protected $fillable = [
        'titulo',
        'descricao',
        'data_inicio',
        'hora_inicio',
        'data_fim',
        'hora_fim',
        'local',
        'local_detalhes',
        'tipo',
        'tipo_config_id',
        'tipo_piscina',
        'visibilidade',
        // 'escaloes_elegiveis', // Removido - usar ageGroups() relationship
        'transporte_necessario',
        'transporte_detalhes',
        'hora_partida',
        'local_partida',
        'taxa_inscricao',
        'custo_inscricao_por_prova',
        'custo_inscricao_por_salto',
        'custo_inscricao_estafeta',
        'centro_custo_id',
        'observacoes',
        'convocatoria_ficheiro',
        'regulamento_ficheiro',
        'estado',
        'criado_por',
        'recorrente',
        'recorrencia_data_inicio',
        'recorrencia_data_fim',
        'recorrencia_dias_semana',
        'evento_pai_id',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'transporte_necessario' => 'boolean',
        'recorrente' => 'boolean',
        'recorrencia_data_inicio' => 'date',
        'recorrencia_data_fim' => 'date',
        // 'escaloes_elegiveis' => 'array', // Removido - usar ageGroups() relationship
        'recorrencia_dias_semana' => 'array',
        'taxa_inscricao' => 'decimal:2',
        'custo_inscricao_por_prova' => 'decimal:2',
        'custo_inscricao_por_salto' => 'decimal:2',
        'custo_inscricao_estafeta' => 'decimal:2',
    ];

    /**
     * ✅ Escalões elegíveis (N:N via pivot table)
     * SUBSTITUI: escaloes_elegiveis (JSON)
     */
    public function ageGroups(): BelongsToMany
    {
        return $this->belongsToMany(
            AgeGroup::class,
            'event_age_group',
            'event_id',
            'age_group_id'
        )->withTimestamps();
    }

    public function tipoConfig(): BelongsTo
    {
        return $this->belongsTo(EventTypeConfig::class, 'tipo_config_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'centro_custo_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function parentEvent(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_pai_id');
    }

    public function childEvents(): HasMany
    {
        return $this->hasMany(Event::class, 'evento_pai_id');
    }

    public function convocations(): HasMany
    {
        return $this->hasMany(EventConvocation::class, 'evento_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(EventConvocation::class, 'evento_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(EventAttendance::class, 'evento_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(EventResult::class, 'evento_id');
    }

    public function trainings(): HasMany
    {
        return $this->hasMany(Training::class, 'evento_id');
    }

    public function competition(): HasMany
    {
        return $this->hasMany(Competition::class, 'evento_id');
    }

    public function convocationGroups(): HasMany
    {
        return $this->hasMany(ConvocationGroup::class, 'evento_id');
    }

    public function convocationMovements(): HasMany
    {
        return $this->hasMany(ConvocationMovement::class, 'evento_id');
    }

    public function getEstadoAttribute(?string $value): string
    {
        return $this->resolveDisplayEstado($value);
    }

    public function resolveDisplayEstado(?string $estado = null, ?Carbon $referenceDate = null): string
    {
        $currentEstado = $estado ?? $this->getRawOriginal('estado') ?? 'rascunho';

        if (in_array($currentEstado, ['rascunho', 'cancelado'], true)) {
            return $currentEstado;
        }

        if (!$this->data_inicio instanceof Carbon) {
            return $currentEstado;
        }

        $reference = ($referenceDate ?? now())->copy()->startOfDay();
        $startDate = $this->data_inicio->copy()->startOfDay();
        $endDate = ($this->data_fim instanceof Carbon ? $this->data_fim : $this->data_inicio)
            ->copy()
            ->endOfDay();

        if ($reference->lt($startDate)) {
            return 'agendado';
        }

        if ($reference->gt($endDate)) {
            return 'concluido';
        }

        return 'em_curso';
    }

    // ===== ACCESSORS/MUTATORS =====

    /**
     * ✅ Accessor para compatibilidade com código frontend
     * Retorna array de UUIDs dos age_groups
     */
    public function getEscaloesElegiveisAttribute(): array
    {
        if ($this->relationLoaded('ageGroups')) {
            return $this->ageGroups->pluck('id')->toArray();
        }
        
        return $this->ageGroups()->pluck('age_groups.id')->toArray();
    }

    /**
     * ✅ Mutator para compatibilidade com código frontend
     * Aceita array de UUIDs e sincroniza pivot table
     */
    public function setEscaloesElegiveisAttribute($value): void
    {
        if (is_array($value) && $this->exists) {
            $this->ageGroups()->sync($value);
        }
    }

    /**
     * ✅ Sincronizar escalões e atualizar presenças automaticamente (para treinos)
     */
    public function syncAgeGroups(array $ageGroupIds): void
    {
        $this->ageGroups()->sync($ageGroupIds);
        
        // Se for treino, atualizar presenças automaticamente
        if ($this->tipo === 'treino') {
            $this->syncAttendances();
        }
    }

    /**
     * ✅ Sincronizar presenças baseado nos escalões elegíveis
     */
    public function syncAttendances(): void
    {
        $ageGroupIds = $this->ageGroups()->pluck('age_groups.id')->toArray();
        
        if (empty($ageGroupIds)) {
            // Se não há escalões, remover todas as presenças
            $this->attendances()->delete();
            return;
        }

        $eligibleUsers = User::query()
            ->with(['athleteSportsData:id,user_id,escalao_id', 'userTypes:id,codigo,nome'])
            ->where('estado', 'ativo')
            ->where(function ($query) {
                $query->whereNull('ativo_desportivo')
                    ->orWhere('ativo_desportivo', true);
            })
            ->where(function ($query) use ($ageGroupIds) {
                $query->whereHas('athleteSportsData', function ($sportsDataQuery) use ($ageGroupIds) {
                    $sportsDataQuery->whereIn('escalao_id', $ageGroupIds);
                });

                foreach ($ageGroupIds as $ageGroupId) {
                    $query->orWhereJsonContains('escalao', $ageGroupId);
                }
            })
            ->get()
            ->filter(fn (User $user) => app(MemberTypeResolver::class)->isAthlete($user));
        
        $eligibleUserIds = $eligibleUsers->pluck('id')->toArray();

        // Get existing attendance records
        $existingUserIds = $this->attendances()->pluck('user_id')->toArray();

        // Add new users
        $newUserIds = array_diff($eligibleUserIds, $existingUserIds);
        foreach ($newUserIds as $userId) {
            EventAttendance::create([
                'evento_id' => $this->id,
                'user_id' => $userId,
                'estado' => 'ausente',
                'registado_por' => auth()->id() ?? $this->criado_por,
                'registado_em' => now(),
                'observacoes' => 'Adicionado automaticamente',
            ]);
        }

        // Remove users who are no longer eligible
        $removedUserIds = array_diff($existingUserIds, $eligibleUserIds);
        if (!empty($removedUserIds)) {
            $this->attendances()->whereIn('user_id', $removedUserIds)->delete();
        }
    }

    /**
     * Evento é considerado treino quando tipo='treino' e existe treino associado.
     */
    public function isTreino(): bool
    {
        if ($this->tipo !== 'treino') {
            return false;
        }

        if ($this->relationLoaded('trainings')) {
            return $this->trainings->isNotEmpty();
        }

        return $this->trainings()->exists();
    }

    /**
     * Presenças de eventos-treino devem ser geridas no módulo Desportivo.
     */
    public function canEditAttendances(): bool
    {
        return !$this->isTreino();
    }
}

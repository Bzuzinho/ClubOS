<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventResult extends Model
{
    use HasUuids;

    protected $table = 'event_results';

    protected $fillable = [
        'evento_id',
        'user_id',
        'prova',
        'tempo',
        'classificacao',
        'piscina',
        'age_group_snapshot_id', // ✅ FK snapshot (preserva histórico)
        // 'age_group_id', // Removido - usar snapshot
        // 'escalao', // Removido - usar snapshot
        'observacoes',
        'epoca',
        'registado_por',
        'registado_em',
    ];

    protected $casts = [
        'classificacao' => 'integer',
        'registado_em' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Alias for compatibility
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registado_por');
    }

    /**
     * ✅ Snapshot do escalão (histórico)
     */
    public function ageGroupSnapshot(): BelongsTo
    {
        return $this->belongsTo(AgeGroup::class, 'age_group_snapshot_id');
    }

    // Manter alias para compatibilidade
    public function ageGroup(): BelongsTo
    {
        return $this->ageGroupSnapshot();
    }

    // ===== ACCESSORS =====

    /**
     * ✅ Accessor: retorna nome do escalão
     * Prioridade: snapshot → primeiro escalão do user → null
     */
    public function getEscalaoAttribute(): ?string
    {
        if ($this->ageGroupSnapshot) {
            return $this->ageGroupSnapshot->nome;
        }
        
        $canonicalAgeGroupId = $this->athlete?->athleteSportsData?->escalao_id;
        if ($canonicalAgeGroupId) {
            return AgeGroup::query()->find($canonicalAgeGroupId)?->nome;
        }

        // Compatibilidade temporária com o escalão legacy em users.
        if ($this->athlete && !empty($this->athlete->escalao)) {
            $escaloes = is_array($this->athlete->escalao) ? $this->athlete->escalao : [$this->athlete->escalao];
            if (!empty($escaloes[0])) {
                $ageGroup = AgeGroup::find($escaloes[0]);
                return $ageGroup?->nome;
            }
        }
        
        return null;
    }

    // ===== BOOT =====

    protected static function booted(): void
    {
        /**
         * ✅ Auto-snapshot do escalão ao criar resultado
         */
        static::creating(function (EventResult $result) {
            if (!$result->age_group_snapshot_id && $result->user_id) {
                $user = User::with('athleteSportsData:id,user_id,escalao_id')->find($result->user_id);

                if ($user?->athleteSportsData?->escalao_id) {
                    $result->age_group_snapshot_id = $user->athleteSportsData->escalao_id;
                } elseif ($user && !empty($user->escalao)) {
                    // Pegar primeiro escalão do array JSON
                    $escaloes = is_array($user->escalao) ? $user->escalao : [$user->escalao];
                    if (!empty($escaloes[0])) {
                        $result->age_group_snapshot_id = $escaloes[0];
                    }
                }
            }
            
            // Auto-preencher registado_em
            if (!$result->registado_em) {
                $result->registado_em = now();
            }
        });
    }
}

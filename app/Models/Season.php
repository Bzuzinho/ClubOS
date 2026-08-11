<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    use HasUuids;
    protected $fillable = ['nome','descricao','data_inicio','data_fim','ativa','piscina_principal','escaloes_abrangidos','provas_alvo','objetivo_principal','objetivo_secundario','created_by','updated_by','club_id','sports_modality_id','status','closed_at','closed_by','reopened_at','reopened_by','reopen_reason'];
    protected $casts = ['data_inicio'=>'date','data_fim'=>'date','ativa'=>'boolean','escaloes_abrangidos'=>'array','provas_alvo'=>'array','closed_at'=>'datetime','reopened_at'=>'datetime'];
    public function modality(): BelongsTo { return $this->belongsTo(SportsModality::class, 'sports_modality_id'); }
    public function programs(): HasMany { return $this->hasMany(SeasonProgram::class); }
    public function ageGroupRules(): HasMany { return $this->hasMany(SeasonAgeGroupRule::class); }
    public function groupConfigurations(): HasMany { return $this->hasMany(TrainingGroupSeason::class); }
    public function macrocycles(): HasMany { return $this->hasMany(Macrocycle::class, 'epoca_id'); }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgeGroup extends Model
{
    use HasUuids;
    protected $fillable = ['club_id','code','nome','descricao','idade_minima','idade_maxima','ano_minimo','ano_maximo','sexo','ativo','archived_at'];
    protected $casts = ['idade_minima'=>'integer','idade_maxima'=>'integer','ano_minimo'=>'integer','ano_maximo'=>'integer','ativo'=>'boolean','archived_at'=>'datetime'];
    public function provas(): HasMany { return $this->hasMany(Prova::class, 'escalao_id'); }
    public function athleteSportsData(): HasMany { return $this->hasMany(AthleteSportsData::class, 'escalao_id'); }
    public function seasonRules(): HasMany { return $this->hasMany(SeasonAgeGroupRule::class); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}

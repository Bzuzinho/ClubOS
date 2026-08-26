<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsProgram extends Model
{
    use HasUuids;

    protected $fillable = ['club_id','sports_modality_id','code','name','description','active','archived_at','created_by','updated_by'];
    protected $casts = ['active' => 'boolean', 'archived_at' => 'datetime'];

    public function modality(): BelongsTo { return $this->belongsTo(SportsModality::class, 'sports_modality_id'); }
    public function seasonPrograms(): HasMany { return $this->hasMany(SeasonProgram::class, 'sports_program_id'); }
    public function scopeForClub($query, string $clubId) { return $query->where('club_id', $clubId); }
}

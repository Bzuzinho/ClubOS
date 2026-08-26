<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Familia extends Model
{
    use HasUuids;

    protected $table = 'familias';

    protected $fillable = [
        'nome',
        'responsavel_user_id',
        'observacoes',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function responsavel(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsavel_user_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(FamiliaUser::class, 'familia_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'familia_user', 'familia_id', 'user_id')
            ->using(FamiliaUser::class)
            ->withPivot([
                'id',
                'papel_na_familia',
                'pode_editar',
                'pode_ver_financeiro',
                'pode_ver_desportivo',
                'pode_ver_documentos',
                'pode_ver_comunicacoes',
            ])
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'family_id');
    }

    public function accountCredits(): HasMany
    {
        return $this->hasMany(AccountCredit::class, 'family_id');
    }
}
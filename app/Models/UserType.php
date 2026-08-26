<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserType extends Model
{
    use HasUuids;


    protected $fillable = [
        'codigo',
        'nome',
        'descricao',
        'ativo',
        'menu_visibility_configured',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'menu_visibility_configured' => 'boolean',
    ];

    public function permissions(): HasMany
    {
        return $this->hasMany(UserTypePermission::class, 'user_type_id');
    }

    public function permissionAssignments(): HasMany
    {
        return $this->hasMany(UserTypePermission::class, 'user_type_id');
    }

    public function menuModules(): HasMany
    {
        return $this->hasMany(UserTypeMenuModule::class, 'user_type_id')->orderBy('sort_order');
    }

    public function landingPage(): HasOne
    {
        return $this->hasOne(UserTypeLandingPage::class, 'user_type_id');
    }
}

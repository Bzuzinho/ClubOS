<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserTypePermission extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_type_id',
        'permission_node_id',
        'can_view',
        'can_edit',
        'can_delete',
        'modulo',
        'submodulo',
        'separador',
        'campo',
        'pode_ver',
        'pode_criar',
        'pode_editar',
        'pode_eliminar',
    ];

    protected $casts = [
        'can_view' => 'boolean',
        'can_edit' => 'boolean',
        'can_delete' => 'boolean',
        'pode_ver' => 'boolean',
        'pode_criar' => 'boolean',
        'pode_editar' => 'boolean',
        'pode_eliminar' => 'boolean',
    ];

    public function userType(): BelongsTo
    {
        return $this->belongsTo(UserType::class, 'user_type_id');
    }

    public function permissionNode(): BelongsTo
    {
        return $this->belongsTo(PermissionNode::class, 'permission_node_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class UserGuardian extends Pivot
{
    use HasUuids;

    protected $table = 'user_guardian';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'user_id',
        'guardian_id',
    ];
}

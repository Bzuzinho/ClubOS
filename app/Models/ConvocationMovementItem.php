<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConvocationMovementItem extends Model
{
    use HasUuids;

    protected $table = 'convocation_movement_items';

    protected $fillable = [
        'movimento_convocatoria_id',
        'descricao',
        'valor',
    ];

    protected $casts = [
        'valor' => 'decimal:2',
    ];

    public function convocationMovement(): BelongsTo
    {
        return $this->belongsTo(ConvocationMovement::class, 'movimento_convocatoria_id');
    }
}

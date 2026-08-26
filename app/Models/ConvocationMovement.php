<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConvocationMovement extends Model
{
    use HasUuids;

    protected $table = 'convocation_movements';

    protected $fillable = [
        'user_id',
        'convocatoria_grupo_id',
        'evento_id',
        'evento_nome',
        'tipo',
        'data_emissao',
        'valor',
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'valor' => 'decimal:2',
    ];

    public function convocationGroup(): BelongsTo
    {
        return $this->belongsTo(ConvocationGroup::class, 'convocatoria_grupo_id');
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ConvocationMovementItem::class, 'movimento_convocatoria_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamResult extends Model
{
    use HasUuids;

    protected $table = 'team_results';

    protected $fillable = [
        'competicao_id',
        'equipa',
        'classificacao',
        'pontos',
        'observacoes',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class, 'competicao_id');
    }
}

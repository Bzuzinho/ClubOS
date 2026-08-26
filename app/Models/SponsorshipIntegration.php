<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsorshipIntegration extends Model
{
    use HasUuids;

    protected $fillable = [
        'sponsorship_id',
        'integration_type',
        'source_type',
        'source_id',
        'target_module',
        'target_table',
        'target_record_id',
        'status',
        'message',
        'executed_at',
    ];

    protected $casts = [
        'executed_at' => 'datetime',
    ];

    public function sponsorship(): BelongsTo
    {
        return $this->belongsTo(Sponsorship::class, 'sponsorship_id');
    }
}
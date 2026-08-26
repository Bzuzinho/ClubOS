<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsConvocationPublication extends Model
{
    use HasUuids;

    protected $fillable = [
        'convocation_group_id','version','fingerprint','published_by','published_at',
        'recipient_count','communication_status','communication_key','snapshot_json',
    ];

    protected $casts = [
        'version' => 'integer',
        'published_at' => 'datetime',
        'recipient_count' => 'integer',
        'snapshot_json' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ConvocationGroup::class, 'convocation_group_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}

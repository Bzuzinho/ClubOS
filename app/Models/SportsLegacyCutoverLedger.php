<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SportsLegacyCutoverLedger extends Model
{
    use HasUuids;

    protected $table = 'sports_legacy_cutover_ledger';

    protected $fillable = [
        'club_id',
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'status',
        'reason',
        'source_snapshot',
        'audited_at',
        'migrated_at',
    ];

    protected $casts = [
        'source_snapshot' => 'array',
        'audited_at' => 'datetime',
        'migrated_at' => 'datetime',
    ];
}

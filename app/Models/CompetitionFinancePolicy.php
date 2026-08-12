<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionFinancePolicy extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'competition_id',
        'payer_mode',
        'charge_mode',
        'fixed_amount',
        'per_race_amount',
        'age_group_rates',
        'cost_center_id',
        'active',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'fixed_amount' => 'decimal:2',
        'per_race_amount' => 'decimal:2',
        'age_group_rates' => 'array',
        'active' => 'boolean',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }
}

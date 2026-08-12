<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionFinancialObligation extends Model
{
    use HasUuids;

    protected $fillable = [
        'club_id',
        'competition_id',
        'user_id',
        'invoice_id',
        'status',
        'calculated_amount',
        'manual_amount',
        'calculation_snapshot',
        'manual_review_reason',
        'synchronized_at',
    ];

    protected $casts = [
        'calculated_amount' => 'decimal:2',
        'manual_amount' => 'decimal:2',
        'calculation_snapshot' => 'array',
        'synchronized_at' => 'datetime',
    ];

    public function competition(): BelongsTo
    {
        return $this->belongsTo(Competition::class);
    }

    public function athlete(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}

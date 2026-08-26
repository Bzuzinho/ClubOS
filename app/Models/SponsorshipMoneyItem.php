<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SponsorshipMoneyItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'sponsorship_id',
        'description',
        'amount',
        'expected_date',
        'financial_movement_id',
        'integration_status',
        'integration_message',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expected_date' => 'date',
    ];

    public function sponsorship(): BelongsTo
    {
        return $this->belongsTo(Sponsorship::class, 'sponsorship_id');
    }

    public function financialMovement(): BelongsTo
    {
        return $this->belongsTo(Movement::class, 'financial_movement_id');
    }
}
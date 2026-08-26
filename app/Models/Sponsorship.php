<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sponsorship extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'codigo',
        'sponsor_name',
        'sponsor_id',
        'supplier_id',
        'type',
        'title',
        'description',
        'periodicity',
        'start_date',
        'end_date',
        'cost_center_id',
        'status',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Sponsor::class, 'sponsor_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'cost_center_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function moneyItems(): HasMany
    {
        return $this->hasMany(SponsorshipMoneyItem::class, 'sponsorship_id');
    }

    public function goodsItems(): HasMany
    {
        return $this->hasMany(SponsorshipGoodsItem::class, 'sponsorship_id');
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(SponsorshipIntegration::class, 'sponsorship_id');
    }
}
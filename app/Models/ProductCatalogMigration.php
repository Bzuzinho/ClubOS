<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCatalogMigration extends Model
{
    use HasUuids;

    protected $table = 'product_catalog_migrations';

    protected $fillable = [
        'legacy_source',
        'legacy_id',
        'product_id',
        'product_variant_id',
        'migrated_at',
        'notes',
    ];

    protected $casts = [
        'migrated_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
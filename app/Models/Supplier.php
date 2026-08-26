<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    use HasUuids;

    protected $fillable = [
        'nome',
        'nif',
        'email',
        'telefone',
        'morada',
        'categoria',
        'notas',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'supplier_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(SupplierPurchase::class, 'supplier_id');
    }

    public function sponsorships(): HasMany
    {
        return $this->hasMany(Sponsorship::class, 'supplier_id');
    }
}

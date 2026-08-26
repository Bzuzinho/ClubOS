<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostCenter extends Model
{
    use HasUuids;


    protected $fillable = [
        'codigo',
        'nome',
        'tipo',
        'descricao',
        'orcamento',
        'ativo',
    ];

    protected $casts = [
        'ativo' => 'boolean',
        'orcamento' => 'decimal:2',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'centro_custo_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'centro_custo_user', 'centro_custo_id', 'user_id')
            ->withPivot('peso')
            ->withTimestamps();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'centro_custo_id');
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'centro_custo_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(Movement::class, 'centro_custo_id');
    }

    public function movementItems(): HasMany
    {
        return $this->hasMany(MovementItem::class, 'centro_custo_id');
    }

    public function financialEntries(): HasMany
    {
        return $this->hasMany(FinancialEntry::class, 'centro_custo_id');
    }

    public function bankStatements(): HasMany
    {
        return $this->hasMany(BankStatement::class, 'centro_custo_id');
    }

    public function sponsorships(): HasMany
    {
        return $this->hasMany(Sponsorship::class, 'cost_center_id');
    }
}

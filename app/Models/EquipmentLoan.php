<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentLoan extends Model
{
    use HasUuids;

    protected $table = 'equipment_loans';

    protected $fillable = [
        'borrower_user_id',
        'borrower_name_snapshot',
        'article_id',
        'article_name_snapshot',
        'quantity',
        'loan_date',
        'due_date',
        'return_date',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'loan_date' => 'date',
        'due_date' => 'date',
        'return_date' => 'date',
    ];

    public function borrower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'borrower_user_id');
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'article_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

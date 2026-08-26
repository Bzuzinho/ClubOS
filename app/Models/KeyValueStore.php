<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeyValueStore extends Model
{
    use HasUuids;

    protected $table = 'key_value_store';

    protected $fillable = [
        'key',
        'user_id',
        'value',
        'scope',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get value for key (global or user-specific)
     */
    public static function getValue(string $key, $userId = null)
    {
        $query = static::where('key', $key);

        if ($userId) {
            $query->where('user_id', $userId)->where('scope', 'user');
        } else {
            $query->where('scope', 'global')->whereNull('user_id');
        }

        $record = $query->first();
        return $record ? $record->value : null;
    }

    /**
     * Set value for key (global or user-specific)
     */
    public static function setValue(string $key, $value, $userId = null): void
    {
        $scope = $userId ? 'user' : 'global';

        static::updateOrCreate(
            [
                'key' => $key,
                'user_id' => $userId,
            ],
            [
                'value' => $value,
                'scope' => $scope,
            ]
        );
    }

    /**
     * Delete value for key
     */
    public static function deleteValue(string $key, $userId = null): void
    {
        $query = static::where('key', $key);

        if ($userId) {
            $query->where('user_id', $userId);
        } else {
            $query->whereNull('user_id');
        }

        $query->delete();
    }
}

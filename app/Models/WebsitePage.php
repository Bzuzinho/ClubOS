<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebsitePage extends Model
{
    use HasUuids;
    use SoftDeletes;

    public const STATUSES = ['legacy', 'draft', 'published', 'scheduled', 'hidden'];

    protected $fillable = [
        'slug',
        'title',
        'navigation_label',
        'status',
        'is_system',
        'show_in_navigation',
        'sort_order',
        'meta_title',
        'meta_description',
        'design_settings',
        'published_snapshot',
        'scheduled_snapshot',
        'published_at',
        'scheduled_for',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_system' => 'boolean',
        'show_in_navigation' => 'boolean',
        'sort_order' => 'integer',
        'design_settings' => 'array',
        'published_snapshot' => 'array',
        'scheduled_snapshot' => 'array',
        'published_at' => 'datetime',
        'scheduled_for' => 'datetime',
    ];

    public function blocks(): HasMany
    {
        return $this->hasMany(WebsitePageBlock::class)->orderBy('sort_order');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WebsitePageVersion::class)->orderByDesc('version');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function publicPath(): string
    {
        return $this->slug === 'home' ? '/' : '/'.$this->slug;
    }
}

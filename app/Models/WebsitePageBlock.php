<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsitePageBlock extends Model
{
    use HasUuids;

    public const TYPES = [
        'hero',
        'section',
        'rich_text',
        'cards',
        'image_text',
        'stats',
        'cta',
        'news_feed',
        'events_feed',
        'contact_form',
        'registration_form',
    ];

    protected $fillable = [
        'website_page_id',
        'block_key',
        'type',
        'sort_order',
        'is_visible',
        'content',
        'style',
        'settings',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
        'content' => 'array',
        'style' => 'array',
        'settings' => 'array',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(WebsitePage::class, 'website_page_id');
    }
}

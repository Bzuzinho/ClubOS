<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SocialNetworkAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'provider',
        'display_name',
        'username',
        'external_account_id',
        'graph_api_version',
        'app_id',
        'app_secret',
        'access_token',
        'webhook_verify_token',
        'is_enabled',
        'verification_status',
        'verification_message',
        'last_verified_at',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'app_secret',
        'access_token',
        'webhook_verify_token',
    ];

    protected $casts = [
        'app_secret' => 'encrypted',
        'access_token' => 'encrypted',
        'webhook_verify_token' => 'encrypted',
        'is_enabled' => 'boolean',
        'last_verified_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deliveryRecipients(): HasMany
    {
        return $this->hasMany(CommunicationDeliveryRecipient::class, 'social_network_account_id');
    }

    public function isPublishReady(): bool
    {
        return $this->is_enabled
            && filled($this->external_account_id)
            && filled($this->access_token);
    }

    /** @return array<string,mixed> */
    public function safeConfiguration(): array
    {
        return [
            'id' => (string) $this->id,
            'provider' => $this->provider,
            'display_name' => $this->display_name,
            'username' => $this->username,
            'external_account_id' => $this->external_account_id,
            'graph_api_version' => $this->graph_api_version,
            'app_id' => $this->app_id,
            'is_enabled' => $this->is_enabled,
            'verification_status' => $this->verification_status,
            'verification_message' => $this->verification_message,
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'has_app_secret' => filled($this->app_secret),
            'has_access_token' => filled($this->access_token),
            'has_webhook_verify_token' => filled($this->webhook_verify_token),
            'publish_ready' => $this->isPublishReady(),
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicFormSubmission extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'athlete_name',
        'birth_date',
        'email',
        'phone',
        'program',
        'experience',
        'locality',
        'previous_club',
        'federation_number',
        'availability',
        'guardian_name',
        'guardian_relationship',
        'guardian_email',
        'guardian_phone',
        'notes',
        'status',
        'user_id',
        'processed_by',
        'identity_fingerprint',
        'processed_at',
        'email_queued_at',
        'admin_notified_at',
        'privacy_consent_at',
        'ip_hash',
        'user_agent',
        'payload',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'processed_at' => 'datetime',
        'email_queued_at' => 'datetime',
        'admin_notified_at' => 'datetime',
        'privacy_consent_at' => 'datetime',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
        'privacy_consent_at',
        'ip_hash',
        'user_agent',
        'payload',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'privacy_consent_at' => 'datetime',
        'payload' => 'array',
    ];
}

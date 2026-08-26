<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids as HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrainingSessionContentRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['club_id','training_id','revision_type','source_plan_version_id','reason','before_snapshot','after_snapshot','created_by','created_at'];
    protected $casts = ['before_snapshot' => 'array','after_snapshot' => 'array','created_at' => 'datetime'];

    public function training(): BelongsTo { return $this->belongsTo(Training::class, 'training_id'); }
    public function sourcePlanVersion(): BelongsTo { return $this->belongsTo(TrainingPlanVersion::class, 'source_plan_version_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}

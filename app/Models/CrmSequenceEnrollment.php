<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmSequenceEnrollment extends Model
{
    protected $table = 'crm_sequence_enrollments';

    protected $fillable = [
        'sequence_id', 'contact_id', 'group_preset_id', 'status', 'current_step',
        'next_run_at', 'started_at', 'paused_at', 'completed_at', 'stopped_reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'next_run_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNotNull('next_run_at')->where('next_run_at', '<=', now());
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(CrmSequence::class, 'sequence_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function groupPreset(): BelongsTo
    {
        return $this->belongsTo(WhatsAppGroupPreset::class, 'group_preset_id');
    }

    public function dispatches(): HasMany
    {
        return $this->hasMany(CrmSequenceDispatch::class, 'enrollment_id');
    }
}

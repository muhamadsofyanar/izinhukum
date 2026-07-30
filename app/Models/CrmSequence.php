<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmSequence extends Model
{
    protected $table = 'crm_sequences';

    protected $fillable = [
        'name', 'description', 'audience_type', 'device_alias', 'group_interval_seconds', 'stop_on_reply',
        'stop_on_deal', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return ['group_interval_seconds' => 'integer', 'stop_on_reply' => 'boolean', 'stop_on_deal' => 'boolean', 'is_active' => 'boolean'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CrmSequenceStep::class, 'sequence_id')->orderBy('position');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(CrmSequenceEnrollment::class, 'sequence_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

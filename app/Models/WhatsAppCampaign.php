<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppCampaign extends Model
{
    protected $fillable = [
        'name', 'template_id', 'created_by', 'audience_type', 'status', 'use_rotator',
        'rotator_mode', 'delay_seconds', 'scheduled_at', 'started_at', 'completed_at',
        'recipient_count', 'queued_count', 'sent_count', 'failed_count', 'filters', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'use_rotator' => 'boolean',
            'delay_seconds' => 'integer',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'recipient_count' => 'integer',
            'queued_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'filters' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class, 'whatsapp_campaign_id');
    }
}

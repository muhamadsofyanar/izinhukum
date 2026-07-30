<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppAutomation extends Model
{
    protected $table = 'whatsapp_automations';

    protected $fillable = [
        'key', 'name', 'trigger', 'template_id', 'recipient_type', 'is_enabled',
        'delay_minutes', 'conditions', 'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'delay_minutes' => 'integer',
            'conditions' => 'array',
            'last_run_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }
}

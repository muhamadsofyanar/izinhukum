<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppGroup extends Model
{
    protected $table = 'whatsapp_groups';

    protected $fillable = [
        'device_alias', 'group_jid', 'name', 'participant_count', 'is_active',
        'last_synced_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'participant_count' => 'integer',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}

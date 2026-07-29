<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppDevice extends Model
{
    protected $fillable = [
        'provider_id', 'name', 'phone', 'role', 'status', 'is_default', 'is_enabled',
        'daily_limit', 'last_checked_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider_id' => 'integer',
            'is_default' => 'boolean',
            'is_enabled' => 'boolean',
            'daily_limit' => 'integer',
            'last_checked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}

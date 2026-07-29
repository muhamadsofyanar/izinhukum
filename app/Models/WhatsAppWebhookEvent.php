<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppWebhookEvent extends Model
{
    protected $fillable = [
        'fingerprint', 'event_type', 'provider_message_id', 'phone', 'payload',
        'processed', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }
}

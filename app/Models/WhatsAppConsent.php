<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppConsent extends Model
{
    protected $table = 'whatsapp_consents';

    protected $fillable = [
        'phone', 'allow_transactional', 'allow_marketing', 'source', 'evidence',
        'consented_at', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'allow_transactional' => 'boolean',
            'allow_marketing' => 'boolean',
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function marketingActive(): bool
    {
        return $this->allow_marketing && $this->revoked_at === null;
    }
}

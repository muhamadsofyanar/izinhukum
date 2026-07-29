<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppTemplate extends Model
{
    protected $fillable = [
        'key', 'name', 'category', 'description', 'body', 'message_type', 'media_url',
        'variables', 'is_enabled', 'is_marketing', 'version', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_enabled' => 'boolean',
            'is_marketing' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'template_id');
    }
}

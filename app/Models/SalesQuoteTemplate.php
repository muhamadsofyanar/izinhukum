<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesQuoteTemplate extends Model
{
    protected $fillable = [
        'name', 'service_id', 'scope', 'terms', 'notes', 'validity_days',
        'invoice_due_days', 'is_active', 'use_count', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'validity_days' => 'integer',
            'invoice_due_days' => 'integer',
            'is_active' => 'boolean',
            'use_count' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(SalesQuote::class);
    }
}

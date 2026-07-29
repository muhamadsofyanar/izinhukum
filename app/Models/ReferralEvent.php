<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferralEvent extends Model
{
    protected $fillable = [
        'partner_referral_id',
        'partner_id',
        'inquiry_id',
        'service_order_id',
        'invoice_id',
        'payment_id',
        'event_type',
        'source_key',
        'event_value',
        'path',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_value' => 'integer',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }
}

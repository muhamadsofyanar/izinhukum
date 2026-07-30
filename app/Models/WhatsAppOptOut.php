<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppOptOut extends Model
{
    protected $table = 'whatsapp_opt_outs';

    protected $fillable = [
        'phone', 'block_marketing', 'block_ai', 'block_all', 'source', 'reason',
        'opted_out_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'block_marketing' => 'boolean',
            'block_ai' => 'boolean',
            'block_all' => 'boolean',
            'opted_out_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

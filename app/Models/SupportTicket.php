<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTicket extends Model
{
    protected $fillable = [
        'reference', 'user_id', 'assigned_to', 'subject', 'category',
        'priority', 'status', 'message', 'admin_response', 'resolved_at',
    ];
    protected function casts(): array { return ['resolved_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}

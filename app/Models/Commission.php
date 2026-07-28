<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commission extends Model
{
    protected $fillable = ['partner_id', 'invoice_id', 'amount', 'status', 'notes', 'paid_at'];
    protected function casts(): array { return ['paid_at' => 'datetime']; }
    public function partner(): BelongsTo { return $this->belongsTo(User::class, 'partner_id'); }
}

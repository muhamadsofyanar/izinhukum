<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppRecipientPreset extends Model
{
    protected $fillable = ['created_by', 'name', 'definition', 'is_active'];
    protected function casts(): array { return ['definition' => 'array', 'is_active' => 'boolean']; }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}

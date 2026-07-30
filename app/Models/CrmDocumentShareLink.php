<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmDocumentShareLink extends Model
{
    protected $table = 'crm_document_share_links';

    protected $fillable = [
        'document_id', 'token_hash', 'purpose', 'expires_at', 'access_count',
        'last_access_at', 'revoked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'access_count' => 'integer',
            'last_access_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CrmDocument::class, 'document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function usable(): bool
    {
        return $this->revoked_at === null && $this->expires_at?->isFuture();
    }
}

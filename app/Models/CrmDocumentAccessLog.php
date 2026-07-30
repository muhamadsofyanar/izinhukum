<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmDocumentAccessLog extends Model
{
    protected $table = 'crm_document_access_logs';

    protected $fillable = ['document_id', 'user_id', 'action', 'ip_address', 'user_agent', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CrmDocument::class, 'document_id');
    }
}

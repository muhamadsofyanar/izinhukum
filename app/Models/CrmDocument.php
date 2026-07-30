<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmDocument extends Model
{
    protected $table = 'crm_documents';

    protected $fillable = [
        'contact_id', 'lead_id', 'service_order_id', 'conversation_id', 'whatsapp_message_id',
        'requirement_id', 'category', 'name', 'original_name', 'disk', 'path', 'mime_type',
        'extension', 'size', 'checksum', 'source', 'source_url', 'archive_status',
        'verification_status', 'notes', 'uploaded_by', 'archived_at', 'verified_at',
        'expires_at', 'access_token', 'access_token_expires_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'archived_at' => 'datetime',
            'verified_at' => 'datetime',
            'expires_at' => 'datetime',
            'access_token_expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function whatsappMessage(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }

    public function requirement(): BelongsTo
    {
        return $this->belongsTo(CrmRequirement::class, 'requirement_id');
    }

    public function shareLinks(): HasMany
    {
        return $this->hasMany(CrmDocumentShareLink::class, 'document_id')->latest();
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(CrmDocumentAccessLog::class, 'document_id')->latest('occurred_at');
    }
}

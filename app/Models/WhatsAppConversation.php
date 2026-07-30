<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'phone', 'channel', 'device_alias', 'display_name', 'contact_type', 'contact_id', 'lead_id',
        'partner_id', 'inquiry_id', 'service_order_id', 'assigned_to', 'status', 'unread_count',
        'is_ai_blocked', 'labels', 'metadata', 'last_message_at', 'last_inbound_at', 'last_outbound_at',
    ];

    protected function casts(): array
    {
        return [
            'unread_count' => 'integer',
            'is_ai_blocked' => 'boolean',
            'labels' => 'array',
            'metadata' => 'array',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id')->orderBy('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(WhatsAppMessage::class, 'conversation_id')->latestOfMany();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}

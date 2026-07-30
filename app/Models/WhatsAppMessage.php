<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppMessage extends Model
{
    protected $fillable = [
        'conversation_id', 'template_id', 'contact_id', 'lead_id', 'crm_document_id', 'inquiry_id', 'service_order_id', 'invoice_id',
        'payment_id', 'partner_id', 'created_by', 'direction', 'channel', 'phone',
        'recipient_name', 'message_type', 'body', 'media_url', 'device_alias', 'status',
        'provider_message_id', 'provider_device_id', 'idempotency_key', 'attempts',
        'scheduled_at', 'accepted_at', 'sent_at', 'failed_at', 'last_error',
        'provider_response', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider_device_id' => 'integer',
            'attempts' => 'integer',
            'scheduled_at' => 'datetime',
            'accepted_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'provider_response' => 'array',
            'metadata' => 'array',
        ];
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $status): void {
                $status->whereIn('status', ['queued', 'scheduled', 'retrying'])
                    ->orWhere(function (Builder $stale): void {
                        $stale->where('status', 'processing')
                            ->where('updated_at', '<=', now()->subMinutes(5));
                    });
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function crmDocument(): BelongsTo
    {
        return $this->belongsTo(CrmDocument::class, 'crm_document_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(WhatsAppMessageAttempt::class, 'whatsapp_message_id');
    }
}

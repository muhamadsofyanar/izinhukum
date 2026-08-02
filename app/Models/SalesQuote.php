<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesQuote extends Model
{
    public const STATUSES = [
        'draft' => 'Draf',
        'sent' => 'Terkirim',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
        'cancelled' => 'Dibatalkan',
    ];

    protected $fillable = [
        'quote_number',
        'public_token',
        'created_by',
        'inquiry_id',
        'crm_lead_id',
        'sales_quote_template_id',
        'service_order_id',
        'invoice_id',
        'referred_by_partner_id',
        'coupon_id',
        'referral_code',
        'coupon_code',
        'recipient_name',
        'recipient_company',
        'recipient_email',
        'recipient_phone',
        'recipient_address',
        'issue_date',
        'valid_until',
        'invoice_due_days',
        'status',
        'subtotal',
        'discount',
        'total',
        'scope',
        'terms',
        'notes',
        'sent_at',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'valid_until' => 'date',
            'invoice_due_days' => 'integer',
            'subtotal' => 'integer',
            'discount' => 'integer',
            'total' => 'integer',
            'sent_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesQuoteItem::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function crmLead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SalesQuoteTemplate::class, 'sales_quote_template_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function referredByPartner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_partner_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function statusLabel(): string
    {
        if ($this->status === 'sent' && $this->isExpired()) {
            return 'Kedaluwarsa';
        }

        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function isExpired(): bool
    {
        return $this->valid_until?->copy()->endOfDay()->isPast() ?? false;
    }

    public function formattedTotal(): string
    {
        return 'Rp'.number_format($this->total, 0, ',', '.');
    }
}

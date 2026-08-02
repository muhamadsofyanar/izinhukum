<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends Model
{
    public const STATUSES = [
        'lead' => 'Lead baru',
        'waiting_approval' => 'Menunggu persetujuan',
        'awaiting_payment' => 'Menunggu pembayaran',
        'document_collection' => 'Pengumpulan dokumen',
        'processing' => 'Sedang diproses',
        'waiting_customer' => 'Menunggu pelanggan',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];

    public const PRIORITIES = [
        'low' => 'Rendah',
        'normal' => 'Normal',
        'high' => 'Tinggi',
        'urgent' => 'Mendesak',
    ];

    protected $fillable = [
        'order_number',
        'public_token',
        'inquiry_id',
        'service_package_id',
        'referred_by_partner_id',
        'partner_referral_id',
        'coupon_id',
        'assigned_to',
        'created_by',
        'referral_code',
        'coupon_code',
        'coupon_discount_type',
        'coupon_discount_value',
        'coupon_discount_amount',
        'title',
        'customer_name',
        'customer_email',
        'customer_phone',
        'customer_company',
        'customer_city',
        'customer_address',
        'status',
        'payment_status',
        'priority',
        'progress',
        'description',
        'internal_notes',
        'checklist',
        'started_at',
        'due_at',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'progress' => 'integer',
            'coupon_discount_value' => 'integer',
            'coupon_discount_amount' => 'integer',
            'started_at' => 'datetime',
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    public function referredByPartner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_partner_id');
    }

    public function referral(): BelongsTo
    {
        return $this->belongsTo(PartnerReferral::class, 'partner_referral_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ServiceOrderEvent::class)->orderByDesc('occurred_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ServiceOrderDocument::class)->latest();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['completed', 'cancelled']);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function priorityLabel(): string
    {
        return self::PRIORITIES[$this->priority] ?? ucfirst($this->priority);
    }

    public function paymentStatusLabel(): string
    {
        return match ($this->payment_status) {
            'paid' => 'Lunas',
            'partial' => 'Dibayar sebagian',
            default => 'Belum dibayar',
        };
    }

    public function isOverdue(): bool
    {
        return $this->due_at !== null
            && $this->due_at->isPast()
            && ! in_array($this->status, ['completed', 'cancelled'], true);
    }
}

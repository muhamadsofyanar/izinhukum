<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Inquiry extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'service_package_id',
        'referred_by_partner_id',
        'partner_referral_id',
        'coupon_id',
        'name',
        'phone',
        'email',
        'company_name',
        'city',
        'message',
        'source',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'landing_path',
        'referral_code',
        'coupon_code',
        'coupon_discount_type',
        'coupon_discount_value',
        'coupon_discount_amount',
        'referred_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'referred_at' => 'datetime',
            'coupon_discount_value' => 'integer',
            'coupon_discount_amount' => 'integer',
        ];
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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function serviceOrder(): HasOne
    {
        return $this->hasOne(ServiceOrder::class);
    }

    public function crmLead(): HasOne
    {
        return $this->hasOne(CrmLead::class);
    }

    public function salesQuotes(): HasMany
    {
        return $this->hasMany(SalesQuote::class);
    }
}

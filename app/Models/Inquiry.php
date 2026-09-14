<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inquiry extends Model
{
    use HasFactory, SoftDeletes;

    public const DISPOSITION_NEW = 'new';
    public const DISPOSITION_CONTACTED = 'contacted';
    public const DISPOSITION_QUALIFIED = 'qualified';
    public const DISPOSITION_PROPOSAL_SENT = 'proposal_sent';
    public const DISPOSITION_WON = 'won';
    public const DISPOSITION_LOST = 'lost';
    public const DISPOSITION_DUPLICATE = 'duplicate';
    public const DISPOSITION_SPAM = 'spam';

    public const DISPOSITIONS = [
        self::DISPOSITION_NEW => 'Lead baru',
        self::DISPOSITION_CONTACTED => 'Sudah dihubungi',
        self::DISPOSITION_QUALIFIED => 'Terverifikasi',
        self::DISPOSITION_PROPOSAL_SENT => 'Penawaran dikirim',
        self::DISPOSITION_WON => 'Menjadi proyek',
        self::DISPOSITION_LOST => 'Tidak lanjut',
        self::DISPOSITION_DUPLICATE => 'Duplikat',
        self::DISPOSITION_SPAM => 'Spam',
    ];

    protected $fillable = [
        'reference',
        'service_package_id',
        'referred_by_partner_id',
        'partner_referral_id',
        'coupon_id',
        'name',
        'phone',
        'phone_normalized',
        'email',
        'company_name',
        'city',
        'message',
        'source',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'marketing_campaign_id',
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
        'disposition',
        'spam_score',
        'spam_reasons',
        'review_note',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'referred_at' => 'datetime',
            'coupon_discount_value' => 'integer',
            'coupon_discount_amount' => 'integer',
            'spam_score' => 'integer',
            'spam_reasons' => 'array',
            'reviewed_at' => 'datetime',
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

    public function marketingCampaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class);
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isQuarantined(): bool
    {
        return in_array($this->disposition, [self::DISPOSITION_DUPLICATE, self::DISPOSITION_SPAM], true);
    }

    public function canConvertToProject(): bool
    {
        return ! $this->trashed()
            && ! $this->serviceOrder()->exists()
            && $this->disposition === self::DISPOSITION_QUALIFIED;
    }

    public function dispositionLabel(): string
    {
        return self::DISPOSITIONS[$this->disposition] ?? $this->disposition;
    }
}

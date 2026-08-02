<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'name',
        'tagline',
        'price',
        'minimum_end_user_price',
        'partner_price',
        'original_price',
        'price_suffix',
        'features',
        'is_estimated',
        'is_popular',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'minimum_end_user_price' => 'integer',
            'partner_price' => 'integer',
            'original_price' => 'integer',
            'features' => 'array',
            'is_estimated' => 'boolean',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function formattedPrice(): string
    {
        if ($this->price === 0 && $this->is_estimated) {
            return 'Hubungi kami';
        }

        return 'Rp'.number_format($this->price, 0, ',', '.');
    }

    public function formattedMinimumPrice(): string
    {
        return 'Rp'.number_format($this->minimum_end_user_price ?? 0, 0, ',', '.');
    }

    public function formattedPartnerPrice(): string
    {
        return 'Rp'.number_format($this->partner_price ?? 0, 0, ',', '.');
    }
}

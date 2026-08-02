<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'created_by',
        'name',
        'code',
        'description',
        'discount_type',
        'discount_value',
        'maximum_discount',
        'minimum_subtotal',
        'starts_at',
        'ends_at',
        'maximum_redemptions',
        'applies_to_all_services',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'integer',
            'maximum_discount' => 'integer',
            'minimum_subtotal' => 'integer',
            'maximum_redemptions' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'applies_to_all_services' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function scopeCurrentlyActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $builder) => $builder->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $builder) => $builder->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }

    public function discountLabel(): string
    {
        if ($this->discount_type === 'percentage') {
            return $this->discount_value.'%';
        }

        return 'Rp'.number_format($this->discount_value, 0, ',', '.');
    }
}

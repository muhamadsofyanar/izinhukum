<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'short_name',
        'category',
        'summary',
        'description',
        'landing_eyebrow',
        'landing_headline',
        'landing_subheadline',
        'landing_benefits',
        'landing_process',
        'landing_faqs',
        'seo_title',
        'seo_description',
        'requirements',
        'icon',
        'is_featured',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'requirements' => 'array',
            'landing_benefits' => 'array',
            'landing_process' => 'array',
            'landing_faqs' => 'array',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ServicePackage::class)->orderBy('sort_order');
    }

    public function coupons(): BelongsToMany
    {
        return $this->belongsToMany(Coupon::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

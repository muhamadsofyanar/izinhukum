<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingCampaign extends Model
{
    public const STATUSES = [
        'draft' => 'Draf',
        'active' => 'Aktif',
        'paused' => 'Dijeda',
        'completed' => 'Selesai',
        'archived' => 'Arsip',
    ];

    protected $fillable = [
        'name', 'slug', 'service_id', 'source', 'medium', 'landing_headline',
        'landing_subheadline', 'cta_text', 'is_landing_enabled', 'landing_views',
        'start_date', 'end_date', 'budget', 'spend', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date',
            'budget' => 'integer', 'spend' => 'integer',
            'is_landing_enabled' => 'boolean', 'landing_views' => 'integer',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function isLandingLive(): bool
    {
        return $this->is_landing_enabled
            && $this->status === 'active'
            && (! $this->start_date || $this->start_date->copy()->startOfDay()->lte(now()))
            && (! $this->end_date || $this->end_date->copy()->endOfDay()->gte(now()));
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

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
        'name', 'slug', 'source', 'medium', 'start_date', 'end_date',
        'budget', 'spend', 'status', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date', 'end_date' => 'date',
            'budget' => 'integer', 'spend' => 'integer',
        ];
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
}

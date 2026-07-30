<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmRequirement extends Model
{
    public const STATUSES = [
        'not_requested' => 'Belum diminta',
        'requested' => 'Sudah diminta',
        'received' => 'Sudah diterima',
        'needs_revision' => 'Perlu revisi',
        'verified' => 'Sudah valid',
    ];

    protected $table = 'crm_requirements';

    protected $fillable = [
        'contact_id', 'lead_id', 'service_order_id', 'template_item_id', 'name',
        'status', 'notes', 'requested_at', 'received_at', 'verified_at', 'verified_by',
    ];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'received_at' => 'datetime', 'verified_at' => 'datetime'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CrmDocument::class, 'requirement_id');
    }
}

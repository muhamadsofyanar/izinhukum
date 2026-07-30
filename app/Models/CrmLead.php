<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmLead extends Model
{
    public const STAGES = [
        'new' => 'Lead baru',
        'questioning' => 'Bertanya',
        'qualified' => 'Terkualifikasi',
        'proposal' => 'Penawaran',
        'deal' => 'Deal',
        'waiting_requirements' => 'Menunggu persyaratan',
        'processing' => 'Diproses',
        'completed' => 'Selesai',
        'lost' => 'Tidak lanjut',
    ];

    protected $table = 'crm_leads';

    protected $fillable = [
        'contact_id', 'inquiry_id', 'service_order_id', 'title', 'source', 'stage',
        'service_interest', 'estimated_value', 'probability', 'assigned_to',
        'next_follow_up_at', 'closed_at', 'lost_reason', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'probability' => 'integer',
            'next_follow_up_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CrmContact::class, 'contact_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmActivity::class, 'lead_id')->latest();
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(CrmRequirement::class, 'lead_id')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CrmDocument::class, 'lead_id')->latest();
    }

    public function stageLabel(): string
    {
        return self::STAGES[$this->stage] ?? ucfirst(str_replace('_', ' ', $this->stage));
    }
}

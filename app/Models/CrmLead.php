<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmLead extends Model
{
    public const TEMPERATURES = [
        'hot' => 'Panas',
        'warm' => 'Hangat',
        'cold' => 'Dingin',
    ];

    public const LOSS_REASONS = [
        'price' => 'Harga/budget',
        'timing' => 'Belum waktunya',
        'no_response' => 'Tidak merespons',
        'competitor' => 'Memilih penyedia lain',
        'requirements' => 'Persyaratan belum siap',
        'cancelled_plan' => 'Rencana dibatalkan',
        'outside_scope' => 'Di luar layanan',
        'other' => 'Alasan lainnya',
    ];

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
        'lead_score', 'temperature', 'next_follow_up_at', 'last_stage_changed_at',
        'first_contacted_at', 'response_minutes', 'closed_at', 'lost_reason',
        'loss_reason_code', 'reactivate_at', 'last_quote_at', 'won_at', 'notes', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'probability' => 'integer',
            'lead_score' => 'integer',
            'next_follow_up_at' => 'datetime',
            'last_stage_changed_at' => 'datetime',
            'first_contacted_at' => 'datetime',
            'response_minutes' => 'integer',
            'closed_at' => 'datetime',
            'reactivate_at' => 'datetime',
            'last_quote_at' => 'datetime',
            'won_at' => 'datetime',
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

    public function salesQuotes(): HasMany
    {
        return $this->hasMany(SalesQuote::class);
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

    public function temperatureLabel(): string
    {
        return self::TEMPERATURES[$this->temperature] ?? ucfirst($this->temperature);
    }

    public function lossReasonLabel(): ?string
    {
        return $this->loss_reason_code
            ? (self::LOSS_REASONS[$this->loss_reason_code] ?? $this->loss_reason_code)
            : null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentProof extends Model
{
    public const STATUSES = [
        'pending' => 'Menunggu pemeriksaan',
        'approved' => 'Disetujui',
        'rejected' => 'Ditolak',
    ];

    protected $fillable = [
        'invoice_id',
        'payment_id',
        'reviewed_by',
        'status',
        'payer_name',
        'transfer_date',
        'claimed_amount',
        'bank_reference',
        'notes',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'checksum',
        'review_note',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'transfer_date' => 'date',
            'claimed_amount' => 'integer',
            'size' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesMessageTemplate extends Model
{
    public const PURPOSES = [
        'first_response' => 'Respons awal',
        'follow_up' => 'Follow-up',
        'quote' => 'Kirim penawaran',
        'quote_reminder' => 'Pengingat penawaran',
        'payment' => 'Pengingat pembayaran',
        'reactivation' => 'Aktivasi ulang',
        'general' => 'Umum',
    ];

    protected $fillable = [
        'name', 'purpose', 'stage', 'body', 'is_active', 'sort_order', 'created_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purposeLabel(): string
    {
        return self::PURPOSES[$this->purpose] ?? ucfirst(str_replace('_', ' ', $this->purpose));
    }
}

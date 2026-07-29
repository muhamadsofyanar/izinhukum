<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'public_token',
        'created_by',
        'partner_id',
        'recipient_type',
        'recipient_name',
        'recipient_company',
        'recipient_email',
        'recipient_phone',
        'recipient_address',
        'issue_date',
        'due_date',
        'status',
        'subtotal',
        'discount',
        'tax',
        'total',
        'notes',
        'sent_at',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function amountPaid(): int
    {
        if ($this->relationLoaded('payments')) {
            return (int) $this->payments->sum('amount');
        }

        return (int) $this->payments()->sum('amount');
    }

    public function remainingAmount(): int
    {
        return max(0, (int) $this->total - $this->amountPaid());
    }

    public function formattedTotal(): string
    {
        return 'Rp'.number_format($this->total, 0, ',', '.');
    }
}

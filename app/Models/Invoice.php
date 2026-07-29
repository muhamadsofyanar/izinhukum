<?php

namespace App\Models;

use App\Services\ReferralEventService;
use App\Services\ServiceOrderService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'public_token',
        'created_by',
        'inquiry_id',
        'service_order_id',
        'partner_id',
        'referred_by_partner_id',
        'referral_code',
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
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invoice $invoice): void {
            if (
                ! $invoice->service_order_id
                && $invoice->inquiry_id
                && Schema::hasTable('service_orders')
                && Schema::hasColumn('invoices', 'service_order_id')
            ) {
                $invoice->service_order_id = ServiceOrder::query()
                    ->where('inquiry_id', $invoice->inquiry_id)
                    ->value('id');
            }
        });

        static::saved(function (Invoice $invoice): void {
            if ($invoice->service_order_id && Schema::hasTable('service_orders')) {
                $order = ServiceOrder::query()->find($invoice->service_order_id);
                if ($order) {
                    app(ServiceOrderService::class)->syncPaymentStatus($order);
                }
            }

            app(ReferralEventService::class)->recordInvoice($invoice);
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function referredByPartner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_partner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function amountPaid(): int
    {
        if ($this->relationLoaded('payments')) {
            return (int) $this->payments->where('status', 'active')->sum('amount');
        }

        return (int) $this->payments()->active()->sum('amount');
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

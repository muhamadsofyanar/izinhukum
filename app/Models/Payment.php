<?php

namespace App\Models;

use App\Services\ReferralEventService;
use App\Services\ServiceOrderService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Schema;

class Payment extends Model
{
    protected $fillable = [
        'invoice_id',
        'created_by',
        'financial_category_id',
        'receipt_number',
        'public_token',
        'status',
        'source',
        'source_key',
        'payment_date',
        'amount',
        'payer_name',
        'description',
        'payment_method',
        'reference_number',
        'notes',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
        'last_edited_at',
        'last_edited_by',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'integer',
            'cancelled_at' => 'datetime',
            'last_edited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Payment $payment): void {
            if (! Schema::hasTable('service_orders')) {
                return;
            }

            $payment->loadMissing('invoice');
            if ($payment->invoice?->service_order_id) {
                $order = ServiceOrder::query()->find($payment->invoice->service_order_id);
                if ($order) {
                    app(ServiceOrderService::class)->syncPaymentStatus($order);
                }
            }

            app(ReferralEventService::class)->recordPayment($payment);
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinancialCategory::class, 'financial_category_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lastEditedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function formattedAmount(): string
    {
        return 'Rp'.number_format($this->amount, 0, ',', '.');
    }
}

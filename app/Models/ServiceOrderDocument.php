<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderDocument extends Model
{
    protected $fillable = [
        'service_order_id',
        'uploaded_by',
        'uploaded_by_type',
        'category',
        'name',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class, 'service_order_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

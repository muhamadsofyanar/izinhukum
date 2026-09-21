<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentItem extends Model
{
    protected $fillable = ['created_by', 'service_id', 'title', 'category', 'body', 'media_path', 'status', 'scheduled_at'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function service(): BelongsTo { return $this->belongsTo(Service::class); }
}

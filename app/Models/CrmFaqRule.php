<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmFaqRule extends Model
{
    protected $table = 'crm_faq_rules';

    protected $fillable = [
        'name', 'keyword', 'match_type', 'answer', 'template_id', 'priority',
        'is_active', 'handoff_after_reply', 'created_by',
    ];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'is_active' => 'boolean', 'handoff_after_reply' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSequenceStep extends Model
{
    protected $table = 'crm_sequence_steps';

    protected $fillable = [
        'sequence_id', 'position', 'name', 'delay_value', 'delay_unit', 'send_time',
        'template_id', 'crm_document_id', 'message_type', 'body', 'media_url', 'metadata',
    ];

    protected function casts(): array
    {
        return ['position' => 'integer', 'delay_value' => 'integer', 'metadata' => 'array'];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(CrmSequence::class, 'sequence_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WhatsAppTemplate::class, 'template_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CrmDocument::class, 'crm_document_id');
    }
}

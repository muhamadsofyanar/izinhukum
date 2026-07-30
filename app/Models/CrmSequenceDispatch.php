<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmSequenceDispatch extends Model
{
    protected $table = 'crm_sequence_dispatches';

    protected $fillable = [
        'enrollment_id', 'step_id', 'whatsapp_message_id', 'status',
        'scheduled_at', 'dispatched_at', 'error',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'dispatched_at' => 'datetime'];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CrmSequenceEnrollment::class, 'enrollment_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(CrmSequenceStep::class, 'step_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }
}

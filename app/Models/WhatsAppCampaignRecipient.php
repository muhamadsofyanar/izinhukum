<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppCampaignRecipient extends Model
{
    protected $table = 'whatsapp_campaign_recipients';

    protected $fillable = [
        'whatsapp_campaign_id', 'whatsapp_message_id', 'phone', 'name', 'status', 'error', 'variables',
        'channel', 'contact_id', 'group_id', 'exclusion_reason', 'retry_generation',
    ];

    protected function casts(): array
    {
        return ['variables' => 'array', 'retry_generation' => 'integer'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'whatsapp_campaign_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMessage::class, 'whatsapp_message_id');
    }

    public function contact(): BelongsTo { return $this->belongsTo(CrmContact::class, 'contact_id'); }
    public function group(): BelongsTo { return $this->belongsTo(WhatsAppGroup::class, 'group_id'); }
}

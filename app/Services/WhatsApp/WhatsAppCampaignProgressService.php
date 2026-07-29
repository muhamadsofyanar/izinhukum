<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppCampaign;

class WhatsAppCampaignProgressService
{
    public function refresh(WhatsAppCampaign $campaign): WhatsAppCampaign
    {
        $campaign->refresh();
        $activeStatuses = ['pending', 'processing', 'queued'];
        $hasActive = $campaign->recipients()->whereIn('status', $activeStatuses)->exists();

        $cancelled = $campaign->status === 'cancelled';

        $campaign->forceFill([
            'recipient_count' => $campaign->recipients()->count(),
            'queued_count' => $campaign->recipients()->whereIn('status', $activeStatuses)->count(),
            'sent_count' => $campaign->recipients()->where('status', 'sent')->count(),
            'failed_count' => $campaign->recipients()->where('status', 'failed')->count(),
            'status' => $cancelled ? 'cancelled' : ($hasActive ? 'running' : 'completed'),
            'completed_at' => $cancelled
                ? ($campaign->completed_at ?: now())
                : ($hasActive ? null : ($campaign->completed_at ?: now())),
        ])->save();

        return $campaign->refresh();
    }
}

<?php

namespace App\Services;

use App\Models\CrmLead;
use App\Models\Inquiry;

class LeadScoringService
{
    public function scoreInquiry(Inquiry $inquiry): int
    {
        $inquiry->loadMissing(['package', 'referredByPartner']);
        $score = 15;
        $score += filled($inquiry->email) ? 10 : 0;
        $score += filled($inquiry->company_name) ? 10 : 0;
        $score += $inquiry->service_package_id ? 20 : 0;
        $score += (int) ($inquiry->package?->price ?? 0) > 0 ? 15 : 0;
        $score += filled($inquiry->message) && mb_strlen((string) $inquiry->message) >= 40 ? 10 : 0;
        $score += $inquiry->referred_by_partner_id ? 10 : 0;
        $score += filled($inquiry->coupon_code) ? 5 : 0;
        $score += filled($inquiry->utm_campaign) ? 5 : 0;

        return min(100, $score);
    }

    public function scoreLead(CrmLead $lead): int
    {
        $lead->loadMissing(['contact', 'inquiry.package']);
        if ($lead->inquiry) {
            return $this->scoreInquiry($lead->inquiry);
        }

        $score = 15;
        $score += filled($lead->contact?->email) ? 10 : 0;
        $score += filled($lead->contact?->company) ? 10 : 0;
        $score += filled($lead->service_interest) ? 20 : 0;
        $score += (float) $lead->estimated_value > 0 ? 20 : 0;
        $score += filled($lead->notes) && mb_strlen((string) $lead->notes) >= 40 ? 10 : 0;
        $score += in_array($lead->source, ['referral', 'website', 'service_landing', 'ads'], true) ? 5 : 0;

        return min(100, $score);
    }

    public function temperature(int $score): string
    {
        return $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold');
    }

    public function refresh(CrmLead $lead): CrmLead
    {
        $score = $this->scoreLead($lead);
        $lead->forceFill([
            'lead_score' => $score,
            'temperature' => $this->temperature($score),
        ])->save();

        return $lead->fresh();
    }
}

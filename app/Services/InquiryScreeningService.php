<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Services\WhatsApp\PhoneNumberNormalizer;

class InquiryScreeningService
{
    public function __construct(private readonly PhoneNumberNormalizer $phoneNumbers) {}

    /** @return array{phone_normalized: string, disposition: string, spam_score: int, spam_reasons: array<int, string>} */
    public function screen(array $data): array
    {
        $phone = (string) $this->phoneNumbers->normalize($data['phone'] ?? null);
        $score = 0;
        $reasons = [];

        if (trim((string) ($data['website'] ?? '')) !== '') {
            $score += 100;
            $reasons[] = 'honeypot_filled';
        }

        $startedAt = (int) ($data['form_started_at'] ?? 0);
        if ($startedAt > 0 && now()->timestamp - $startedAt < 3) {
            $score += 40;
            $reasons[] = 'submitted_too_fast';
        }

        $content = mb_strtolower(implode(' ', array_filter([
            $data['name'] ?? null,
            $data['company_name'] ?? null,
            $data['message'] ?? null,
        ])));
        if (preg_match('/https?:\/\/|\b(?:casino|slot|judi|pinjaman online|crypto giveaway)\b/u', $content)) {
            $score += 45;
            $reasons[] = 'suspicious_content';
        }

        $duplicate = Inquiry::query()
            ->where('phone_normalized', $phone)
            ->where('service_package_id', $data['service_package_id'] ?? null)
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if ($duplicate) {
            $score += 70;
            $reasons[] = 'duplicate_phone_service_24h';
        }

        $disposition = $duplicate
            ? Inquiry::DISPOSITION_DUPLICATE
            : ($score >= 80 ? Inquiry::DISPOSITION_SPAM : Inquiry::DISPOSITION_NEW);

        return [
            'phone_normalized' => $phone,
            'disposition' => $disposition,
            'spam_score' => min(100, $score),
            'spam_reasons' => $reasons,
        ];
    }
}

<?php

namespace App\Services\Crm;

use App\Models\CrmFaqRule;
use App\Models\WhatsAppConversation;
use App\Services\FeatureFlagService;
use App\Services\WhatsApp\WhatsAppManager;
use Illuminate\Support\Str;

class CrmFaqService
{
    public function __construct(
        private readonly FeatureFlagService $features,
        private readonly WhatsAppManager $messages,
    ) {
    }

    public function reply(string $phone, string $body, ?WhatsAppConversation $conversation = null): bool
    {
        if (! $this->features->enabled('crm_faq') || trim($body) === '') {
            return false;
        }

        $normalized = Str::lower(trim($body));
        $rule = CrmFaqRule::query()
            ->with('template')
            ->where('is_active', true)
            ->orderBy('priority')
            ->get()
            ->first(fn (CrmFaqRule $candidate): bool => $this->matches($candidate, $normalized));

        if (! $rule) {
            return false;
        }

        $message = $rule->template
            ? $this->messages->queueTemplate(
                $rule->template->key,
                $phone,
                $conversation?->display_name,
                ['nama_pelanggan' => $conversation?->display_name ?: 'Bapak/Ibu'],
                ['conversation_id' => $conversation?->id, 'contact_id' => $conversation?->contact_id],
                'crm-faq:'.$rule->id.':'.$phone.':'.now()->format('YmdHi'),
                null,
                'support',
            )
            : $this->messages->queueRaw([
                'phone' => $phone,
                'recipient_name' => $conversation?->display_name,
                'conversation_id' => $conversation?->id,
                'contact_id' => $conversation?->contact_id,
                'body' => $rule->answer,
                'device_alias' => 'support',
                'idempotency_key' => 'crm-faq:'.$rule->id.':'.$phone.':'.now()->format('YmdHi'),
                'metadata' => ['source' => 'crm_faq', 'faq_rule_id' => $rule->id],
            ]);

        if ($message && $rule->handoff_after_reply && $conversation) {
            $conversation->forceFill(['status' => 'pending', 'is_ai_blocked' => true])->save();
        }

        return $message !== null;
    }

    private function matches(CrmFaqRule $rule, string $body): bool
    {
        $keyword = Str::lower(trim($rule->keyword));
        if ($keyword === '') {
            return false;
        }

        return match ($rule->match_type) {
            'exact' => $body === $keyword,
            'regex' => @preg_match($rule->keyword, $body) === 1,
            default => Str::contains($body, $keyword),
        };
    }
}

<?php

namespace App\Services;

use App\Models\PartnerReferral;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

class PartnerReferralService
{
    private const CODE_COOKIE = 'ih_partner_ref';

    private const VISITOR_COOKIE = 'ih_partner_visitor';

    public function capture(Request $request): ?PartnerReferral
    {
        $rawCode = $request->query('ref');
        if (! is_string($rawCode) || trim($rawCode) === '') {
            return null;
        }

        $code = Str::upper(trim($rawCode));
        $partner = $this->activePartner($code);
        if (! $partner) {
            return null;
        }

        $visitorToken = (string) $request->cookie(self::VISITOR_COOKIE);
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $visitorToken)) {
            $visitorToken = Str::random(40);
        }

        $path = Str::limit('/'.ltrim($request->getRequestUri(), '/'), 2048, '');
        $referral = PartnerReferral::query()->firstOrCreate(
            [
                'partner_id' => $partner->id,
                'visitor_token' => $visitorToken,
            ],
            [
                'referral_code' => $partner->partner_code,
                'first_landing_path' => $path,
                'last_landing_path' => $path,
                'click_count' => 0,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ],
        );

        $referral->increment('click_count');
        $referral->update([
            'referral_code' => $partner->partner_code,
            'last_landing_path' => $path,
            'last_seen_at' => now(),
        ]);

        $request->session()->put([
            'partner_referral_id' => $referral->id,
            'partner_referral_code' => $partner->partner_code,
            'partner_referral_visitor' => $visitorToken,
        ]);

        $minutes = max(1, (int) config('partner.attribution_days', 30) * 24 * 60);
        Cookie::queue(cookie(
            self::CODE_COOKIE,
            $partner->partner_code,
            $minutes,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));
        Cookie::queue(cookie(
            self::VISITOR_COOKIE,
            $visitorToken,
            $minutes,
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'lax',
        ));

        return $referral->fresh();
    }

    public function attribution(Request $request): ?array
    {
        $code = (string) (
            $request->session()->get('partner_referral_code')
            ?: $request->cookie(self::CODE_COOKIE)
        );
        $partner = $this->activePartner(Str::upper(trim($code)));

        if (! $partner) {
            return null;
        }

        $visitorToken = (string) (
            $request->session()->get('partner_referral_visitor')
            ?: $request->cookie(self::VISITOR_COOKIE)
        );
        $referral = null;

        if (preg_match('/^[A-Za-z0-9]{40}$/', $visitorToken)) {
            $referral = PartnerReferral::query()
                ->where('partner_id', $partner->id)
                ->where('visitor_token', $visitorToken)
                ->first();
        }

        return [
            'referred_by_partner_id' => $partner->id,
            'partner_referral_id' => $referral?->id,
            'referral_code' => $partner->partner_code,
            'referred_at' => now(),
        ];
    }

    private function activePartner(string $code): ?User
    {
        if ($code === '') {
            return null;
        }

        return User::query()
            ->where('role', 'partner')
            ->where('partner_code', $code)
            ->where('is_active', true)
            ->where('account_status', 'active')
            ->first();
    }
}


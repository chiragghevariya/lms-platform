<?php

namespace App\Affiliates;

use App\Models\Affiliate;
use App\Models\Referral;
use App\Models\ReferralClick;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/** Clicks (?ref / ?invite) and attribution of new sign-ups. */
class ReferralTracker
{
    public function __construct(private readonly FraudGuard $fraud) {}

    /**
     * Records an affiliate click and returns the cookie to set (null when the code is unknown/inactive
     * or first-touch attribution keeps an earlier affiliate).
     *
     * @param  array{landing_url?: string|null, referrer?: string|null, utm?: array<string, string>|null, visitor_id?: string|null}  $ctx
     */
    public function click(string $code, array $ctx): ?Cookie
    {
        $affiliate = Affiliate::where('code', strtoupper($code))->where('status', 'approved')->first();
        if (! $affiliate) {
            return null;
        }
        $existing = ReferralCookie::read(ReferralCookie::AFFILIATE);
        $click = ReferralClick::create([
            'affiliate_id' => $affiliate->id,
            'visitor_id' => $ctx['visitor_id'] ?? null,
            'ip_hash' => (string) Identity::ipFromRequest(),
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''),
            'landing_url' => isset($ctx['landing_url']) ? Str::limit($ctx['landing_url'], 1000, '') : null,
            'referrer' => isset($ctx['referrer']) ? Str::limit($ctx['referrer'], 1000, '') : null,
            'utm' => $ctx['utm'] ?? null,
        ]);
        if ($existing && config('affiliates.attribution') === 'first_touch' && ($existing['c'] ?? null) !== $affiliate->code) {
            return null; // first touch wins; the click is still counted for the newer affiliate's funnel
        }

        return ReferralCookie::make(ReferralCookie::AFFILIATE, ['c' => $affiliate->code, 'k' => $click->id, 't' => now()->getTimestamp()]);
    }

    public function invite(string $code): ?Cookie
    {
        if (! config('affiliates.student_rewards.enabled') || ! User::where('invite_code', strtoupper($code))->exists()) {
            return null;
        }

        return ReferralCookie::make(ReferralCookie::INVITE, ['c' => strtoupper($code), 't' => now()->getTimestamp()]);
    }

    /** Called once for every new account (form, Google, mobile). Affiliate attribution wins over a student invite. */
    public function attributeSignup(User $user): ?Referral
    {
        $cookie = ReferralCookie::read(ReferralCookie::AFFILIATE);
        $code = $cookie['c'] ?? request()->input('ref');
        $referral = is_string($code) && $code !== '' ? $this->attributeAffiliate($user, $code, isset($cookie['k']) ? (int) $cookie['k'] : null) : null;

        if (! $referral || $referral->status === 'rejected') {
            $invite = ReferralCookie::read(ReferralCookie::INVITE)['c'] ?? request()->input('invite');
            if (is_string($invite) && $invite !== '') {
                $this->attributeInvite($user, $invite);
            }
        }

        return $referral;
    }

    private function attributeAffiliate(User $user, string $code, ?int $clickId): ?Referral
    {
        $affiliate = Affiliate::with('user')->where('code', strtoupper($code))->where('status', 'approved')->first();
        if (! $affiliate || Referral::where('referred_user_id', $user->id)->exists()) {
            return null;
        }
        $ip = Identity::ipFromRequest();
        $device = Identity::deviceFromRequest();
        $flags = $this->fraud->atSignup($affiliate, $user, $ip, $device);
        $click = $clickId ? ReferralClick::where('affiliate_id', $affiliate->id)->find($clickId) : null;

        return Referral::create([
            'affiliate_id' => $affiliate->id,
            'referred_user_id' => $user->id,
            'click_id' => $click?->id,
            'attribution' => (string) config('affiliates.attribution'),
            'status' => FraudGuard::blocks($flags) ? 'rejected' : ($flags ? 'flagged' : 'pending'),
            'flags' => $flags ?: null,
            'signup_ip_hash' => $ip,
            'signup_device_hash' => $device,
            'utm' => $click?->utm,
        ]);
    }

    private function attributeInvite(User $user, string $code): void
    {
        $referrer = User::where('invite_code', strtoupper($code))->first();
        if (! $referrer || ReferralReward::where('friend_id', $user->id)->exists()) {
            return;
        }
        $flags = [];
        if ($referrer->id === $user->id || Identity::canonicalEmail($referrer->email) === Identity::canonicalEmail($user->email)) {
            $flags[] = 'self_referral';
        }
        $device = Identity::deviceFromRequest();
        if ($device && in_array($device, Identity::userDeviceHashes($referrer, 90), true)) {
            $flags[] = 'same_device';
        }
        $ip = Identity::ipFromRequest();
        if ($ip && in_array($ip, Identity::userIpHashes($referrer, 90), true)) {
            $flags[] = 'same_ip';
        }

        ReferralReward::create([
            'referrer_id' => $referrer->id, 'friend_id' => $user->id,
            'type' => (string) config('affiliates.student_rewards.type'),
            'status' => $flags ? 'rejected' : 'invited',
            'flags' => $flags ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function utmFrom(array $query): array
    {
        return array_filter(Arr::only(array_map(fn ($v) => is_string($v) ? Str::limit($v, 100, '') : '', $query),
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']));
    }
}

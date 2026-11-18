<?php

namespace App\Affiliates;

use App\Enums\Gateway;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ReferralReward;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Support\Str;

/**
 * "Invite a friend": when the friend's first paid membership clears the hold period, the inviter gets
 * free days (complimentary access) — or, if they're already paying, the days are banked and applied
 * automatically when their paid plan ends. A `credit` reward is recorded as a balance only (see docs).
 */
class StudentReferrals
{
    public function __construct(private readonly MembershipService $membership) {}

    public function inviteCode(User $user): string
    {
        if (! $user->invite_code) {
            do {
                $code = 'F'.strtoupper(Str::random(7));
            } while (User::where('invite_code', $code)->exists());
            $user->forceFill(['invite_code' => $code])->save();
        }

        return (string) $user->invite_code;
    }

    public function onPayment(Payment $payment): void
    {
        if ($payment->status !== PaymentStatus::Succeeded || ! $payment->subscription_id || $payment->amount <= 0) {
            return;
        }
        $reward = ReferralReward::where('friend_id', $payment->user_id)->where('status', 'invited')->first();
        if (! $reward) {
            return;
        }
        $reward->forceFill([
            'status' => 'pending', 'payment_id' => $payment->id,
            'days' => $reward->type === 'days' ? (int) config('affiliates.student_rewards.days') : null,
            'credit_amount' => $reward->type === 'credit' ? (int) (config('affiliates.student_rewards.credit')[$payment->currency] ?? 0) : null,
            'currency' => $reward->type === 'credit' ? $payment->currency : null,
            'available_at' => ($payment->paid_at ?? now())->copy()->addDays((int) config('affiliates.student_rewards.hold_days', config('affiliates.hold_days'))),
        ])->save();
    }

    public function onRefund(Payment $payment): void
    {
        ReferralReward::where('payment_id', $payment->id)->where('status', 'pending')->update(['status' => 'reversed']);
    }

    public function release(): int
    {
        $n = 0;
        ReferralReward::with('referrer', 'friend')->where('status', 'pending')->where('available_at', '<=', now())->each(function (ReferralReward $r) use (&$n) {
            $payment = $r->payment_id ? Payment::find($r->payment_id) : null;
            if ($payment && ($payment->status === PaymentStatus::Refunded || $payment->disputed_at)) {
                $r->forceFill(['status' => 'reversed'])->save();

                return;
            }
            $thisYear = ReferralReward::where('referrer_id', $r->referrer_id)->whereIn('status', ['granted', 'banked'])
                ->where('updated_at', '>=', now()->subYear())->count();
            if ($thisYear >= (int) config('affiliates.student_rewards.max_per_year')) {
                $r->forceFill(['status' => 'rejected', 'flags' => ['yearly_limit']])->save();

                return;
            }
            $this->grant($r, $payment);
            $n++;
        });

        return $n;
    }

    private function grant(ReferralReward $r, ?Payment $payment): void
    {
        if ($r->type === 'credit') {
            $r->forceFill(['status' => 'granted', 'granted_at' => now()])->save();

            return;
        }
        $active = $r->referrer->activeSubscription();
        if ($active && $active->gateway !== Gateway::Manual) {
            $r->forceFill(['status' => 'banked'])->save(); // paying already — applied when the paid plan ends

            return;
        }
        $this->addDays($r->referrer, (int) $r->days, $payment);
        $r->forceFill(['status' => 'granted', 'granted_at' => now()])->save();
    }

    /** Extends a running complimentary membership, or starts one at the friend's plan tier. */
    public function addDays(User $user, int $days, ?Payment $basis = null): void
    {
        $comp = $user->activeSubscription();
        if ($comp && $comp->gateway === Gateway::Manual) {
            if ($comp->current_period_end) {
                $comp->forceFill(['current_period_end' => $comp->current_period_end->copy()->addDays($days)])->save();
            }

            return; // open-ended complimentary access: nothing to add
        }
        $planId = $basis?->subscription_id ? Subscription::whereKey($basis->subscription_id)->value('plan_id') : null;
        $plan = ($planId ? Plan::find($planId) : null) ?? Plan::orderBy('tier_level')->firstOrFail();
        $this->membership->grantComplimentary($user, $plan, now()->addDays($days), null, "Referral reward: {$days} free days");
    }

    /** SubscriptionEnded: turn banked rewards into free days now that the paid plan is over. */
    public function applyBanked(Subscription $ended): void
    {
        $user = $ended->user;
        if ($ended->gateway === Gateway::Manual || $user->activeSubscription()) {
            return;
        }
        $banked = ReferralReward::where('referrer_id', $user->id)->where('status', 'banked')->get();
        if ($banked->isEmpty()) {
            return;
        }
        $user->unsetRelation('liveSubscriptions');
        $this->membership->grantComplimentary($user, $ended->plan, now()->addDays((int) $banked->sum('days')), null,
            'Referral rewards: '.$banked->sum('days').' free days');
        ReferralReward::whereIn('id', $banked->pluck('id'))->update(['status' => 'granted', 'granted_at' => now()]);
    }
}

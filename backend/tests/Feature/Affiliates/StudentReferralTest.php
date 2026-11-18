<?php

use App\Affiliates\Identity;
use App\Affiliates\StudentReferrals;
use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Events\SubscriptionEnded;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->price = planFor(MembershipTier::Pro, BillingInterval::Month, 'INR', ['amount' => 99900]);
    $this->inviter = User::factory()->student()->create();
});

function inviteCookie(User $inviter): string
{
    $code = app(StudentReferrals::class)->inviteCode($inviter); // the friend opens the link logged out

    return test()->withCredentials()->postJson(api('referrals/track'), ['invite' => $code])->getCookie('lms_invite', false)->getValue();
}

it('gives the inviter 30 free days when a friend subscribes and the hold passes', function () {
    $friend = signUp('friend@example.com', inviteCookie: inviteCookie($this->inviter));
    expect(ReferralReward::first())->status->toBe('invited')->referrer_id->toBe($this->inviter->id);

    razorpayCharge($friend, $this->price, 'pay_friend');
    expect(ReferralReward::first())->status->toBe('pending')->days->toBe(30);

    $this->travel(15)->days();
    $this->artisan('affiliates:release');
    expect(ReferralReward::first()->status)->toBe('granted')
        ->and($this->inviter->fresh()->currentTier())->toBe(MembershipTier::Pro)
        ->and($this->inviter->fresh()->activeSubscription()->current_period_end->isSameDay(now()->addDays(30)))->toBeTrue();

    $this->actingAs($this->inviter)->getJson(api('me/invite'))->assertJsonPath('data.stats.days_earned', 30)
        ->assertJsonPath('data.rewards.0.friend', 'New');
});

it('banks the days for paying members and applies them when the paid plan ends', function () {
    $sub = subscribe($this->inviter, MembershipTier::Starter);
    $friend = signUp('friend@example.com', inviteCookie: inviteCookie($this->inviter));
    razorpayCharge($friend, $this->price, 'pay_friend');
    $this->travel(15)->days();
    $this->artisan('affiliates:release');
    expect(ReferralReward::first()->status)->toBe('banked');

    $sub->forceFill(['status' => 'expired', 'ended_at' => now()])->save();
    $this->inviter->unsetRelation('liveSubscriptions');
    event(new SubscriptionEnded($sub->fresh()));
    expect(ReferralReward::first()->status)->toBe('granted')
        ->and($this->inviter->fresh()->activeSubscription()?->gateway->value)->toBe('manual');
});

it('cancels the reward when the friend\'s payment is refunded during the hold', function () {
    $friend = signUp('friend@example.com', inviteCookie: inviteCookie($this->inviter));
    razorpayCharge($friend, $this->price, 'pay_friend');
    razorpayRefund('pay_friend', 99900, 99900, 99900);
    $this->travel(15)->days();
    $this->artisan('affiliates:release');
    expect(ReferralReward::first()->status)->toBe('reversed')->and($this->inviter->fresh()->currentTier())->toBeNull();
});

it('rejects inviting yourself and lets an affiliate referral take precedence', function () {
    signUp('other@example.com', inviteCookie: inviteCookie($this->inviter), device: 'same-device-123456');
    $this->inviter->loginActivities()->create(['email' => $this->inviter->email, 'event' => 'login_success', 'channel' => 'web', 'ip_address' => '1.1.1.1', 'device_hash' => Identity::hash('same-device-123456')]);
    signUp('again@example.com', inviteCookie: inviteCookie($this->inviter), device: 'same-device-123456');
    expect(ReferralReward::orderBy('id')->pluck('status')->all())->toBe(['invited', 'rejected']);

    $affiliate = makeAffiliate();
    signUp('both@example.com', clickRef($affiliate->code), inviteCookie($this->inviter));
    expect(ReferralReward::whereHas('friend', fn ($q) => $q->where('email', 'both@example.com'))->exists())->toBeFalse();
});

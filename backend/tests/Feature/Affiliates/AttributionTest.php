<?php

use App\Actions\Auth\ResolveGoogleUser;
use App\Affiliates\ReferralTracker;
use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Models\Commission;
use App\Models\Referral;
use App\Models\ReferralClick;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->price = planFor(MembershipTier::Pro, BillingInterval::Month, 'INR', ['amount' => 99900]);
    $this->a = makeAffiliate();
    $this->b = makeAffiliate();
});

it('uses last-touch by default: the newer click wins', function () {
    clickRef($this->a->code);
    $cookie = clickRef($this->b->code);
    signUp('n@example.com', $cookie);

    expect(Referral::first())->affiliate_id->toBe($this->b->id)->attribution->toBe('last_touch')
        ->and(ReferralClick::count())->toBe(2);
});

it('keeps the first affiliate under first-touch, but still counts the second click', function () {
    config(['affiliates.attribution' => 'first_touch']);
    $first = clickRef($this->a->code);
    $second = $this->withCredentials()->withUnencryptedCookie('lms_ref', $first)
        ->postJson(api('referrals/track'), ['ref' => $this->b->code])->getCookie('lms_ref', false);
    expect($second)->toBeNull(); // cookie not replaced

    signUp('n@example.com', $first);
    expect(Referral::first())->affiliate_id->toBe($this->a->id)->attribution->toBe('first_touch')
        ->and(ReferralClick::where('affiliate_id', $this->b->id)->count())->toBe(1);
});

it('expires the cookie after 60 days', function () {
    $cookie = clickRef($this->a->code);
    $this->travel(61)->days();
    signUp('late@example.com', $cookie);
    expect(Referral::count())->toBe(0);
});

it('rejects a tampered cookie', function () {
    $cookie = clickRef($this->a->code);
    [$body] = explode('.', $cookie);
    $forged = rtrim(strtr(base64_encode(json_encode(['c' => $this->b->code, 'k' => 1, 't' => time()])), '+/', '-_'), '=').'.'.substr($cookie, strlen($body) + 1);
    signUp('n@example.com', $forged);
    expect(Referral::count())->toBe(0);
});

it('ignores unknown, pending and suspended affiliates', function () {
    $pending = makeAffiliate(['status' => 'pending']);
    expect(clickRef('NOPE123'))->toBeNull()->and(clickRef($pending->code))->toBeNull();

    $cookie = clickRef($this->a->code);
    $this->a->forceFill(['status' => 'suspended'])->save();
    signUp('n@example.com', $cookie);
    expect(Referral::count())->toBe(0);
});

it('stops commissions when the affiliate is suspended after the referral', function () {
    $customer = signUp('n@example.com', clickRef($this->a->code));
    $this->a->forceFill(['status' => 'suspended'])->save();
    razorpayCharge($customer, $this->price, 'pay_1');
    expect(Commission::count())->toBe(0);
});

it('never re-attributes existing members and attributes a user only once', function () {
    $member = User::factory()->create();
    $this->actingAs($member)->withCredentials()->postJson(api('referrals/track'), ['ref' => $this->a->code])
        ->assertNoContent()->assertCookieMissing('lms_ref');
    app('auth')->forgetGuards();

    $user = signUp('n@example.com', clickRef($this->a->code));
    app(ReferralTracker::class)->attributeSignup($user); // e.g. a second Registered event
    expect(Referral::count())->toBe(1);
});

it('attributes mobile sign-ups that send the code in the body', function () {
    signUp('app@example.com', body: ['ref' => strtolower($this->a->code), 'device_name' => 'iPhone']);
    expect(Referral::first()->affiliate_id)->toBe($this->a->id)->and(Referral::first()->click_id)->toBeNull();
});

it('attributes Google sign-ups too', function () {
    $cookie = clickRef($this->a->code);
    $google = (new Laravel\Socialite\Two\User)->setRaw(['email_verified' => true])->map(['id' => 'google-123', 'name' => 'Gita', 'email' => 'g@example.com']);
    request()->cookies->set('lms_ref', $cookie); // the OAuth callback is a browser request carrying the cookie

    app(ResolveGoogleUser::class)->handle($google);
    expect(Referral::first()?->referred_user_id)->toBe(User::where('email', 'g@example.com')->value('id'));
});

it('pays a level-2 commission to the recruiter when enabled', function () {
    config(['affiliates.level2.enabled' => true, 'affiliates.level2.percent' => 5]);
    $recruiterUser = $this->a->user;
    // B's own account was referred by A (A recruited B).
    Referral::create(['affiliate_id' => $this->a->id, 'referred_user_id' => $this->b->user_id, 'attribution' => 'last_touch', 'status' => 'converted']);

    $customer = signUp('n@example.com', clickRef($this->b->code));
    razorpayCharge($customer, $this->price, 'pay_1');

    $rows = Commission::orderBy('level')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0])->affiliate_id->toBe($this->b->id)->level->toBe(1)->amount->toBe(16932)
        ->and($rows[1])->affiliate_id->toBe($this->a->id)->level->toBe(2)->amount->toBe(4233)->rate->toBe('5%');
    expect($recruiterUser->id)->toBe($this->a->user_id);
});

it('supports fixed commissions per currency and per-affiliate overrides', function () {
    $this->a->forceFill(['commission_type' => 'fixed', 'commission_fixed' => ['INR' => 25000]])->save();
    $customer = signUp('n@example.com', clickRef($this->a->code));
    razorpayCharge($customer, $this->price, 'pay_1');
    expect(Commission::first())->amount->toBe(25000)->rate->toBe('fixed');

    $this->b->forceFill(['tier' => 'partner'])->save();
    $c2 = signUp('m@example.com', clickRef($this->b->code));
    razorpayCharge($c2, $this->price, 'pay_2');
    expect(Commission::where('affiliate_id', $this->b->id)->value('amount'))->toBe(25398); // 30 % of 84661
});

it('pays only the first payment when recurring months is 0, and includes course sales', function () {
    config(['affiliates.recurring_months' => 0]);
    $customer = signUp('n@example.com', clickRef($this->a->code));
    razorpayCharge($customer, $this->price, 'pay_1');
    $this->travel(1)->month();
    razorpayCharge($customer, $this->price, 'pay_2', ['paid_count' => 2]);
    expect(Commission::count())->toBe(1);
});

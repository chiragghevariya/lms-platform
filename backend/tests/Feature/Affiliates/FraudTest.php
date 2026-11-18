<?php

use App\Affiliates\Identity;
use App\Enums\BillingInterval;
use App\Enums\LoginEvent;
use App\Enums\MembershipTier;
use App\Models\Commission;
use App\Models\LoginActivity;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\ReferralClick;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->price = planFor(MembershipTier::Pro, BillingInterval::Month, 'INR', ['amount' => 99900]);
    $this->owner = User::factory()->student()->create(['email' => 'asha.k+promo@gmail.com']);
    $this->a = makeAffiliate([], $this->owner);
    // The affiliate logs in from their own laptop at home.
    LoginActivity::create(['user_id' => $this->owner->id, 'email' => $this->owner->email, 'event' => LoginEvent::Success, 'channel' => 'web',
        'ip_address' => '198.51.100.7', 'device_hash' => Identity::hash('device-affiliate-laptop')]);
});

it('blocks self-referral by the same inbox (gmail dots and +tags)', function () {
    $user = signUp('ashak@gmail.com', clickRef($this->a->code));
    expect(Referral::first())->status->toBe('rejected')->flags->toContain('self_referral');
    razorpayCharge($user, $this->price, 'pay_1');
    expect(Commission::count())->toBe(0);
});

it('blocks sign-ups from the affiliate\'s own device', function () {
    signUp('friend@example.com', clickRef($this->a->code), device: 'device-affiliate-laptop');
    expect(Referral::first())->status->toBe('rejected')->flags->toContain('same_device');
});

it('holds commissions from the affiliate\'s IP for admin review, then releases them on approval', function () {
    $admin = User::factory()->admin()->create();
    $user = signUp('roommate@example.com', clickRef($this->a->code, ip: '198.51.100.7'), ip: '198.51.100.7');
    expect(Referral::first())->status->toBe('flagged')->flags->toBe(['same_ip_as_affiliate']);

    razorpayCharge($user, $this->price, 'pay_1');
    $c = Commission::firstOrFail();
    expect($c->needs_review)->toBeTrue();
    $this->travel(15)->days();
    $this->artisan('affiliates:release');
    expect($c->fresh()->status)->toBe('pending'); // held

    $this->actingAs($admin)->getJson(api('admin/affiliate-reviews'))->assertJsonPath('data.0.flags', ['same_ip_as_affiliate'])
        ->assertJsonPath('data.0.held.INR', 16932);
    $this->actingAs($admin)->postJson(api('admin/affiliate-reviews/'.Referral::first()->uuid), ['approve' => true])->assertOk();
    $this->artisan('affiliates:release');
    expect($c->fresh()->status)->toBe('approved');
});

it('reverses held commissions when an admin rejects the referral', function () {
    $user = signUp('roommate@example.com', ip: '198.51.100.7', refCookie: clickRef($this->a->code));
    razorpayCharge($user, $this->price, 'pay_1');
    $this->actingAs(User::factory()->admin()->create())->postJson(api('admin/affiliate-reviews/'.Referral::first()->uuid), ['approve' => false]);
    expect(Commission::first())->status->toBe('reversed')->reversal_reason->toBe('rejected_in_review');
});

it('flags a customer paying with the affiliate\'s own card', function () {
    Payment::factory()->create(['user_id' => $this->owner->id, 'payment_fingerprint' => 'card:card_SameCard01']);
    $user = signUp('friend@example.com', clickRef($this->a->code));
    $vars = ['card_id' => 'card_SameCard01'];
    // Fixture has no card_id, so set it like the gateway would.
    razorpayCharge($user, $this->price, 'pay_1');
    Payment::where('gateway_payment_id', 'pay_1')->update(['payment_fingerprint' => 'card:card_SameCard01']);
    $this->travel(15)->days();
    $this->artisan('affiliates:release');

    expect(Commission::first())->status->toBe('pending')->needs_review->toBeTrue()
        ->and(Referral::first())->status->toBe('flagged')->flags->toContain('shared_payment_method');
    expect($vars)->toBeArray();
});

it('flags velocity: too many sign-ups from one IP for the same affiliate', function () {
    config(['affiliates.fraud.max_signups_per_ip_per_day' => 2]);
    foreach (['a', 'b', 'c'] as $i => $n) {
        signUp("{$n}@example.com", clickRef($this->a->code, ip: '192.0.2.50'), device: "device-visitor-000{$i}", ip: '192.0.2.50');
    }
    expect(Referral::orderBy('id')->pluck('status')->all())->toBe(['pending', 'pending', 'flagged'])
        ->and(Referral::latest('id')->first()->flags)->toBe(['velocity_ip']);
});

it('stores only hashed IPs and devices', function () {
    signUp('friend@example.com', clickRef($this->a->code));
    $r = Referral::first();
    expect($r->signup_ip_hash)->toHaveLength(64)->not->toContain('203.0.113')
        ->and(ReferralClick::first()->ip_hash)->toBe(Identity::hash('203.0.113.10'));
});

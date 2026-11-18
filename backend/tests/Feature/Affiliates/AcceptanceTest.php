<?php

use App\Affiliates\Payouts\PayoutService;
use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Models\AffiliatePayout;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\ReferralClick;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
 * ACCEPTANCE: click → signup → paid subscription → renewal produces correct commissions; refund reverses them.
 * Pro monthly ₹999 incl. 18 % GST → net ₹846.61 (84661 paise); standard tier 20 % → ₹169.32 (16932) per payment.
 */
beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->price = planFor(MembershipTier::Pro, BillingInterval::Month, 'INR', ['amount' => 99900]);
    $this->affiliate = makeAffiliate();
});

it('pays 20 % of net on the first payment and each renewal, holds 14 days, and reverses on refund', function () {
    // 1. Click with UTM → signed 60-day cookie
    $cookie = clickRef($this->affiliate->code, ['utm' => ['utm_source' => 'youtube', 'utm_campaign' => 'diwali']]);
    expect($cookie)->not->toBeNull()
        ->and(ReferralClick::first())->utm->toBe(['utm_source' => 'youtube', 'utm_campaign' => 'diwali'])->ip_hash->toHaveLength(64);

    // 2. Sign-up 3 days later (cookie still valid) → referral, last-touch, linked to the click
    $this->travel(3)->days();
    $customer = signUp('customer@example.com', $cookie);
    $referral = Referral::firstOrFail();
    expect($referral)->referred_user_id->toBe($customer->id)->status->toBe('pending')->attribution->toBe('last_touch')
        ->click_id->toBe(ReferralClick::first()->id)->utm->toBe(['utm_source' => 'youtube', 'utm_campaign' => 'diwali']);

    // 3. First paid subscription (real Razorpay webhooks)
    razorpayCharge($customer, $this->price, 'pay_first');
    $first = Commission::firstOrFail();
    $paid = Payment::where('gateway_payment_id', 'pay_first')->firstOrFail();
    expect($paid->amount - $paid->tax_amount)->toBe(84661)
        ->and($first)->level->toBe(1)->base_amount->toBe(84661)->rate->toBe('20%')->amount->toBe(16932)->status->toBe('pending')
        ->and($first->available_at->equalTo($paid->paid_at->copy()->addDays(14)))->toBeTrue()
        ->and($referral->fresh())->status->toBe('converted')->converted_at->not->toBeNull();

    // Webhook replay doesn't double-pay.
    postRazorpayWebhook(webhookFixture('razorpay/subscription.charged.json', [
        'event' => 'subscription.charged', 'sub_id' => 'sub_'.$customer->uuid, 'razorpay_plan_id' => $this->price->razorpay_plan_id,
        'user_uuid' => $customer->uuid, 'plan_price_uuid' => $this->price->uuid, 'status' => 'active',
        'period_start' => now()->getTimestamp(), 'period_end' => now()->addMonth()->getTimestamp(), 'paid_count' => 1, 'payment_id' => 'pay_first', 'amount' => 99900,
    ]), 'evt_replay')->assertOk();
    expect(Commission::count())->toBe(1);

    // 4. Hold period
    $this->artisan('affiliates:release');
    expect($first->fresh()->status)->toBe('pending');
    $this->travel(15)->days();
    $this->artisan('affiliates:release');
    expect($first->fresh()->status)->toBe('approved');

    // 5. Renewal a month later → a second commission
    $this->travel(16)->days();
    razorpayCharge($customer, $this->price, 'pay_renewal', ['paid_count' => 2]);
    $renewal = Commission::where('payment_id', Payment::where('gateway_payment_id', 'pay_renewal')->value('id'))->firstOrFail();
    expect($renewal)->amount->toBe(16932)->status->toBe('pending');

    // 6. Full refund of the renewal → its commission is reversed; the first one stands.
    razorpayRefund('pay_renewal', 99900, 99900, 99900);
    expect($renewal->fresh())->status->toBe('reversed')->reversal_reason->toBe('refund')
        ->and($first->fresh()->status)->toBe('approved')
        ->and(Payment::where('gateway_payment_id', 'pay_renewal')->value('status')->value)->toBe('refunded');

    // Even after the hold, a reversed commission never becomes payable.
    $this->travel(20)->days();
    $this->artisan('affiliates:release');
    expect($renewal->fresh()->status)->toBe('reversed');

    // 7. Renewals beyond the 12-month window earn nothing.
    $this->travel(13)->months();
    razorpayCharge($customer, $this->price, 'pay_late', ['paid_count' => 15]);
    expect(Commission::where('payment_id', Payment::where('gateway_payment_id', 'pay_late')->value('id'))->exists())->toBeFalse();

    // The affiliate sees it all.
    $this->actingAs($this->affiliate->user)->getJson(api('me/affiliate'))
        ->assertJsonPath('data.balances.INR.approved', 16932)->assertJsonPath('data.balances.INR.pending', 0);
    $this->actingAs($this->affiliate->user)->getJson(api('me/affiliate/stats?days=365'))
        ->assertJsonPath('data.funnel.clicks', 0) // the click was >365 days ago by now
        ->assertJsonPath('data.funnel.conversions', 0);
});

it('claws back a commission that was already paid out when the payment is refunded later', function () {
    config(['affiliates.payouts.minimum.INR' => 10000]);
    $this->affiliate->forceFill(['payout_method' => 'upi', 'payout_details' => ['upi_id' => 'aff@okaxis']])->save();
    $customer = signUp('customer@example.com', clickRef($this->affiliate->code));
    razorpayCharge($customer, $this->price, 'pay_first');
    $this->travel(15)->days();
    $this->artisan('affiliates:release');
    $this->artisan('affiliates:payouts', ['period' => '2026-12'])->assertSuccessful();
    $payout = AffiliatePayout::firstOrFail();
    expect($payout->amount)->toBe(16932);
    app(PayoutService::class)->markPaid($payout, User::factory()->admin()->create(), 'UTR1');
    expect(Commission::first()->status)->toBe('paid');

    // Partial refund (half) after payout → negative line for half the commission
    razorpayRefund('pay_first', 99900, 49950, 49950);
    $claw = Commission::where('kind', 'clawback')->firstOrFail();
    expect($claw)->amount->toBe(-8466)->status->toBe('approved')->parent_id->toBe(Commission::where('kind', 'sale')->value('id'));
    // Refund replay → nothing new
    razorpayRefund('pay_first', 99900, 49950, 49950);
    expect(Commission::where('kind', 'clawback')->count())->toBe(1);
    // Rest refunded → the remaining half is clawed back too
    razorpayRefund('pay_first', 99900, 99900, 49950);
    expect((int) Commission::where('kind', 'clawback')->sum('amount'))->toBe(-16932);
    $this->actingAs($this->affiliate->user)->getJson(api('me/affiliate'))->assertJsonPath('data.balances.INR.approved', -16932);
});

it('reverses on a chargeback', function () {
    $customer = signUp('customer@example.com', clickRef($this->affiliate->code));
    razorpayCharge($customer, $this->price, 'pay_first');
    postRazorpayWebhook(webhookFixture('razorpay/payment.dispute.created.json', ['payment_id' => 'pay_first', 'amount' => 99900]))->assertOk();

    expect(Commission::first())->status->toBe('reversed')->reversal_reason->toBe('chargeback')
        ->and(Payment::first()->disputed_at)->not->toBeNull();
});

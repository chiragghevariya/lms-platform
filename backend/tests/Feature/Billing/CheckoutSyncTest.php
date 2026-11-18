<?php

use App\Enums\MembershipTier;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->student()->create(['country' => 'IN']);
    $this->price = planFor(MembershipTier::Pro);
});

function rzpSignature(string $a, string $b): string
{
    return hash_hmac('sha256', $a.'|'.$b, 'rzp_test_secret');
}

it('verifies the Razorpay handler signature and activates immediately', function () {
    Subscription::factory()->for($this->user)->forPrice($this->price)->create(['gateway_subscription_id' => 'sub_V1', 'status' => 'incomplete']);
    Http::fake(['api.razorpay.com/v1/subscriptions/sub_V1' => Http::response([
        'id' => 'sub_V1', 'plan_id' => $this->price->razorpay_plan_id, 'status' => 'active',
        'current_start' => now()->getTimestamp(), 'current_end' => now()->addMonth()->getTimestamp(),
        'notes' => ['user_uuid' => $this->user->uuid, 'plan_price_uuid' => $this->price->uuid],
    ])]);

    $this->actingAs($this->user)->postJson(api('billing/checkout/razorpay/verify'), [
        'razorpay_payment_id' => 'pay_V1', 'razorpay_subscription_id' => 'sub_V1',
        'razorpay_signature' => rzpSignature('pay_V1', 'sub_V1'),
    ])->assertOk()->assertJsonPath('data.subscription.status', 'active');

    expect($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro);
});

it('rejects a forged Razorpay signature', function () {
    $this->actingAs($this->user)->postJson(api('billing/checkout/razorpay/verify'), [
        'razorpay_payment_id' => 'pay_V1', 'razorpay_subscription_id' => 'sub_V1', 'razorpay_signature' => 'forged',
    ])->assertUnprocessable()->assertJsonPath('code', 'signature_invalid');

    Http::assertNothingSent();
});

it('will not sync another user\'s Razorpay subscription', function () {
    Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'sub_X', 'status' => 'active', 'notes' => ['user_uuid' => 'someone-else']])]);

    $this->actingAs($this->user)->postJson(api('billing/checkout/razorpay/verify'), [
        'razorpay_payment_id' => 'pay_X', 'razorpay_subscription_id' => 'sub_X', 'razorpay_signature' => rzpSignature('pay_X', 'sub_X'),
    ])->assertNotFound();
});

it('syncs a completed Stripe Checkout Session on the success page', function () {
    $usd = planFor(MembershipTier::Elite, currency: 'USD');
    fakeStripe()->on('GET', '/v1/checkout/sessions/cs_test_ok', [
        'id' => 'cs_test_ok', 'object' => 'checkout.session', 'mode' => 'subscription', 'status' => 'complete',
        'client_reference_id' => $this->user->uuid, 'customer' => 'cus_S1',
        'subscription' => [
            'id' => 'sub_S1', 'object' => 'subscription', 'status' => 'active', 'customer' => 'cus_S1', 'cancel_at_period_end' => false,
            'metadata' => ['user_uuid' => $this->user->uuid, 'plan_price_uuid' => $usd->uuid],
            'items' => ['object' => 'list', 'data' => [[
                'id' => 'si_1', 'current_period_start' => now()->getTimestamp(), 'current_period_end' => now()->addMonth()->getTimestamp(),
                'price' => ['id' => $usd->stripe_price_id],
            ]]],
        ],
    ]);

    $this->actingAs($this->user)->getJson(api('billing/checkout/stripe/cs_test_ok'))
        ->assertOk()->assertJsonPath('data.checkout_status', 'complete')->assertJsonPath('data.subscription.tier', 'elite');
});

it('does not expose someone else\'s Stripe session', function () {
    fakeStripe()->on('GET', '/v1/checkout/sessions/cs_test_other', ['id' => 'cs_test_other', 'object' => 'checkout.session', 'client_reference_id' => 'other-uuid']);

    $this->actingAs($this->user)->getJson(api('billing/checkout/stripe/cs_test_other'))->assertNotFound();
});

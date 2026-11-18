<?php

use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Models\Coupon;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => $this->user = User::factory()->student()->create(['country' => 'IN', 'phone' => '+91 9876543210']));

it('starts a Stripe Checkout Session (subscription mode) for non-INR prices', function () {
    $stripe = fakeStripe()
        ->on('POST', '/v1/customers', ['id' => 'cus_New01', 'object' => 'customer'])
        ->on('POST', '/v1/checkout/sessions', ['id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']);
    $price = planFor(MembershipTier::Pro, BillingInterval::Month, 'USD');

    $this->actingAs($this->user)->postJson(api('billing/checkout'), ['plan_price_id' => $price->uuid])
        ->assertCreated()
        ->assertJsonPath('data.gateway', 'stripe')
        ->assertJsonPath('data.action', 'redirect')
        ->assertJsonPath('data.redirect_url', 'https://checkout.stripe.com/c/pay/cs_test_1');

    $params = $stripe->last('POST', '/v1/checkout/sessions')['params'];
    expect($params['mode'])->toBe('subscription')
        ->and($params['customer'])->toBe('cus_New01')
        ->and($params['line_items'][0]['price'])->toBe($price->stripe_price_id)
        ->and($params['subscription_data']['metadata']['user_uuid'])->toBe($this->user->uuid)
        ->and($params['success_url'])->toContain('/billing/success?gateway=stripe&session_id={CHECKOUT_SESSION_ID}')
        ->and($this->user->fresh()->stripe_id)->toBe('cus_New01');
});

it('uses payment mode for a lifetime Stripe price', function () {
    $stripe = fakeStripe()
        ->on('POST', '/v1/customers', ['id' => 'cus_New02', 'object' => 'customer'])
        ->on('POST', '/v1/checkout/sessions', ['id' => 'cs_test_2', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/x']);
    $price = planFor(MembershipTier::Elite, BillingInterval::Lifetime, 'USD');

    $this->actingAs($this->user)->postJson(api('billing/checkout'), ['plan_price_id' => $price->uuid])->assertCreated();

    expect($stripe->last('POST', '/v1/checkout/sessions')['params']['mode'])->toBe('payment');
});

it('creates a Razorpay subscription for INR prices and returns the modal payload', function () {
    Http::fake(['api.razorpay.com/v1/subscriptions' => Http::response(['id' => 'sub_Rzp001', 'status' => 'created', 'short_url' => 'https://rzp.io/i/x'])]);
    $price = planFor(MembershipTier::Pro);

    $this->actingAs($this->user)->postJson(api('billing/checkout'), ['plan_price_id' => $price->uuid])
        ->assertCreated()
        ->assertJsonPath('data.gateway', 'razorpay')
        ->assertJsonPath('data.action', 'razorpay_modal')
        ->assertJsonPath('data.razorpay.subscription_id', 'sub_Rzp001')
        ->assertJsonPath('data.razorpay.key', 'rzp_test_key')
        ->assertJsonPath('data.razorpay.prefill.email', $this->user->email);

    Http::assertSent(fn ($r) => $r['plan_id'] === $price->razorpay_plan_id && $r['notes']['user_uuid'] === $this->user->uuid
        && $r->hasHeader('Authorization', 'Basic '.base64_encode('rzp_test_key:rzp_test_secret')));
    expect(Subscription::first())->status->value->toBe('incomplete')->gateway_subscription_id->toBe('sub_Rzp001');
});

it('creates a Razorpay order with the coupon discount for a lifetime price', function () {
    Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_Lt01', 'amount' => 3999920, 'status' => 'created'])]);
    $price = planFor(MembershipTier::Elite, BillingInterval::Lifetime, 'INR', ['amount' => 4999900]);
    Coupon::factory()->create(['code' => 'LAUNCH20', 'value' => 20]);

    $this->actingAs($this->user)->postJson(api('billing/checkout'), ['plan_price_id' => $price->uuid, 'coupon_code' => 'launch20'])
        ->assertCreated()->assertJsonPath('data.razorpay.order_id', 'order_Lt01')->assertJsonPath('data.razorpay.amount', 3999920);

    Http::assertSent(fn ($r) => $r['amount'] === 3999920 && $r['currency'] === 'INR');
});

it('refuses a second recurring subscription and lifetime repurchase', function () {
    subscribe($this->user, MembershipTier::Starter);
    $this->actingAs($this->user)->postJson(api('billing/checkout'), ['plan_price_id' => planFor(MembershipTier::Pro)->uuid])
        ->assertStatus(409)->assertJsonPath('code', 'already_subscribed');

    $lifer = User::factory()->create();
    subscribe($lifer, MembershipTier::Elite, ['is_lifetime' => true, 'current_period_end' => null]);
    $this->actingAs($lifer)->postJson(api('billing/checkout'), ['plan_price_id' => planFor(MembershipTier::Elite, BillingInterval::Lifetime)->uuid])
        ->assertStatus(409)->assertJsonPath('code', 'already_lifetime');
});

it('reports prices that are not wired to a gateway yet', function () {
    $price = planFor(MembershipTier::Pro, attrs: ['razorpay_plan_id' => null]);

    $this->actingAs($this->user)->postJson(api('billing/checkout'), ['plan_price_id' => $price->uuid])
        ->assertUnprocessable()->assertJsonPath('code', 'price_not_configured');
});

describe('coupons', function () {
    it('validates a coupon and returns the discounted price', function () {
        $price = planFor(MembershipTier::Pro, BillingInterval::Lifetime, 'INR', ['amount' => 100000]);
        Coupon::factory()->create(['code' => 'SAVE25', 'value' => 25]);

        $this->actingAs($this->user)->postJson(api('billing/coupons/validate'), ['plan_price_id' => $price->uuid, 'coupon_code' => 'save25'])
            ->assertOk()->assertJsonPath('data.discount.amount', 25000)->assertJsonPath('data.final_price.amount', 75000);
    });

    it('rejects expired, exhausted, out-of-scope and reused coupons', function () {
        $price = planFor(MembershipTier::Pro, BillingInterval::Lifetime);
        $other = planFor(MembershipTier::Elite);
        Coupon::factory()->create(['code' => 'OLD', 'expires_at' => now()->subDay()]);
        Coupon::factory()->create(['code' => 'FULL', 'max_uses' => 1, 'times_used' => 1]);
        Coupon::factory()->create(['code' => 'ELITEONLY'])->plans()->attach($other->plan_id);
        $used = Coupon::factory()->create(['code' => 'ONCE']);
        $used->redemptions()->create(['user_id' => $this->user->id]);

        foreach (['OLD' => 'expired', 'FULL' => 'usage limit', 'ELITEONLY' => 'does not apply', 'ONCE' => 'already used', 'NOPE' => 'not valid'] as $code => $msg) {
            $this->actingAs($this->user)->postJson(api('billing/coupons/validate'), ['plan_price_id' => $price->uuid, 'coupon_code' => $code])
                ->assertJsonValidationErrors(['coupon_code' => $msg]);
        }
    });

    it('requires a Razorpay offer for coupons on Razorpay subscriptions', function () {
        $price = planFor(MembershipTier::Pro);
        Coupon::factory()->create(['code' => 'NOOFFER']);
        Coupon::factory()->create(['code' => 'WITHOFFER', 'razorpay_offer_id' => 'offer_X1']);

        $this->actingAs($this->user)->postJson(api('billing/coupons/validate'), ['plan_price_id' => $price->uuid, 'coupon_code' => 'NOOFFER'])
            ->assertJsonValidationErrors(['coupon_code' => 'payment method']);
        $this->actingAs($this->user)->postJson(api('billing/coupons/validate'), ['plan_price_id' => $price->uuid, 'coupon_code' => 'WITHOFFER'])
            ->assertOk();
    });

    it('mirrors the coupon to Stripe once and applies it at checkout', function () {
        $stripe = fakeStripe()
            ->on('POST', '/v1/coupons', ['id' => 'co_Mirror1', 'object' => 'coupon'])
            ->on('POST', '/v1/customers', ['id' => 'cus_C1', 'object' => 'customer'])
            ->on('POST', '/v1/checkout/sessions', ['id' => 'cs_c', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c']);
        $price = planFor(MembershipTier::Pro, BillingInterval::Month, 'USD');
        $coupon = Coupon::factory()->create(['code' => 'TENOFF', 'value' => 10]);

        $this->actingAs($this->user)->postJson(api('billing/checkout'), ['plan_price_id' => $price->uuid, 'coupon_code' => 'TENOFF'])->assertCreated();

        expect($stripe->last('POST', '/v1/coupons')['params'])->toMatchArray(['percent_off' => 10, 'duration' => 'once'])
            ->and($stripe->last('POST', '/v1/checkout/sessions')['params']['discounts'][0]['coupon'])->toBe('co_Mirror1')
            ->and($coupon->fresh()->stripe_coupon_id)->toBe('co_Mirror1');
    });
});

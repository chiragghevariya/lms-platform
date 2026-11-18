<?php

use App\Billing\Webhooks\RazorpayEventHandler;
use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->user = User::factory()->student()->create(['country' => 'IN']);
    $this->pro = planFor(MembershipTier::Pro, attrs: ['amount' => 100000]);
    $this->elite = PlanPrice::factory()->for(Plan::factory()->tier(MembershipTier::Elite))->razorpay()->create(['amount' => 200000]);
    $this->starter = PlanPrice::factory()->for(Plan::factory()->tier(MembershipTier::Starter))->razorpay()->create(['amount' => 50000]);
    $this->sub = Subscription::factory()->for($this->user)->forPrice($this->pro)->create([
        'gateway_subscription_id' => 'sub_M1',
        'current_period_start' => now()->subDays(15),
        'current_period_end' => now()->addDays(15),
    ]);
});

it('previews an upgrade with time-based proration', function () {
    $this->actingAs($this->user)->postJson(api('billing/subscription/preview-change'), ['plan_price_id' => $this->elite->uuid])
        ->assertOk()
        ->assertJsonPath('data.direction', 'upgrade')
        ->assertJsonPath('data.effective', 'now')
        ->assertJsonPath('data.credit', 50000)
        ->assertJsonPath('data.charge', 100000)
        ->assertJsonPath('data.amount_due_now', 50000);
});

it('upgrades immediately via Razorpay "now" (Razorpay prorates)', function () {
    Http::fake(['api.razorpay.com/v1/subscriptions/sub_M1' => Http::response(['id' => 'sub_M1', 'status' => 'active'])]);

    $this->actingAs($this->user)->postJson(api('billing/subscription/change'), ['plan_price_id' => $this->elite->uuid])
        ->assertOk()->assertJsonPath('data.tier', 'elite')->assertJsonPath('data.scheduled_change', null);

    Http::assertSent(fn ($r) => $r->method() === 'PATCH' && $r['schedule_change_at'] === 'now' && $r['plan_id'] === $this->elite->razorpay_plan_id);
    expect($this->user->fresh()->currentTier())->toBe(MembershipTier::Elite);
});

it('schedules a downgrade for period end and keeps the higher tier until then', function () {
    Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'sub_M1'])]);

    $this->actingAs($this->user)->postJson(api('billing/subscription/change'), ['plan_price_id' => $this->starter->uuid])
        ->assertOk()->assertJsonPath('data.tier', 'pro')->assertJsonPath('data.scheduled_change.plan.tier', 'starter');
    Http::assertSent(fn ($r) => ($r['schedule_change_at'] ?? null) === 'cycle_end');

    // Gateway reports the new plan early → we still honour the paid-for tier.
    app(RazorpayEventHandler::class)->syncSubscription([
        'id' => 'sub_M1', 'plan_id' => $this->starter->razorpay_plan_id, 'status' => 'active',
        'current_start' => now()->subDays(15)->getTimestamp(), 'current_end' => now()->addDays(15)->getTimestamp(), 'notes' => [],
    ]);
    expect($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro);

    // At period end the change applies.
    $this->travel(16)->days();
    app(RazorpayEventHandler::class)->syncSubscription([
        'id' => 'sub_M1', 'plan_id' => $this->starter->razorpay_plan_id, 'status' => 'active',
        'current_start' => now()->getTimestamp(), 'current_end' => now()->addMonth()->getTimestamp(), 'notes' => [],
    ]);
    expect($this->user->fresh()->currentTier())->toBe(MembershipTier::Starter);
});

it('withdraws a pending downgrade when the current plan is chosen again', function () {
    Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'sub_M1'])]);
    $this->actingAs($this->user)->postJson(api('billing/subscription/change'), ['plan_price_id' => $this->starter->uuid]);

    $this->actingAs($this->user)->postJson(api('billing/subscription/change'), ['plan_price_id' => $this->pro->uuid])
        ->assertOk()->assertJsonPath('data.scheduled_change', null);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/subscriptions/sub_M1/cancel_scheduled_changes'));
});

it('surfaces Razorpay errors (e.g. UPI mandates cannot be updated)', function () {
    Http::fake(['api.razorpay.com/*' => Http::response(['error' => ['description' => 'Subscription cannot be updated when payment mode is upi']], 400)]);

    $this->actingAs($this->user)->postJson(api('billing/subscription/change'), ['plan_price_id' => $this->elite->uuid])
        ->assertUnprocessable()->assertJsonPath('message', 'Subscription cannot be updated when payment mode is upi');
    expect($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro);
});

it('rejects cross-currency and lifetime plan changes', function () {
    $usd = planFor(MembershipTier::Elite, currency: 'USD');
    $lifetime = planFor(MembershipTier::Elite, BillingInterval::Lifetime);

    $this->actingAs($this->user)->postJson(api('billing/subscription/change'), ['plan_price_id' => $usd->uuid])->assertJsonPath('code', 'currency_mismatch');
    $this->actingAs($this->user)->postJson(api('billing/subscription/change'), ['plan_price_id' => $lifetime->uuid])->assertJsonPath('code', 'lifetime');
});

describe('Razorpay cancel / resume (deferred because Razorpay cancellations are irreversible)', function () {
    it('cancels locally, can resume, and forwards the cancel 24h before renewal', function () {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'sub_M1', 'status' => 'active'])]);

        $this->actingAs($this->user)->postJson(api('billing/subscription/cancel'))
            ->assertOk()->assertJsonPath('data.cancel_at_period_end', true)->assertJsonPath('data.can_resume', true);
        Http::assertNothingSent();

        $this->actingAs($this->user)->postJson(api('billing/subscription/resume'))->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
        $this->actingAs($this->user)->postJson(api('billing/subscription/cancel'))->assertOk();

        $this->travelTo(now()->addDays(14)->addHours(2));
        $this->artisan('billing:maintain')->assertSuccessful();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/subscriptions/sub_M1/cancel') && $r['cancel_at_cycle_end'] === 1);
        $this->actingAs($this->user)->postJson(api('billing/subscription/resume'))->assertStatus(409)->assertJsonPath('code', 'resume_unavailable');
        // Access continues until the period ends.
        expect($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro);
    });

    it('sends the cancel immediately when already inside the lead window', function () {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'sub_M1'])]);
        $this->sub->update(['current_period_end' => now()->addHours(5)]);

        $this->actingAs($this->user)->postJson(api('billing/subscription/cancel'))->assertOk()->assertJsonPath('data.can_resume', false);
        Http::assertSentCount(1);
    });
});

describe('Stripe', function () {
    beforeEach(function () {
        $this->usdPro = planFor(MembershipTier::Pro, currency: 'USD');
        $this->usdElite = PlanPrice::factory()->for(Plan::factory()->tier(MembershipTier::Elite))->stripe()->create();
        $this->stripeUser = User::factory()->create(['country' => 'US', 'stripe_id' => 'cus_P1']);
        Subscription::factory()->for($this->stripeUser)->forPrice($this->usdPro)->create(['gateway_subscription_id' => 'sub_S9']);
        $this->stripe = fakeStripe()
            ->on('GET', '/v1/subscriptions/sub_S9', ['id' => 'sub_S9', 'object' => 'subscription', 'items' => ['object' => 'list', 'data' => [['id' => 'si_9', 'object' => 'subscription_item']]]])
            ->on('POST', '/v1/subscriptions/sub_S9', ['id' => 'sub_S9', 'object' => 'subscription'])
            ->on('POST', '/v1/billing_portal/sessions', ['id' => 'bps_1', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.com/p/session/x']);
    });

    it('upgrades with always_invoice proration', function () {
        $this->actingAs($this->stripeUser)->postJson(api('billing/subscription/change'), ['plan_price_id' => $this->usdElite->uuid])->assertOk();

        expect($this->stripe->last('POST', '/v1/subscriptions/sub_S9')['params'])
            ->toMatchArray(['proration_behavior' => 'always_invoice'])
            ->and($this->stripe->last('POST', '/v1/subscriptions/sub_S9')['params']['items'][0])->toBe(['id' => 'si_9', 'price' => $this->usdElite->stripe_price_id]);
    });

    it('cancels at period end and resumes', function () {
        $this->actingAs($this->stripeUser)->postJson(api('billing/subscription/cancel'))->assertOk();
        expect($this->stripe->last('POST', '/v1/subscriptions/sub_S9')['params'])->toBe(['cancel_at_period_end' => 'true']); // form-encoded by stripe-php

        $this->actingAs($this->stripeUser)->postJson(api('billing/subscription/resume'))->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
        expect($this->stripe->last('POST', '/v1/subscriptions/sub_S9')['params'])->toBe(['cancel_at_period_end' => 'false']);
    });

    it('opens the Customer Portal to update the card', function () {
        $this->actingAs($this->stripeUser)->getJson(api('billing/portal'))->assertOk()->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/x');
    });
});

it('has no portal for Razorpay and blocks self-service on complimentary memberships', function () {
    $this->actingAs($this->user)->getJson(api('billing/portal'))->assertStatus(409)->assertJsonPath('code', 'portal_unavailable');

    $comp = User::factory()->create();
    app(MembershipService::class)->grantComplimentary($comp, $this->pro->plan, null, null);
    $this->actingAs($comp)->postJson(api('billing/subscription/cancel'))->assertStatus(409)->assertJsonPath('code', 'manual_subscription');
});

it('returns the current subscription for the billing page', function () {
    $this->actingAs($this->user)->getJson(api('billing/subscription'))
        ->assertOk()->assertJsonPath('data.tier', 'pro')->assertJsonPath('data.gateway', 'razorpay')->assertJsonPath('data.is_active', true);
    $this->actingAs(User::factory()->create())->getJson(api('billing/subscription'))->assertOk()->assertJsonPath('data', null);
});

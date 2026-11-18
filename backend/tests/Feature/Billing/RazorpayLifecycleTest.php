<?php

use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\SubscriptionEndedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->user = User::factory()->student()->create(['country' => 'IN']);
    $this->price = planFor(MembershipTier::Pro, BillingInterval::Month, 'INR', ['amount' => 99900]);
    $this->vars = fn (array $o = []) => [
        'event' => 'subscription.activated',
        'sub_id' => 'sub_TestRazorpay1',
        'razorpay_plan_id' => $this->price->razorpay_plan_id,
        'user_uuid' => $this->user->uuid,
        'plan_price_uuid' => $this->price->uuid,
        'status' => 'active',
        'period_start' => now()->getTimestamp(),
        'period_end' => now()->addMonth()->getTimestamp(),
        'paid_count' => 1,
        'payment_id' => 'pay_'.bin2hex(random_bytes(6)),
        'amount' => 99900,
        ...$o,
    ];
});

it('rejects a bad signature', function () {
    postRazorpayWebhook(webhookFixture('razorpay/subscription.event.json', ($this->vars)()), secret: 'nope')->assertStatus(400);
    expect(WebhookEvent::count())->toBe(0);
});

it('dedupes by X-Razorpay-Event-Id', function () {
    $payload = webhookFixture('razorpay/subscription.event.json', ($this->vars)());

    postRazorpayWebhook($payload, 'evt_same')->assertJsonPath('status', 'queued');
    postRazorpayWebhook($payload, 'evt_same')->assertJsonPath('status', 'duplicate');

    expect(WebhookEvent::count())->toBe(1);
});

it('runs the full subscribe → renew → fail → downgrade cycle', function () {
    $v = $this->vars;

    // 1. Subscribe: activated + first charge (GST invoice for an Indian customer)
    postRazorpayWebhook(webhookFixture('razorpay/subscription.event.json', $v()))->assertOk();
    postRazorpayWebhook(webhookFixture('razorpay/subscription.charged.json', $v(['payment_id' => 'pay_first'])))->assertOk();

    $sub = Subscription::firstOrFail();
    $first = Payment::where('gateway_payment_id', 'pay_first')->firstOrFail();
    expect($sub->status->value)->toBe('active')
        ->and($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro)
        ->and($first->tax_name)->toBe('GST')
        ->and($first->subtotal + $first->tax_amount)->toBe(99900)
        ->and($first->pdf_path)->not->toBeNull();

    // 2. Renewal
    $this->travel(1)->month();
    $renew = ['period_start' => now()->getTimestamp(), 'period_end' => now()->addMonth()->getTimestamp(), 'paid_count' => 2];
    postRazorpayWebhook(webhookFixture('razorpay/subscription.charged.json', $v($renew + ['payment_id' => 'pay_renewal'])))->assertOk();

    expect(Payment::where('status', 'succeeded')->count())->toBe(2)
        ->and($sub->fresh()->current_period_end->getTimestamp())->toBe($renew['period_end']);

    // 3. Renewal charge fails → Razorpay marks subscription "pending" (retrying)
    $this->travel(1)->month();
    postRazorpayWebhook(webhookFixture('razorpay/subscription.event.json', $v(['event' => 'subscription.pending', 'status' => 'pending'] + $renew)))->assertOk();

    expect($sub->fresh()->status->value)->toBe('past_due')
        ->and($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro);
    Notification::assertSentTo($this->user, PaymentFailedNotification::class);

    // Retries exhausted: "halted" keeps it past_due (grace still governs access)
    postRazorpayWebhook(webhookFixture('razorpay/subscription.event.json', $v(['event' => 'subscription.halted', 'status' => 'halted'] + $renew)))->assertOk();
    Notification::assertSentToTimes($this->user, PaymentFailedNotification::class, 1);

    // 4. Grace passes → downgraded
    $this->travel(4)->days();
    $this->artisan('billing:maintain')->assertSuccessful();

    expect($sub->fresh()->status->value)->toBe('expired')
        ->and($this->user->fresh()->currentTier())->toBeNull();
    Notification::assertSentTo($this->user, SubscriptionEndedNotification::class);
});

it('restores access when a pending charge later succeeds', function () {
    $v = $this->vars;
    postRazorpayWebhook(webhookFixture('razorpay/subscription.event.json', $v(['event' => 'subscription.pending', 'status' => 'pending'])));
    postRazorpayWebhook(webhookFixture('razorpay/subscription.charged.json', $v()));

    expect(Subscription::first())->status->value->toBe('active')->past_due_since->toBeNull();
});

it('activates a lifetime purchase from order.paid', function () {
    $lifetime = planFor(MembershipTier::Elite, BillingInterval::Lifetime, 'INR', ['amount' => 4999900]);

    postRazorpayWebhook(webhookFixture('razorpay/order.paid.json', [
        'order_id' => 'order_Lifetime01', 'amount' => 4999900,
        'user_uuid' => $this->user->uuid, 'plan_price_uuid' => $lifetime->uuid,
    ]))->assertOk();

    expect(Subscription::first())->is_lifetime->toBeTrue()->gateway_subscription_id->toBe('order_Lifetime01')
        ->and($this->user->fresh()->currentTier())->toBe(MembershipTier::Elite);
});

it('ignores events for unknown subscriptions without failing', function () {
    $payload = webhookFixture('razorpay/subscription.event.json', ($this->vars)(['user_uuid' => 'not-a-user', 'razorpay_plan_id' => 'plan_unknown', 'plan_price_uuid' => 'x']));

    postRazorpayWebhook($payload)->assertOk();

    expect(Subscription::count())->toBe(0)->and(WebhookEvent::first()->status->value)->toBe('processed');
});

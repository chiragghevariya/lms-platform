<?php

use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Enums\WebhookStatus;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\PaymentReceiptNotification;
use App\Notifications\Billing\SubscriptionActivatedNotification;
use App\Notifications\Billing\SubscriptionEndedNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->user = User::factory()->student()->create(['country' => 'US']);
    $this->price = planFor(MembershipTier::Pro, BillingInterval::Month, 'USD', ['amount' => 1900]);
    $this->start = now()->startOfMinute();
    $this->vars = fn (array $o = []) => [
        'event_id' => 'evt_'.bin2hex(random_bytes(6)),
        'sub_id' => 'sub_TestStripe01',
        'user_uuid' => $this->user->uuid,
        'plan_price_uuid' => $this->price->uuid,
        'stripe_price_id' => $this->price->stripe_price_id,
        'status' => 'active',
        'cancel_at_period_end' => false,
        'period_start' => $this->start->getTimestamp(),
        'period_end' => $this->start->copy()->addMonth()->getTimestamp(),
        'invoice_id' => 'in_'.bin2hex(random_bytes(6)),
        'amount' => 1900,
        'billing_reason' => 'subscription_create',
        ...$o,
    ];
});

it('rejects webhooks with a bad signature', function () {
    postStripeWebhook(webhookFixture('stripe/customer.subscription.updated.json', ($this->vars)()), 'whsec_wrong')
        ->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
});

it('is idempotent: the same event delivered twice is processed once', function () {
    $payload = webhookFixture('stripe/customer.subscription.updated.json', ($this->vars)(['event_id' => 'evt_dup']));

    postStripeWebhook($payload)->assertOk()->assertJsonPath('status', 'queued');
    postStripeWebhook($payload)->assertOk()->assertJsonPath('status', 'duplicate');

    expect(WebhookEvent::count())->toBe(1)
        ->and(WebhookEvent::first()->status)->toBe(WebhookStatus::Processed)
        ->and(Subscription::count())->toBe(1);
});

it('runs the full subscribe → renew → fail → downgrade cycle', function () {
    $v = $this->vars;

    // 1. Subscribe: subscription created + first invoice paid
    postStripeWebhook(webhookFixture('stripe/customer.subscription.updated.json', $v()))->assertOk();
    postStripeWebhook(webhookFixture('stripe/invoice.paid.json', $v(['invoice_id' => 'in_first'])))->assertOk();

    $sub = Subscription::firstOrFail();
    expect($sub->status->value)->toBe('active')
        ->and($sub->gateway->value)->toBe('stripe')
        ->and($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro)
        ->and(Payment::where('gateway_payment_id', 'in_first')->first())
        ->status->value->toBe('succeeded')
        ->invoice_number->not->toBeNull()
        ->pdf_path->not->toBeNull();
    Notification::assertSentTo($this->user, SubscriptionActivatedNotification::class);
    Notification::assertSentTo($this->user, PaymentReceiptNotification::class);

    // 2. Renewal: next period paid
    $this->travel(1)->month();
    $next = ['period_start' => now()->getTimestamp(), 'period_end' => now()->addMonth()->getTimestamp(), 'billing_reason' => 'subscription_cycle'];
    postStripeWebhook(webhookFixture('stripe/customer.subscription.updated.json', $v($next)))->assertOk();
    postStripeWebhook(webhookFixture('stripe/invoice.paid.json', $v($next + ['invoice_id' => 'in_renewal'])))->assertOk();

    expect(Payment::where('status', 'succeeded')->count())->toBe(2)
        ->and($sub->fresh()->current_period_end->getTimestamp())->toBe($next['period_end']);

    // 3. Next renewal fails → past_due, dunning email, access kept during grace
    $this->travel(1)->month();
    $fail = ['period_start' => now()->getTimestamp(), 'period_end' => now()->addMonth()->getTimestamp()];
    postStripeWebhook(webhookFixture('stripe/invoice.payment_failed.json', $v($fail + ['invoice_id' => 'in_failed'])))->assertOk();
    postStripeWebhook(webhookFixture('stripe/customer.subscription.updated.json', $v($fail + ['status' => 'past_due'])))->assertOk();

    expect($sub->fresh()->status->value)->toBe('past_due')
        ->and($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro)
        ->and(Payment::where('gateway_payment_id', 'in_failed')->first()->status->value)->toBe('failed');
    Notification::assertSentToTimes($this->user, PaymentFailedNotification::class, 1);

    // 4. Grace (3 days) passes without payment → downgraded
    $this->travel(2)->days();
    $this->artisan('billing:maintain')->assertSuccessful();
    expect($this->user->fresh()->currentTier())->toBe(MembershipTier::Pro); // still within grace

    $this->travel(2)->days();
    $this->artisan('billing:maintain')->assertSuccessful();

    expect($sub->fresh()->status->value)->toBe('expired')
        ->and($this->user->fresh()->currentTier())->toBeNull();
    Notification::assertSentTo($this->user, SubscriptionEndedNotification::class);
});

it('recovers when a retry succeeds within the grace period', function () {
    $v = $this->vars;
    postStripeWebhook(webhookFixture('stripe/customer.subscription.updated.json', $v(['status' => 'past_due'])));
    expect(Subscription::first()->past_due_since)->not->toBeNull();

    postStripeWebhook(webhookFixture('stripe/invoice.paid.json', $v(['invoice_id' => 'in_retry'])));

    expect(Subscription::first())->status->value->toBe('active')->past_due_since->toBeNull();
});

it('accepts the classic API shape (periods on the subscription, invoice.subscription)', function () {
    $payload = json_decode(webhookFixture('stripe/customer.subscription.updated.json', ($this->vars)()), true);
    $item = &$payload['data']['object']['items']['data'][0];
    $payload['data']['object']['current_period_start'] = $item['current_period_start'];
    $payload['data']['object']['current_period_end'] = $item['current_period_end'];
    unset($item['current_period_start'], $item['current_period_end']);

    postStripeWebhook(json_encode($payload))->assertOk();

    expect(Subscription::first()->current_period_end)->not->toBeNull();
});

it('ends access when Stripe deletes the subscription', function () {
    postStripeWebhook(webhookFixture('stripe/customer.subscription.updated.json', ($this->vars)()));
    postStripeWebhook(webhookFixture('stripe/customer.subscription.deleted.json', ($this->vars)()));

    expect(Subscription::first()->status->value)->toBe('cancelled')
        ->and($this->user->fresh()->currentTier())->toBeNull();
});

it('activates a lifetime purchase from checkout.session.completed', function () {
    $lifetime = planFor(MembershipTier::Elite, BillingInterval::Lifetime, 'USD', ['amount' => 79900]);

    postStripeWebhook(webhookFixture('stripe/checkout.session.completed.lifetime.json', ($this->vars)([
        'plan_price_uuid' => $lifetime->uuid, 'amount' => 79900,
    ])))->assertOk();

    $sub = Subscription::firstOrFail();
    expect($sub->is_lifetime)->toBeTrue()
        ->and($sub->current_period_end)->toBeNull()
        ->and($this->user->fresh()->currentTier())->toBe(MembershipTier::Elite)
        ->and($this->user->fresh()->stripe_id)->toBe('cus_TestCustomer01')
        ->and(Payment::first()->amount)->toBe(79900);
});

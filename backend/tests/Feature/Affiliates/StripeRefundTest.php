<?php

use App\Enums\BillingInterval;
use App\Enums\MembershipTier;
use App\Instructors\EarningsService;
use App\Models\Commission;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->price = planFor(MembershipTier::Pro, BillingInterval::Month, 'USD', ['amount' => 1900]);
    $this->affiliate = makeAffiliate();
    $this->customer = signUp('us@example.com', clickRef($this->affiliate->code));
    $this->customer->forceFill(['country' => 'US'])->save();
    $this->vars = fn (array $o = []) => [
        'event_id' => 'evt_'.bin2hex(random_bytes(6)), 'sub_id' => 'sub_Aff01', 'user_uuid' => $this->customer->uuid,
        'plan_price_uuid' => $this->price->uuid, 'stripe_price_id' => $this->price->stripe_price_id, 'status' => 'active',
        'cancel_at_period_end' => false, 'period_start' => now()->getTimestamp(), 'period_end' => now()->addMonth()->getTimestamp(),
        'invoice_id' => 'in_aff1', 'amount' => 1900, 'billing_reason' => 'subscription_create', 'payment_intent' => 'pi_aff1', ...$o,
    ];
    postStripeWebhook(webhookFixture('stripe/customer.subscription.updated.json', ($this->vars)()))->assertOk();
    postStripeWebhook(webhookFixture('stripe/invoice.paid.json', ($this->vars)()))->assertOk();
});

it('maps a Stripe charge refund (PaymentIntent) to the invoice payment and reverses partially', function () {
    $payment = Payment::where('gateway_payment_id', 'in_aff1')->firstOrFail();
    expect($payment->gateway_reference)->toBe('pi_aff1')->and(Commission::first()->amount)->toBe(380); // 20 % of $19

    postStripeWebhook(webhookFixture('stripe/charge.refunded.json', [
        'event_id' => 'r1', 'charge_id' => 'c1', 'amount' => 1900, 'amount_refunded' => 950, 'payment_intent' => 'pi_aff1', 'refunded' => false,
    ]))->assertOk();
    expect(Commission::first())->status->toBe('pending')->reversed_amount->toBe(190)
        ->and($payment->fresh())->refunded_amount->toBe(950)->status->value->toBe('succeeded');

    // Phase 6 earnings use the unrefunded part only.
    expect(EarningsService::net($payment->fresh()))->toBe(950);

    postStripeWebhook(webhookFixture('stripe/charge.refunded.json', [
        'event_id' => 'r2', 'charge_id' => 'c1', 'amount' => 1900, 'amount_refunded' => 1900, 'payment_intent' => 'pi_aff1', 'refunded' => true,
    ]))->assertOk();
    expect(Commission::first())->status->toBe('reversed')->reversed_amount->toBe(380)
        ->and($payment->fresh()->status->value)->toBe('refunded');
});

it('records the card fingerprint from charge.succeeded for fraud checks', function () {
    postStripeWebhook(webhookFixture('stripe/charge.succeeded.json', [
        'event_id' => 's1', 'charge_id' => 'c1', 'amount' => 1900, 'payment_intent' => 'pi_aff1', 'fingerprint' => 'Fp123',
    ]))->assertOk();
    expect(Payment::first()->payment_fingerprint)->toBe('stripe:Fp123');
    expect(User::count())->toBeGreaterThan(1);
});

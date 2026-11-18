<?php

use App\Billing\RefundRecorder;
use App\Enums\Gateway;
use App\Enums\MembershipTier;
use App\Enums\PaymentStatus;
use App\Events\PaymentRefunded;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('lists payments with filters and per-currency totals', function () {
    $u = User::factory()->create(['email' => 'buyer@example.com']);
    Payment::factory()->create(['user_id' => $u->id, 'amount' => 1000, 'currency' => 'INR']);
    Payment::factory()->create(['amount' => 2000, 'currency' => 'INR', 'refunded_amount' => 500]);
    Payment::factory()->create(['amount' => 900, 'currency' => 'USD', 'gateway' => Gateway::Stripe, 'gateway_payment_id' => 'pi_x']);
    Payment::factory()->create(['status' => PaymentStatus::Failed, 'paid_at' => null, 'invoice_number' => null]);

    $res = $this->actingAs($this->admin)->getJson(api('admin/payments'))->assertOk()->assertJsonCount(4, 'data');
    expect(collect($res->json('meta.totals'))->keyBy('currency')['INR'])->toBe(['currency' => 'INR', 'gross' => 3000, 'refunded' => 500, 'count' => 2]);

    $this->actingAs($this->admin)->getJson(api('admin/payments?search=buyer@'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.email', 'buyer@example.com');
    $this->actingAs($this->admin)->getJson(api('admin/payments?status=failed'))->assertJsonCount(1, 'data');
    $this->actingAs($this->admin)->getJson(api('admin/payments?refunded=1'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.refundable_minor', 1500);
    $this->actingAs($this->admin)->getJson(api('admin/payments?gateway=stripe'))->assertJsonCount(1, 'data');
});

it('refunds a Stripe payment partially then fully, through the gateway', function () {
    Event::fake([PaymentRefunded::class]);
    $stripe = fakeStripe()->on('POST', '/v1/refunds', fn (array $p) => ['id' => 're_'.$p['amount'], 'object' => 'refund', 'amount' => $p['amount'], 'status' => 'succeeded']);
    $p = Payment::factory()->create(['gateway' => Gateway::Stripe, 'gateway_payment_id' => 'in_123', 'gateway_reference' => 'pi_123', 'amount' => 99900]);

    $this->actingAs($this->admin)->postJson(api("admin/payments/{$p->uuid}/refund"), ['amount' => 20000, 'reason' => 'Goodwill'])
        ->assertOk()->assertJsonPath('data.refunded_minor', 20000)->assertJsonPath('data.status', 'succeeded');
    expect($stripe->last('POST', '/v1/refunds')['params'])->toMatchArray(['payment_intent' => 'pi_123', 'amount' => 20000]);

    $this->actingAs($this->admin)->postJson(api("admin/payments/{$p->uuid}/refund"), ['amount' => 90000, 'reason' => 'Too much'])->assertJsonValidationErrors('amount');
    $this->actingAs($this->admin)->postJson(api("admin/payments/{$p->uuid}/refund"), ['reason' => 'Rest of it'])
        ->assertOk()->assertJsonPath('data.refunded_minor', 99900)->assertJsonPath('data.status', 'refunded')->assertJsonPath('data.refundable_minor', 0);
    expect($stripe->last('POST', '/v1/refunds')['params']['amount'])->toBe(79900);

    Event::assertDispatchedTimes(PaymentRefunded::class, 2);
    expect(Activity::where('event', 'payment.refunded')->count())->toBe(2);

    // The later Stripe webhook reports the same cumulative total → no double count.
    app(RefundRecorder::class)->refund(Gateway::Stripe, ['pi_123'], 99900);
    expect($p->fresh()->refunded_amount)->toBe(99900);
});

it('refunds a Razorpay payment', function () {
    Http::fake(['api.razorpay.com/v1/payments/pay_ABC/refund' => Http::response(['id' => 'rfnd_1', 'amount' => 99900, 'status' => 'processed'])]);
    $p = Payment::factory()->create(['gateway_payment_id' => 'pay_ABC', 'amount' => 99900]);

    $this->actingAs($this->admin)->postJson(api("admin/payments/{$p->uuid}/refund"), ['reason' => 'Duplicate charge'])
        ->assertOk()->assertJsonPath('data.status', 'refunded');

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/payments/pay_ABC/refund') && $r['amount'] === 99900 && $r['notes']['note'] === 'Duplicate charge');
});

it('reports gateway errors and refuses non-refundable payments', function () {
    fakeStripe(); // no route → Stripe answers 404
    $p = Payment::factory()->create(['gateway' => Gateway::Stripe, 'gateway_payment_id' => 'pi_404']);
    $this->actingAs($this->admin)->postJson(api("admin/payments/{$p->uuid}/refund"), ['reason' => 'Test'])->assertStatus(422);
    expect($p->fresh()->refunded_amount)->toBe(0);

    $failed = Payment::factory()->create(['status' => PaymentStatus::Failed]);
    $this->actingAs($this->admin)->postJson(api("admin/payments/{$failed->uuid}/refund"), ['reason' => 'Test'])->assertJsonValidationErrors('amount');

    $noRef = Payment::factory()->create(['gateway' => Gateway::Stripe, 'gateway_payment_id' => 'cs_only']);
    $this->actingAs($this->admin)->postJson(api("admin/payments/{$noRef->uuid}/refund"), ['reason' => 'Test'])
        ->assertStatus(422)->assertJsonPath('code', 'refund_unsupported');
});

it('only lets staff with payments.refund issue refunds', function () {
    $p = Payment::factory()->create();
    $this->actingAs(User::factory()->instructor()->create())->postJson(api("admin/payments/{$p->uuid}/refund"), ['reason' => 'x'])->assertForbidden();
});

it('lists subscriptions with status counts and shows one with its payments', function () {
    $u = User::factory()->create();
    $sub = subscribe($u, MembershipTier::Pro);
    subscribe(User::factory()->create(), MembershipTier::Starter, ['cancel_at_period_end' => true]);
    Payment::factory()->create(['user_id' => $u->id, 'subscription_id' => $sub->id]);

    $this->actingAs($this->admin)->getJson(api('admin/subscriptions?tier=2'))->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user.id', $u->uuid)->assertJsonPath('meta.counts.active', 2);
    $this->actingAs($this->admin)->getJson(api('admin/subscriptions?renewing=0'))->assertJsonCount(1, 'data');
    $this->actingAs($this->admin)->getJson(api('admin/subscriptions/'.$sub->uuid))->assertOk()->assertJsonCount(1, 'data.payments');
});

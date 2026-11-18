<?php

use App\Enums\MembershipTier;
use App\Models\CoursePurchase;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    $this->course = publishedCourse(3, 2, ['price_amount' => 299900, 'currency' => 'INR']);
    $this->buyer = User::factory()->create(['country' => 'IN']);
});

it('sells a single course via a Razorpay order and grants access on order.paid', function () {
    Http::fake(['api.razorpay.com/v1/orders' => Http::response(['id' => 'order_C1', 'amount' => 299900, 'status' => 'created'])]);

    $this->actingAs($this->buyer)->postJson(api("courses/{$this->course->slug}/purchase"))
        ->assertCreated()->assertJsonPath('data.razorpay.order_id', 'order_C1')->assertJsonPath('data.razorpay.amount', 299900);
    Http::assertSent(fn ($r) => ($r['notes']['course_uuid'] ?? null) === $this->course->uuid);

    postRazorpayWebhook(json_encode(['event' => 'order.paid', 'payload' => [
        'payment' => ['entity' => ['id' => 'pay_C1', 'amount' => 299900, 'currency' => 'INR', 'status' => 'captured', 'notes' => []]],
        'order' => ['entity' => ['id' => 'order_C1', 'amount' => 299900, 'amount_paid' => 299900, 'currency' => 'INR', 'status' => 'paid',
            'notes' => ['kind' => 'course', 'user_uuid' => $this->buyer->uuid, 'course_uuid' => $this->course->uuid]]],
    ]]))->assertOk();

    expect(CoursePurchase::where('user_id', $this->buyer->id)->exists())->toBeTrue()
        ->and(Payment::firstOrFail())->course_id->toBe($this->course->id)->description->toBe('Course: '.$this->course->title);
    $this->actingAs($this->buyer)->getJson(api("courses/{$this->course->slug}"))->assertJsonPath('data.access.reason', 'purchase');
});

it('sells via Stripe Checkout (inline price) for non-INR courses and completes on the webhook', function () {
    $course = publishedCourse(3, 1, ['price_amount' => 4900, 'currency' => 'USD']);
    $stripe = fakeStripe()
        ->on('POST', '/v1/customers', ['id' => 'cus_X', 'object' => 'customer'])
        ->on('POST', '/v1/checkout/sessions', ['id' => 'cs_c', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c']);

    $this->actingAs($this->buyer)->postJson(api("courses/{$course->slug}/purchase"))->assertCreated()->assertJsonPath('data.gateway', 'stripe');
    $params = $stripe->last('POST', '/v1/checkout/sessions')['params'];
    expect($params['mode'])->toBe('payment')->and($params['line_items'][0]['price_data']['unit_amount'])->toBe(4900)
        ->and($params['metadata']['course_uuid'])->toBe($course->uuid);

    postStripeWebhook(json_encode(['id' => 'evt_c1', 'type' => 'checkout.session.completed', 'data' => ['object' => [
        'id' => 'cs_c', 'mode' => 'payment', 'payment_status' => 'paid', 'payment_intent' => 'pi_c1', 'customer' => 'cus_X',
        'client_reference_id' => $this->buyer->uuid, 'amount_total' => 4900, 'currency' => 'usd',
        'metadata' => ['kind' => 'course', 'user_uuid' => $this->buyer->uuid, 'course_uuid' => $course->uuid],
    ]]]))->assertOk();

    expect(CoursePurchase::where('course_id', $course->id)->where('user_id', $this->buyer->id)->exists())->toBeTrue();
});

it('refuses purchase when already entitled or not for sale', function () {
    $elite = User::factory()->create();
    subscribe($elite, MembershipTier::Elite);
    $this->actingAs($elite)->postJson(api("courses/{$this->course->slug}/purchase"))->assertStatus(409)->assertJsonPath('code', 'already_has_access');

    $free = publishedCourse(1, 1);
    $this->actingAs($this->buyer)->postJson(api("courses/{$free->slug}/purchase"))->assertJsonPath('code', 'not_for_sale');
});

it('allows one review per enrolled learner and keeps the average up to date', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    foreach ([$a, $b] as $u) {
        subscribe($u, MembershipTier::Elite);
    }
    $lesson = $this->course->sections[0]->lessons[1];

    $this->actingAs($a)->postJson(api("courses/{$this->course->slug}/reviews"), ['rating' => 5])->assertForbidden(); // not started

    foreach ([[$a, 5, null], [$b, 3, 'Useful']] as [$u, $r, $comment]) {
        $this->actingAs($u)->getJson(api("lessons/{$lesson->uuid}/playback"))->assertOk();
        $this->actingAs($u)->postJson(api("courses/{$this->course->slug}/reviews"), ['rating' => $r, 'comment' => $comment])->assertCreated();
    }
    $this->actingAs($b)->postJson(api("courses/{$this->course->slug}/reviews"), ['rating' => 4])->assertOk(); // update, not duplicate

    expect($this->course->fresh())->rating_count->toBe(2)->rating_avg->toBe('4.50');
    $this->getJson(api("courses/{$this->course->slug}/reviews"))->assertJsonCount(1, 'data'); // only reviews with comments listed
});

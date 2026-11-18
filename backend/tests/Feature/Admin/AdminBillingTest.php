<?php

use App\Enums\MembershipTier;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\Billing\SubscriptionActivatedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => $this->admin = User::factory()->admin()->create());

it('creates a plan, adds prices and prevents duplicate active prices', function () {
    $plan = $this->actingAs($this->admin)->postJson(api('admin/plans'), [
        'name' => 'Pro', 'slug' => 'pro-x', 'tier_level' => 2,
        'features' => [['key' => 'live', 'label' => 'Live classes', 'value' => 'Monthly']],
    ])->assertCreated()->json('data.id');

    $this->actingAs($this->admin)->postJson(api("admin/plans/{$plan}/prices"), ['interval' => 'month', 'currency' => 'INR', 'amount' => 99900, 'razorpay_plan_id' => 'plan_ABC'])
        ->assertCreated()->assertJsonPath('data.razorpay_plan_id', 'plan_ABC');
    $this->actingAs($this->admin)->postJson(api("admin/plans/{$plan}/prices"), ['interval' => 'month', 'currency' => 'INR', 'amount' => 89900])
        ->assertJsonValidationErrors('interval');
    $this->actingAs($this->admin)->postJson(api("admin/plans/{$plan}/prices"), ['interval' => 'lifetime', 'currency' => 'USD', 'amount' => 79900, 'trial_days' => 7])
        ->assertJsonValidationErrors('trial_days');
});

it('validates tier_level and features', function () {
    $this->actingAs($this->admin)->postJson(api('admin/plans'), ['name' => 'X', 'slug' => 'x', 'tier_level' => 4, 'features' => [['label' => 'no key']]])
        ->assertJsonValidationErrors(['tier_level', 'features.0.key']);
});

it('updates a price but never its interval/currency', function () {
    $price = planFor(MembershipTier::Pro);

    $this->actingAs($this->admin)->patchJson(api("admin/plans/{$price->plan->uuid}/prices/{$price->uuid}"), ['amount' => 123400, 'currency' => 'USD'])
        ->assertJsonValidationErrors('currency');
    $this->actingAs($this->admin)->patchJson(api("admin/plans/{$price->plan->uuid}/prices/{$price->uuid}"), ['amount' => 123400])
        ->assertOk()->assertJsonPath('data.price.amount', 123400);
});

it('manages coupons with plan scope', function () {
    $plan = Plan::factory()->create();

    $id = $this->actingAs($this->admin)->postJson(api('admin/coupons'), [
        'code' => 'diwali', 'type' => 'percent', 'value' => 30, 'max_uses' => 100, 'plan_ids' => [$plan->uuid],
    ])->assertCreated()->assertJsonPath('data.code', 'DIWALI')->assertJsonPath('data.plan_ids', [$plan->uuid])->json('data.id');

    $this->actingAs($this->admin)->postJson(api('admin/coupons'), ['code' => 'BAD', 'type' => 'percent', 'value' => 150])->assertJsonValidationErrors('value');
    $this->actingAs($this->admin)->postJson(api('admin/coupons'), ['code' => 'FIX', 'type' => 'fixed', 'value' => 500])->assertJsonValidationErrors('currency');
    $this->actingAs($this->admin)->patchJson(api("admin/coupons/{$id}"), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->actingAs($this->admin)->deleteJson(api("admin/coupons/{$id}"))->assertNoContent();
    expect(Coupon::count())->toBe(0);
});

it('grants and revokes a complimentary membership', function () {
    Notification::fake();
    $student = User::factory()->student()->create();
    $plan = Plan::factory()->tier(MembershipTier::Elite)->create();

    $sub = $this->actingAs($this->admin)->postJson(api("admin/users/{$student->uuid}/subscriptions"), ['plan_id' => $plan->uuid, 'notes' => 'Scholarship'])
        ->assertCreated()->assertJsonPath('data.is_complimentary', true)->assertJsonPath('data.current_period_end', null)->json('data.id');

    expect($student->fresh()->currentTier())->toBe(MembershipTier::Elite);
    Notification::assertSentTo($student, SubscriptionActivatedNotification::class);

    $this->actingAs($this->admin)->postJson(api("admin/users/{$student->uuid}/subscriptions/{$sub}/cancel"))->assertOk();
    expect($student->fresh()->currentTier())->toBeNull();
});

it('will not revoke paid subscriptions from the admin panel', function () {
    $student = User::factory()->create();
    $sub = subscribe($student, MembershipTier::Pro);

    $this->actingAs($this->admin)->postJson(api("admin/users/{$student->uuid}/subscriptions/{$sub->uuid}/cancel"))->assertStatus(409);
});

it('forbids students from billing admin', function () {
    $this->actingAs(User::factory()->student()->create())->getJson(api('admin/coupons'))->assertForbidden();
});

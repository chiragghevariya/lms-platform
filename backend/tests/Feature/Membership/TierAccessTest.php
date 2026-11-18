<?php

use App\Contracts\GatedContent;
use App\Enums\MembershipTier;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'tier:2'])->get('/api/v1/_test/pro-only', fn () => ['ok' => true]);
});

function content(?MembershipTier $tier): GatedContent
{
    return new class($tier) implements GatedContent
    {
        public function __construct(private ?MembershipTier $tier) {}

        public function requiredTier(): ?MembershipTier
        {
            return $this->tier;
        }
    };
}

it('reports access for each tier via /me/access', function (?MembershipTier $have, string $need, bool $expected) {
    $user = User::factory()->student()->create();
    if ($have) {
        subscribe($user, $have);
    }

    $this->actingAs($user)->getJson(api("me/access?tier={$need}"))
        ->assertOk()
        ->assertJsonPath('data.has_access', $expected)
        ->assertJsonPath('data.current_tier', $have?->slug());
})->with([
    'none → starter' => [null, 'starter', false],
    'starter → starter' => [MembershipTier::Starter, 'starter', true],
    'starter → pro' => [MembershipTier::Starter, 'pro', false],
    'pro → level 1' => [MembershipTier::Pro, '1', true],
    'elite → pro' => [MembershipTier::Elite, 'pro', true],
    'pro → elite' => [MembershipTier::Pro, 'elite', false],
]);

it('enforces tier:2 with a 402 for lower tiers and passes higher tiers and staff', function () {
    $starter = User::factory()->student()->create();
    subscribe($starter, MembershipTier::Starter);
    $elite = User::factory()->student()->create();
    subscribe($elite, MembershipTier::Elite);

    $this->actingAs($starter)->getJson('/api/v1/_test/pro-only')
        ->assertStatus(402)->assertJsonPath('code', 'membership_required')->assertJsonPath('required_tier', 2);
    $this->actingAs($elite)->getJson('/api/v1/_test/pro-only')->assertOk();
    $this->actingAs(User::factory()->admin()->create())->getJson('/api/v1/_test/pro-only')->assertOk();
});

it('decides access for content that declares required_tier (service + gate)', function () {
    $pro = User::factory()->student()->create();
    subscribe($pro, MembershipTier::Pro);
    $svc = app(MembershipService::class);

    expect($svc->canAccess($pro, content(MembershipTier::Starter)))->toBeTrue()
        ->and($svc->canAccess($pro, content(MembershipTier::Elite)))->toBeFalse()
        ->and($svc->canAccess($pro, content(null)))->toBeTrue()
        ->and($svc->canAccess(null, content(MembershipTier::Starter)))->toBeFalse()
        ->and(Gate::forUser($pro)->allows('access-content', [content(MembershipTier::Pro)]))->toBeTrue()
        ->and(Gate::forUser($pro)->allows('access-content', [content(MembershipTier::Elite)]))->toBeFalse();
});

it('keeps access during the grace period, not after', function () {
    $user = User::factory()->student()->create();
    subscribe($user, MembershipTier::Pro, ['status' => 'past_due', 'past_due_since' => now()->subDays(2)]);

    expect($user->fresh()->canAccessTier(MembershipTier::Pro))->toBeTrue();

    $this->travel(2)->days();
    expect($user->fresh()->canAccessTier(MembershipTier::Pro))->toBeFalse();
});

it('ignores ended subscriptions and picks the highest live tier', function () {
    $user = User::factory()->student()->create();
    Subscription::factory()->expired()->for($user)->forPrice(planFor(MembershipTier::Elite))->create();
    subscribe($user, MembershipTier::Starter);
    subscribe($user, MembershipTier::Pro);

    expect($user->fresh()->currentTier())->toBe(MembershipTier::Pro);
});

it('grants lifetime and open-ended complimentary access', function () {
    $lifetime = User::factory()->create();
    subscribe($lifetime, MembershipTier::Elite, ['is_lifetime' => true, 'current_period_end' => null]);
    $comp = User::factory()->create();
    Subscription::factory()->complimentary()->for($comp)->forPrice(planFor(MembershipTier::Pro))->create(['gateway' => 'manual', 'plan_price_id' => null]);

    expect($lifetime->fresh()->currentTier())->toBe(MembershipTier::Elite)
        ->and($comp->fresh()->currentTier())->toBe(MembershipTier::Pro);
});

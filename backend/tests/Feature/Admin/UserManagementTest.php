<?php

use App\Admin\Impersonation;
use App\Enums\MembershipTier;
use App\Enums\UserStatus;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

it('filters users by status, tier, country and verification', function () {
    $pro = User::factory()->create(['country' => 'IN', 'name' => 'Priya Pro']);
    subscribe($pro, MembershipTier::Pro);
    User::factory()->create(['country' => 'US', 'status' => UserStatus::Suspended]);
    User::factory()->unverified()->create(['country' => 'IN']);

    $get = fn (string $q) => $this->actingAs($this->admin)->getJson(api('admin/users?'.$q))->assertOk();

    $get('tier=2')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Priya Pro')->assertJsonPath('data.0.membership.tier', 2);
    $get('status=suspended')->assertJsonCount(1, 'data')->assertJsonPath('data.0.country', 'US');
    $get('country=in&verified=0')->assertJsonCount(1, 'data');
    $get('search=priya')->assertJsonCount(1, 'data');
    expect($get('tier=none')->json('meta.total'))->toBe(User::count() - 1);
});

it('shows a user detail with subscriptions, payments and stats', function () {
    $u = User::factory()->create();
    subscribe($u, MembershipTier::Pro);

    $this->actingAs($this->admin)->getJson(api('admin/users/'.$u->uuid))->assertOk()
        ->assertJsonPath('data.id', $u->uuid)
        ->assertJsonCount(1, 'data.subscriptions')
        ->assertJsonStructure(['data' => ['stats' => ['enrollments', 'watch_seconds', 'lifetime_value'], 'logins', 'activity', 'payments']]);
});

it('suspends a user, signs them out everywhere and audits it', function () {
    $u = User::factory()->create();
    $token = $u->createToken('phone')->plainTextToken;

    $this->actingAs($this->admin)->postJson(api("admin/users/{$u->uuid}/status"), ['status' => 'suspended'])
        ->assertJsonValidationErrors('reason');
    $this->actingAs($this->admin)->postJson(api("admin/users/{$u->uuid}/status"), ['status' => 'suspended', 'reason' => 'Chargeback abuse'])
        ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_reason', 'Chargeback abuse');

    expect($u->tokens()->count())->toBe(0);
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson(api('me'))->assertUnauthorized();

    $log = Activity::where('event', 'user.status')->latest('id')->first();
    expect($log->causer_id)->toBe($this->admin->id)->and($log->properties['new'])->toBe('suspended');

    $this->actingAs($this->admin)->postJson(api("admin/users/{$u->uuid}/status"), ['status' => 'active'])
        ->assertOk()->assertJsonPath('data.status_reason', null);
});

it('never lets admins suspend staff or themselves, in single or bulk actions', function () {
    $other = User::factory()->admin()->create();
    $a = User::factory()->create();
    $b = User::factory()->create();

    $this->actingAs($this->admin)->postJson(api("admin/users/{$other->uuid}/status"), ['status' => 'banned', 'reason' => 'x'])->assertForbidden();
    $this->actingAs($this->admin)->postJson(api("admin/users/{$this->admin->uuid}/status"), ['status' => 'banned', 'reason' => 'x'])->assertForbidden();

    $this->actingAs($this->admin)->postJson(api('admin/users/bulk'), [
        'action' => 'suspend', 'reason' => 'Spam wave', 'ids' => [$a->uuid, $b->uuid, $other->uuid, $this->admin->uuid],
    ])->assertOk()->assertJsonPath('data.updated', 2)->assertJsonPath('data.skipped', 2);

    expect($a->fresh()->status)->toBe(UserStatus::Suspended)->and($other->fresh()->status)->toBe(UserStatus::Active);
});

it('sends a password reset link on behalf of the user', function () {
    Notification::fake();
    $u = User::factory()->create();

    $this->actingAs($this->admin)->postJson(api("admin/users/{$u->uuid}/password-reset"))->assertOk();
    Notification::assertSentTo($u, QueuedResetPassword::class);
    expect(Activity::where('event', 'user.password_reset')->exists())->toBeTrue();
});

it('lets an admin view as a user and come back, blocking sensitive actions meanwhile', function () {
    $u = User::factory()->create(['name' => 'Learner Lee']);
    $spa = fn () => $this->withHeader('Origin', 'http://localhost:5180');

    $spa()->actingAs($this->admin, 'web')->postJson(api("admin/users/{$u->uuid}/impersonate"))
        ->assertOk()->assertJsonPath('data.id', $u->uuid)
        ->assertJsonPath('data.impersonator.name', $this->admin->name) // the SPA shows the banner straight away
        ->assertSessionHas(Impersonation::KEY, $this->admin->id);
    $this->assertAuthenticatedAs($u, 'web');

    // Next requests carry the session: /me says who is watching.
    $as = function () use ($spa, $u) {
        $this->app['auth']->forgetGuards(); // the sanctum guard memoises the previous request's user

        return $spa()->withSession([Impersonation::KEY => $this->admin->id])->actingAs($u, 'web');
    };
    $as()->getJson(api('me'))->assertOk()
        ->assertJsonPath('data.id', $u->uuid)
        ->assertJsonPath('data.impersonator.name', $this->admin->name);

    // Credentials, billing and the admin API are off-limits while impersonating.
    $as()->putJson(api('me/password'), ['current_password' => 'password', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
        ->assertForbidden()->assertJsonPath('code', 'impersonating');
    $as()->postJson(api('billing/subscription/cancel'))->assertForbidden()->assertJsonPath('code', 'impersonating');
    $as()->getJson(api('me/notifications'))->assertOk(); // reading is fine

    $as()->postJson(api('impersonation/stop'))->assertOk()->assertJsonPath('data.id', $this->admin->uuid)->assertSessionMissing(Impersonation::KEY);
    $this->assertAuthenticatedAs($this->admin, 'web');

    expect(Activity::whereIn('event', ['impersonation.started', 'impersonation.stopped'])->count())->toBe(2);
    $this->app['auth']->forgetGuards();
    $this->flushSession();
    $spa()->actingAs($u, 'web')->postJson(api('impersonation/stop'))->assertJsonValidationErrors('user');
});

it('refuses to impersonate staff, suspended users, or from an API token', function () {
    $staff = User::factory()->instructor()->create();
    $staff->assignRole('admin');
    $suspended = User::factory()->create(['status' => UserStatus::Suspended]);
    $u = User::factory()->create();

    $this->withHeader('Origin', 'http://localhost:5180')->actingAs($this->admin, 'web')->postJson(api("admin/users/{$staff->uuid}/impersonate"))->assertJsonValidationErrors('user');
    $this->actingAs($this->admin, 'web')->postJson(api("admin/users/{$suspended->uuid}/impersonate"))->assertJsonValidationErrors('user');

    $token = $this->admin->createToken('cli')->plainTextToken;
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Origin' => ''])->withToken($token)->postJson(api("admin/users/{$u->uuid}/impersonate"))->assertJsonValidationErrors('user');
});

it('audits role changes with before and after', function () {
    $u = User::factory()->create();
    $this->actingAs($this->admin)->putJson(api("admin/users/{$u->uuid}/roles"), ['roles' => ['instructor']])->assertOk();

    $log = Activity::where('event', 'user.roles')->first();
    expect($log->properties['old'])->toBe([])->and($log->properties['new'])->toBe(['instructor']);
});

it('gates every admin area by permission', function () {
    $student = User::factory()->create();
    foreach (['admin/users', 'admin/payments', 'admin/subscriptions', 'admin/reviews', 'admin/cms/faqs', 'admin/settings', 'admin/audit-log', 'admin/analytics/overview'] as $path) {
        $this->actingAs($student)->getJson(api($path))->assertForbidden();
        $this->actingAs($this->admin)->getJson(api($path))->assertOk();
    }
});

it('gives admins every permission except role assignment and secrets', function () {
    $perms = $this->actingAs($this->admin)->getJson(api('me'))->json('data.permissions');
    expect($perms)->toContain('payments.refund', 'cms.manage', 'analytics.view', 'users.impersonate')
        ->not->toContain('settings.secrets', 'roles.assign');
});

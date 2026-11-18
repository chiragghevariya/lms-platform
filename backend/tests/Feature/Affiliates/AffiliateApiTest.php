<?php

use App\Affiliates\Identity;
use App\Models\Affiliate;
use App\Models\User;

it('lets a member apply, an admin approve, and the affiliate use the dashboard', function () {
    $user = User::factory()->student()->create(['name' => 'Ravi Kumar']);
    $admin = User::factory()->admin()->create();

    $this->actingAs($user)->postJson(api('me/affiliate'), ['promotion_plan' => 'short'])->assertJsonValidationErrors(['promotion_plan', 'accept_terms']);
    $this->actingAs($user)->postJson(api('me/affiliate'), ['promotion_plan' => str_repeat('YouTube reviews of courses. ', 2), 'accept_terms' => true, 'website' => 'https://ravi.example'])
        ->assertCreated()->assertJsonPath('data.status', 'pending');
    $a = Affiliate::firstOrFail();
    expect($a->code)->toStartWith('RAVIKU');
    $this->actingAs($user)->getJson(api('me/affiliate/stats'))->assertForbidden(); // not approved yet

    $this->actingAs($admin)->getJson(api('admin/affiliates?status=pending'))->assertJsonPath('data.0.user.email', $user->email);
    $this->actingAs($admin)->postJson(api("admin/affiliates/{$a->uuid}/approve"))->assertJsonPath('data.status', 'approved');
    $this->actingAs($admin)->patchJson(api("admin/affiliates/{$a->uuid}/terms"), ['commission_type' => 'percent', 'commission_rate' => 25])
        ->assertJsonPath('data.terms.rate', 25);

    $this->actingAs($user)->getJson(api('me/affiliate'))->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.link', config('lms.frontend_url').'/?ref='.$a->code)->assertJsonPath('data.terms.rate', 25)
        ->assertJsonPath('data.terms.hold_days', 14);
    $this->actingAs($user)->getJson(api('me'))->assertJsonPath('data.affiliate.active', true)->assertJsonPath('data.affiliate.code', $a->code);

    $this->actingAs($user)->putJson(api('me/affiliate/payout'), ['payout_method' => 'upi', 'payout_details' => ['upi_id' => 'ravi@okhdfc']])
        ->assertJsonPath('data.summary', 'ra•••@okhdfc');

    app('auth')->forgetGuards(); // visitors are logged out
    clickRef($a->code, ['utm' => ['utm_campaign' => 'launch']]);
    clickRef($a->code, ['utm' => ['utm_campaign' => 'launch']], '203.0.113.11');
    $this->actingAs($user)->getJson(api('me/affiliate/stats?days=7'))
        ->assertJsonPath('data.funnel.clicks', 2)->assertJsonPath('data.funnel.unique_visitors', 2)
        ->assertJsonPath('data.campaigns.0', ['campaign' => 'launch', 'clicks' => 2])
        ->assertJsonCount(7, 'data.daily');

    $assets = $this->actingAs($user)->getJson(api('me/affiliate/assets'))->json('data');
    expect($assets['swipe_copy'][0]['body'])->toContain('?ref='.$a->code)->and($assets['banners'])->toHaveCount(3);
    $this->get(api("affiliate-banners/300x250/{$a->code}.svg"))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->get(api('affiliate-banners/300x250/NOPE.svg'))->assertNotFound();

    $this->actingAs($admin)->postJson(api("admin/affiliates/{$a->uuid}/suspend"), ['notes' => 'Spam'])->assertJsonPath('data.status', 'suspended');
    app('auth')->forgetGuards();
    expect(clickRef($a->code))->toBeNull();
    $this->actingAs($user)->getJson(api('admin/affiliates'))->assertForbidden();
});

it('records device hashes on login for the fraud checks', function () {
    $u = User::factory()->create(['email' => 'l@example.com', 'password' => 'Password123']);
    $this->withHeader('X-Device-Id', 'device-abcdef-123456')->postJson(api('auth/login'), ['email' => 'l@example.com', 'password' => 'Password123'])->assertOk();
    expect($u->loginActivities()->latest('id')->value('device_hash'))->toBe(Identity::hash('device-abcdef-123456'));
});

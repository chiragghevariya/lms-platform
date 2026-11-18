<?php

use App\Enums\MembershipTier;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\Subscription;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->student()->create();
    subscribe($this->user, MembershipTier::Pro);
});

it('enforces capacity under concurrent registrations', function () {
    $session = LiveSession::factory()->create(['capacity' => 1]);
    $other = User::factory()->student()->create();
    subscribe($other, MembershipTier::Pro);

    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/registration"))->assertCreated()->assertJsonPath('data.seats_left', 0);
    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/registration"))->assertOk(); // idempotent
    $this->actingAs($other)->postJson(api("live-sessions/{$session->uuid}/registration"))->assertJsonValidationErrors('session');
    $this->actingAs($other)->getJson(api("live-sessions/{$session->uuid}"))->assertJsonPath('data.viewer.can_register', false);
});

it('lets learners leave before the start but not after', function () {
    $session = LiveSession::factory()->create();
    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/registration"));
    $this->actingAs($this->user)->deleteJson(api("live-sessions/{$session->uuid}/registration"))->assertNoContent();
    expect(LiveRegistration::count())->toBe(0);

    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/registration"));
    $session->forceFill(['status' => 'live'])->save();
    $this->actingAs($this->user)->deleteJson(api("live-sessions/{$session->uuid}/registration"))->assertJsonValidationErrors('session');
});

it('requires registration and an active tier to join; external links count as attendance', function () {
    $session = LiveSession::factory()->tier(2)->create(['starts_at' => now()->addMinutes(5)]);
    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/join"))->assertJsonValidationErrors('session');

    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/registration"));
    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/join"))
        ->assertOk()->assertJsonPath('data.mode', 'external')->assertJsonPath('data.url', 'https://meet.google.com/abc-defg-hij')
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(LiveRegistration::first())->attended->toBeTrue()->joined_at->not->toBeNull();

    // Membership lapses → no join even though registered.
    Subscription::where('user_id', $this->user->id)->update(['status' => 'expired']);
    $this->actingAs($this->user->fresh())->postJson(api("live-sessions/{$session->uuid}/join"))->assertStatus(402);
});

it('falls back to the Zoom link when Meeting SDK keys are missing', function () {
    config(['live.zoom.sdk_key' => null]);
    $session = LiveSession::factory()->zoom()->create(['starts_at' => now()->addMinutes(5)]);
    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/registration"));
    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/join"))
        ->assertJsonPath('data.mode', 'external')->assertJsonPath('data.url', 'https://zoom.us/j/8811223344?pwd=x');
    expect(LiveRegistration::first()->attended)->toBeFalse(); // Zoom attendance comes from the report
});

it('lets the instructor join their own session without a membership', function () {
    $session = LiveSession::factory()->tier(3)->create();
    $this->actingAs($session->instructor)->getJson(api("live-sessions/{$session->uuid}"))
        ->assertJsonPath('data.viewer.eligible', true)->assertJsonPath('data.viewer.can_manage', true);
});

it('lists by range with filters and shows my registrations on the dashboard', function () {
    $mine = LiveSession::factory()->tier(2)->create(['title' => 'Pro clinic', 'starts_at' => now()->addDay()]);
    LiveSession::factory()->tier(3)->create(['title' => 'Elite mastermind', 'starts_at' => now()->addDays(2)]);
    LiveSession::factory()->create(['title' => 'Next month', 'starts_at' => now()->addDays(40)]);
    LiveSession::factory()->create(['title' => 'Over', 'starts_at' => now()->subDays(2)]);
    $this->actingAs($this->user)->postJson(api("live-sessions/{$mine->uuid}/registration"));

    $all = $this->actingAs($this->user)->getJson(api('live-sessions'))->assertOk();
    expect(array_column($all->json('data'), 'title'))->toBe(['Pro clinic', 'Elite mastermind', 'Next month'])
        ->and($all->json('data.1.viewer.eligible'))->toBeFalse()
        ->and($all->json('data.0.viewer.registered'))->toBeTrue();

    $this->actingAs($this->user)->getJson(api('live-sessions?tier=elite'))->assertJsonCount(1, 'data');
    $this->actingAs($this->user)->getJson(api('live-sessions?mine=1'))->assertJsonCount(1, 'data');
    $range = now()->subDays(3)->toDateString();
    $this->actingAs($this->user)->getJson(api("live-sessions?from={$range}&to=".now()->addDays(3)->toDateString()))->assertJsonCount(3, 'data'); // incl. past
    $this->actingAs($this->user)->getJson(api('live-sessions?from=2026-01-01&to=2026-12-31'))->assertStatus(422);

    $this->actingAs($this->user)->getJson(api('me/dashboard'))->assertJsonPath('data.upcoming_live_classes.0.title', 'Pro clinic');
});

it('serves calendar files and a Google Calendar link without secrets', function () {
    $session = LiveSession::factory()->zoom()->create(['title' => 'Ads, budgets; and more', 'starts_at' => '2026-12-01 14:00:00', 'duration' => 90]);
    $this->travelTo('2026-11-20');

    $ics = $this->get(api("live-sessions/{$session->uuid}/calendar.ics"))->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();
    expect($ics)->toContain("DTSTART:20261201T140000Z\r\n")->toContain('DTEND:20261201T153000Z')
        ->toContain('SUMMARY:Ads\, budgets\; and more')->toContain('TRIGGER:-PT15M')
        ->not->toContain('zoom.us')->not->toContain('p4ss');
    foreach (explode("\r\n", $ics) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75);
    }

    $google = $this->actingAs($this->user)->getJson(api("live-sessions/{$session->uuid}"))->json('data.calendar.google');
    parse_str((string) parse_url($google, PHP_URL_QUERY), $q);
    expect($q)->toMatchArray(['action' => 'TEMPLATE', 'dates' => '20261201T140000Z/20261201T153000Z', 'ctz' => 'Asia/Kolkata']);

    $this->actingAs($this->user)->postJson(api("live-sessions/{$session->uuid}/registration"));
    $this->actingAs($this->user)->get(api('live-sessions/mine.ics'))->assertOk()->assertSee($session->uuid.'@', false);
});

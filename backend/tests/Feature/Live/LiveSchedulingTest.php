<?php

use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\Live\LiveSessionCancelledNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->instructor = User::factory()->instructor()->create();
});

it('creates a weekly series at the same local time across a DST change', function () {
    fakeZoom();
    $this->travelTo('2026-10-20 12:00:00'); // New York switches to EST on Sun 1 Nov 2026
    $res = $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody([
        'starts_at' => '2026-10-27T18:00', 'timezone' => 'America/New_York',
        'repeat' => ['weekdays' => [2, 4], 'occurrences' => 4], // Tue + Thu
    ]))->assertCreated();

    $sessions = LiveSession::orderBy('starts_at')->get();
    expect($sessions)->toHaveCount(4)
        ->and($sessions->map(fn ($s) => $s->starts_at->copy()->setTimezone('America/New_York')->format('D d H:i'))->all())
        ->toBe(['Tue 27 18:00', 'Thu 29 18:00', 'Tue 03 18:00', 'Thu 05 18:00'])
        ->and($sessions[0]->starts_at->format('H:i'))->toBe('22:00') // UTC-4 (EDT)
        ->and($sessions[2]->starts_at->format('H:i'))->toBe('23:00') // UTC-5 (EST)
        ->and($sessions->pluck('series_id')->unique())->toHaveCount(1)
        ->and($sessions->pluck('meeting_id')->unique())->toHaveCount(4);
    expect($res->json('data.0.is_series'))->toBeTrue();
});

it('rolls back remote meetings when one occurrence fails', function () {
    $n = 0;
    Http::fake([
        'zoom.us/oauth/token' => Http::response(['access_token' => 't']),
        'api.zoom.us/v2/users/me/meetings' => function () use (&$n) {
            return ++$n < 3 ? Http::response(['id' => 900 + $n, 'join_url' => 'https://zoom.us/j/1', 'start_url' => 'https://zoom.us/s/1'], 201)
                : Http::response(['code' => 1001, 'message' => 'User does not exist'], 404);
        },
        'api.zoom.us/v2/meetings/*' => Http::response(null, 204),
    ]);

    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['repeat' => ['weekdays' => [1, 2, 3, 4, 5, 6, 7], 'occurrences' => 5]]))
        ->assertJsonValidationErrors('provider');

    expect(LiveSession::withTrashed()->count())->toBe(0);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/meetings/901'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/meetings/902'));
});

it('validates input and refuses unconfigured providers', function () {
    config(['live.zoom.client_id' => null]);
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody())->assertJsonValidationErrors('provider');
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['provider' => 'agora']))->assertJsonValidationErrors('provider');
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['provider' => 'external_link']))->assertJsonValidationErrors('join_url');
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['provider' => 'external_link', 'join_url' => 'http://meet.google.com/x']))->assertJsonValidationErrors('join_url');
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['provider' => 'external_link', 'join_url' => 'https://meet.google.com/x', 'starts_at' => '2020-01-01T10:00']))->assertJsonValidationErrors('starts_at');
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['provider' => 'external_link', 'join_url' => 'https://meet.google.com/x', 'timezone' => 'Mars/Base']))->assertJsonValidationErrors('timezone');
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['provider' => 'external_link', 'join_url' => 'https://meet.google.com/x', 'course_id' => courseWith(['text'])->uuid]))->assertJsonValidationErrors('course_id');

    $this->actingAs($this->instructor)->getJson(api('instructor/live-sessions/options'))->assertJsonPath('data.providers', ['external_link']);
    $this->actingAs(User::factory()->student()->create())->postJson(api('instructor/live-sessions'), liveBody())->assertForbidden();
});

it('accepts legacy timezone aliases that browsers report', function () {
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody([
        'provider' => 'external_link', 'join_url' => 'https://meet.google.com/x', 'timezone' => 'Asia/Calcutta', 'starts_at' => now('Asia/Kolkata')->addDay()->format('Y-m-d').'T19:00',
    ]))->assertCreated()->assertJsonPath('data.0.timezone', 'Asia/Calcutta');
    expect(LiveSession::first()->starts_at->format('H:i'))->toBe('13:30'); // 19:00 IST = 13:30 UTC
});

it('forces mentorship sessions to one seat and inherits the course tier', function () {
    fakeZoom();
    $course = courseWith(['text'], 3);
    $course->update(['instructor_id' => $this->instructor->id]);
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['type' => 'mentorship_1on1', 'capacity' => 50, 'required_tier' => null, 'course_id' => $course->uuid]))
        ->assertCreated()->assertJsonPath('data.0.capacity', 1)->assertJsonPath('data.0.required_tier', 'elite');
});

it('pushes edits to Zoom and re-arms reminders on reschedule', function () {
    fakeZoom();
    $session = LiveSession::factory()->zoom()->create(['instructor_id' => $this->instructor->id]);
    $reg = LiveRegistration::create(['live_session_id' => $session->id, 'user_id' => User::factory()->create()->id]);
    $reg->forceFill(['reminded_24h_at' => now()])->save();

    $this->actingAs($this->instructor)->patchJson(api("instructor/live-sessions/{$session->uuid}"), [
        'title' => 'New title', 'starts_at' => now('Asia/Kolkata')->addDays(3)->format('Y-m-d\TH:i'),
    ])->assertOk()->assertJsonPath('data.title', 'New title');

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/meetings/8811223344') && $r['topic'] === 'New title');
    expect($reg->fresh()->reminded_24h_at)->toBeNull();

    $this->actingAs(User::factory()->instructor()->create())->patchJson(api("instructor/live-sessions/{$session->uuid}"), ['title' => 'x'])->assertForbidden();
    $this->actingAs($this->instructor)->patchJson(api("instructor/live-sessions/{$session->uuid}"), ['capacity' => 0])->assertJsonValidationErrors('capacity');
});

it('cancels this and following occurrences, deletes meetings and emails registrants', function () {
    Notification::fake();
    fakeZoom();
    $this->actingAs($this->instructor)->postJson(api('instructor/live-sessions'), liveBody(['repeat' => ['weekdays' => [1, 3, 5], 'occurrences' => 3]]))->assertCreated();
    [$first, $second, $third] = LiveSession::orderBy('starts_at')->get()->all();
    $learner = User::factory()->create();
    LiveRegistration::create(['live_session_id' => $second->id, 'user_id' => $learner->id]);

    $this->actingAs($this->instructor)->postJson(api("instructor/live-sessions/{$second->uuid}/cancel"), ['reason' => 'Instructor unwell', 'following' => true])
        ->assertJsonPath('data.cancelled', 2);

    expect($first->fresh()->status->value)->toBe('scheduled')
        ->and($second->fresh())->status->value->toBe('cancelled')->cancel_reason->toBe('Instructor unwell')
        ->and($third->fresh()->status->value)->toBe('cancelled');
    Notification::assertSentTo($learner, LiveSessionCancelledNotification::class);
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/meetings/'.$third->meeting_id));
    $this->actingAs($learner)->postJson(api("live-sessions/{$second->uuid}/registration"))->assertJsonValidationErrors('session');
});

it('gives the host a fresh start link', function () {
    fakeZoom(['api.zoom.us/v2/meetings/8811223344' => Http::response(['start_url' => 'https://zoom.us/s/8811223344?zak=fresh'])]);
    $session = LiveSession::factory()->zoom()->create(['instructor_id' => $this->instructor->id]);

    $this->actingAs($this->instructor)->postJson(api("instructor/live-sessions/{$session->uuid}/start"))
        ->assertOk()->assertJsonPath('data.url', 'https://zoom.us/s/8811223344?zak=fresh');
    $this->actingAs(User::factory()->student()->create())->postJson(api("instructor/live-sessions/{$session->uuid}/start"))->assertForbidden();
});

<?php

use App\Events\LiveSessionReminderDue;
use App\Jobs\SyncLiveAttendance;
use App\Live\LiveAttendanceService;
use App\Live\LiveProviders;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\Live\LiveSessionReminderNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

it('answers Zoom URL validation and rejects bad or stale signatures', function () {
    zoomWebhook(['event' => 'endpoint.url_validation', 'payload' => ['plainToken' => 'abc123']])
        ->assertOk()->assertExactJson(['plainToken' => 'abc123', 'encryptedToken' => hash_hmac('sha256', 'abc123', 'zoom-webhook-secret')]);

    zoomWebhook(['event' => 'meeting.started', 'payload' => ['object' => ['id' => '1']]], 'wrong-secret')->assertUnauthorized();

    $raw = json_encode(['event' => 'meeting.started']);
    $old = (string) (time() - 600);
    $this->call('POST', api('webhooks/zoom'), [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_ZM_REQUEST_TIMESTAMP' => $old,
        'HTTP_X_ZM_SIGNATURE' => 'v0='.hash_hmac('sha256', "v0:{$old}:{$raw}", 'zoom-webhook-secret'),
    ], $raw)->assertUnauthorized();
});

it('dedupes webhooks and records live joins by customer key', function () {
    $session = LiveSession::factory()->zoom('555')->create(['starts_at' => now()]);
    $reg = LiveRegistration::create(['live_session_id' => $session->id, 'user_id' => User::factory()->create()->id]);
    $body = ['event' => 'meeting.participant_joined', 'event_ts' => 10, 'payload' => ['object' => ['id' => '555', 'uuid' => 'x',
        'participant' => ['customer_key' => $reg->customerKey(), 'join_time' => '2026-11-12T10:00:05Z']]]];

    zoomWebhook($body)->assertJsonPath('status', 'processed');
    zoomWebhook($body)->assertJsonPath('status', 'duplicate');
    expect($reg->fresh()->joined_at->toIso8601String())->toBe('2026-11-12T10:00:05+00:00');

    zoomWebhook(['event' => 'meeting.started', 'event_ts' => 11, 'payload' => ['object' => ['id' => 'unknown']]])->assertJsonPath('status', 'ignored');
});

it('retries attendance until the Zoom report exists', function () {
    $session = LiveSession::factory()->zoom('777')->create(['status' => 'ended']);
    Http::fake([
        'zoom.us/oauth/token' => Http::response(['access_token' => 't']),
        'api.zoom.us/v2/report/meetings/777/participants*' => Http::response(['code' => 3001, 'message' => 'Meeting does not exist'], 404),
    ]);

    $job = (new SyncLiveAttendance($session->id))->withFakeQueueInteractions();
    $job->handle(app(LiveProviders::class), app(LiveAttendanceService::class));

    $job->assertReleased(300);
    expect($session->fresh()->attendance_synced_at)->toBeNull();
});

it('counts short attendance as not attended and awards points once', function () {
    $session = LiveSession::factory()->zoom('888')->create(['duration' => 60]);
    $user = User::factory()->create(['email' => 'Late@Example.com']);
    $reg = LiveRegistration::create(['live_session_id' => $session->id, 'user_id' => $user->id]);
    $svc = app(LiveAttendanceService::class);

    $svc->apply($session, [['customer_key' => null, 'email' => 'late@example.com', 'join_time' => now()->toIso8601String(), 'leave_time' => null, 'duration' => 120]]);
    expect($reg->fresh())->attended->toBeFalse()->attended_seconds->toBe(120); // matched by email, under 5 minutes

    $row = [['customer_key' => $reg->customerKey(), 'email' => null, 'join_time' => now()->toIso8601String(), 'leave_time' => null, 'duration' => 900]];
    $svc->apply($session, $row);
    $svc->apply($session, $row);
    expect($reg->fresh()->attended)->toBeTrue()
        ->and((int) DB::table('points_ledger')->where('user_id', $user->id)->sum('points'))->toBe(15);
});

it('sends 24 h and 15 min reminders once, skipping the 24 h one for late sign-ups', function () {
    Notification::fake();
    $session = LiveSession::factory()->create(['starts_at' => now()->addHours(30)]);
    $early = LiveRegistration::create(['live_session_id' => $session->id, 'user_id' => User::factory()->create()->id]);

    $this->travel(7)->hours(); // 23 h before
    $late = LiveRegistration::create(['live_session_id' => $session->id, 'user_id' => User::factory()->create()->id]);
    $this->artisan('live:maintain')->assertSuccessful();
    $this->artisan('live:maintain')->assertSuccessful();
    Notification::assertSentToTimes($early->user, LiveSessionReminderNotification::class, 1);
    Notification::assertNotSentTo($late->user, LiveSessionReminderNotification::class);

    $this->travelTo($session->starts_at->copy()->subMinutes(14));
    $this->artisan('live:maintain');
    Notification::assertSentToTimes($early->user, LiveSessionReminderNotification::class, 2);
    Notification::assertSentToTimes($late->user, LiveSessionReminderNotification::class, 1);
});

it('exposes reminders as an event for later notification channels', function () {
    Event::fake([LiveSessionReminderDue::class]);
    $session = LiveSession::factory()->create(['starts_at' => now()->addMinutes(10)]);
    LiveRegistration::create(['live_session_id' => $session->id, 'user_id' => User::factory()->create()->id]);

    $this->artisan('live:maintain');
    Event::assertDispatched(LiveSessionReminderDue::class, fn ($e) => $e->minutesBefore === 15);
});

it('moves external sessions live, ends stale sessions and queues attendance sync', function () {
    Queue::fake();
    $external = LiveSession::factory()->create(['starts_at' => now()->subMinutes(5)]);
    $zoom = LiveSession::factory()->zoom()->create(['starts_at' => now()->subHours(2), 'duration' => 60]);
    $future = LiveSession::factory()->zoom('2')->create(['starts_at' => now()->addHour()]);

    $this->artisan('live:maintain')->assertSuccessful();

    expect($external->fresh()->status->value)->toBe('live')
        ->and($zoom->fresh()->status->value)->toBe('ended')
        ->and($future->fresh()->status->value)->toBe('scheduled');
    Queue::assertPushed(SyncLiveAttendance::class, fn ($j) => $j->sessionId === $zoom->id);
});

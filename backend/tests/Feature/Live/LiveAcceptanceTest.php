<?php

use App\Enums\MembershipTier;
use App\Jobs\RecalculateCourseProgress;
use App\Learning\ProgressService;
use App\Live\LiveRecordingService;
use App\Models\Course;
use App\Models\CourseProgress;
use App\Models\Lesson;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use App\Models\Video;
use App\Notifications\Live\LiveRegistrationConfirmedNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/*
 * ACCEPTANCE: instructor schedules a session, Pro members register and join from the browser,
 * attendance and recording sync back automatically.
 */
it('runs the whole live-class lifecycle', function () {
    Notification::fake();
    $instructor = User::factory()->instructor()->create();
    $course = courseWith(['text'], 2);
    $course->update(['instructor_id' => $instructor->id]);
    $pro = User::factory()->student()->create(['email' => 'pro@example.com']);
    subscribe($pro, MembershipTier::Pro);
    $starter = User::factory()->student()->create();
    subscribe($starter, MembershipTier::Starter);

    $report = ['participants' => []];
    fakeZoom([
        'api.zoom.us/v2/report/meetings/*' => function () use (&$report) {
            return Http::response($report);
        },
        'video.bunnycdn.com/library/4242/videos/fetch' => Http::response(['success' => true, 'statusCode' => 200]),
        'video.bunnycdn.com/library/4242/videos?*' => function (Request $r) {
            $video = Video::latest('id')->first();

            return Http::response(['items' => [['guid' => 'rec-guid', 'title' => 'Replay: Meta Ads clinic ['.$video->uuid.']']]]);
        },
        'video.bunnycdn.com/library/4242/videos/rec-guid' => Http::response(['guid' => 'rec-guid', 'status' => 4, 'length' => 3480]),
    ]);

    // 1. Instructor schedules a Zoom class for their Pro course.
    $id = $this->actingAs($instructor)->postJson(api('instructor/live-sessions'), liveBody(['course_id' => $course->uuid]))
        ->assertCreated()->assertJsonPath('data.0.provider', 'zoom')->assertJsonPath('data.0.required_tier', 'pro')
        ->json('data.0.id');
    $session = LiveSession::where('uuid', $id)->firstOrFail();
    expect($session->meeting_id)->toBe('8800000001')
        ->and($session->starts_at->copy()->setTimezone('Asia/Kolkata')->format('H:i'))->toBe('19:30');
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/users/me/meetings')
        && $r['type'] === 2 && $r['timezone'] === 'Asia/Kolkata' && $r['settings']['auto_recording'] === 'cloud' && $r['duration'] === 60);

    // Secrets never leak through the listing.
    $listing = $this->actingAs($pro)->getJson(api('live-sessions'))->assertOk()->json();
    expect(json_encode($listing))->not->toContain('zoom.us/j/')->not->toContain('p4ss')->not->toContain('zak=');

    // 2. Pro registers (confirmation with .ics); Starter is blocked.
    $this->actingAs($pro)->postJson(api("live-sessions/{$id}/registration"))->assertCreated()->assertJsonPath('data.viewer.registered', true);
    Notification::assertSentTo($pro, LiveRegistrationConfirmedNotification::class);
    $this->actingAs($starter)->postJson(api("live-sessions/{$id}/registration"))->assertStatus(402)->assertJsonPath('required_tier_slug', 'pro');

    // 3. Join opens 10 minutes before the start, with a Meeting SDK signature.
    $this->actingAs($pro)->postJson(api("live-sessions/{$id}/join"))->assertForbidden()->assertJsonPath('code', 'join_not_open');
    $this->travelTo($session->starts_at->copy()->subMinutes(9));
    $join = $this->actingAs($pro)->postJson(api("live-sessions/{$id}/join"))->assertOk()->json('data');
    expect($join)->mode->toBe('zoom_sdk')->meeting_number->toBe('8800000001')->password->toBe('p4ss')->sdk_key->toBe('sdk-key');
    [$h, $p, $sig] = explode('.', $join['signature']);
    $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    expect($claims)->mn->toBe('8800000001')->role->toBe(0)->appKey->toBe('sdk-key')
        ->and($claims['exp'] - $claims['iat'])->toBe(7200)
        ->and(rtrim(strtr(base64_encode(hash_hmac('sha256', "{$h}.{$p}", 'sdk-secret', true)), '+/', '-_'), '='))->toBe($sig);
    $this->actingAs($starter)->postJson(api("live-sessions/{$id}/join"))->assertStatus(402);

    // 4. Zoom says the meeting started, then ended → attendance from the participant report (matched by customer key).
    $this->travelTo($session->starts_at->copy()->addMinutes(1));
    zoomWebhook(['event' => 'meeting.started', 'event_ts' => 1, 'payload' => ['object' => ['id' => '8800000001', 'uuid' => 'u1']]])->assertOk();
    expect($session->fresh()->status->value)->toBe('live');

    $reg = LiveRegistration::where('user_id', $pro->id)->firstOrFail();
    $report = ['participants' => [
        ['customer_key' => $reg->customerKey(), 'user_email' => '', 'join_time' => $session->starts_at->toIso8601String(), 'leave_time' => $session->starts_at->copy()->addMinutes(20)->toIso8601String(), 'duration' => 1200],
        ['customer_key' => $reg->customerKey(), 'user_email' => '', 'join_time' => $session->starts_at->copy()->addMinutes(25)->toIso8601String(), 'leave_time' => $session->starts_at->copy()->addMinutes(55)->toIso8601String(), 'duration' => 1800],
        ['customer_key' => '', 'user_email' => 'host@example.com', 'join_time' => $session->starts_at->toIso8601String(), 'leave_time' => null, 'duration' => 3600],
    ]];
    $this->travelTo($session->endsAt());
    zoomWebhook(['event' => 'meeting.ended', 'event_ts' => 2, 'payload' => ['object' => ['id' => '8800000001', 'uuid' => 'u1']]])->assertOk();

    $reg->refresh();
    expect($session->fresh()->status->value)->toBe('ended')
        ->and($reg->attended)->toBeTrue()
        ->and($reg->attended_seconds)->toBe(3000)
        ->and($reg->left_at->equalTo($session->starts_at->copy()->addMinutes(55)))->toBeTrue();
    $this->actingAs($instructor)->getJson(api("instructor/live-sessions/{$id}/registrations"))
        ->assertJsonPath('data.0.attended', true)->assertJsonPath('data.0.attended_minutes', 50);

    // 5. recording.completed → re-hosted on Bunny (with Zoom's download token) → replay lesson in the course.
    zoomWebhook([
        'event' => 'recording.completed', 'event_ts' => 3, 'download_token' => 'dl-token',
        'payload' => ['object' => ['id' => '8800000001', 'uuid' => 'u1', 'share_url' => 'https://zoom.us/rec/share/abc', 'recording_files' => [
            ['file_type' => 'M4A', 'download_url' => 'https://zoom.us/rec/download/audio', 'status' => 'completed'],
            ['file_type' => 'MP4', 'recording_type' => 'shared_screen_with_speaker_view', 'download_url' => 'https://zoom.us/rec/download/video', 'status' => 'completed',
                'recording_start' => '2026-11-14T14:00:00Z', 'recording_end' => '2026-11-14T14:58:00Z'],
        ]]],
    ])->assertOk();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/videos/fetch')
        && $r['url'] === 'https://zoom.us/rec/download/video' && $r['headers']['Authorization'] === 'Bearer dl-token');
    $session->refresh();
    $lesson = Lesson::findOrFail($session->replay_lesson_id);
    expect($session->recordingVideo->provider_video_id)->toBe('rec-guid')
        ->and($session->recordingVideo->status->value)->toBe('ready')
        ->and($session->recording_url)->toBe('https://zoom.us/rec/share/abc')
        ->and($lesson)->is_optional->toBeTrue()->video_id->toBe($session->recording_video_id)->duration_seconds->toBe(3480)
        ->and($lesson->section->title)->toBe('Live replays');

    // Replays: Pro can play, Starter sees the lock.
    $this->actingAs($pro)->getJson(api('live-replays'))->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.locked', false)
        ->assertJsonPath('data.0.replay_lesson_id', $lesson->uuid);
    $this->actingAs($pro)->getJson(api("live-replays/{$id}/playback"))->assertOk()->assertJsonPath('data.url', fn ($u) => str_contains($u, 'rec-guid'));
    $this->actingAs($starter)->getJson(api("live-replays/{$id}/playback"))->assertStatus(402);
    $this->actingAs($starter)->getJson(api('live-replays'))->assertJsonPath('data.0.locked', true);
});

it('does not let an optional replay lesson reopen a completed course', function () {
    $user = User::factory()->student()->create();
    subscribe($user, MembershipTier::Pro);
    $course = courseWith(['text']);
    app(ProgressService::class)->complete($user, $course->sections[0]->lessons[0]->load('course'));
    expect(CourseProgress::first()->percent)->toBe(100);

    $session = LiveSession::factory()->create(['course_id' => $course->id]);
    $video = Video::create(['provider' => 'bunny', 'provider_video_id' => 'v', 'title' => 'r', 'status' => 'ready', 'duration_seconds' => 60]);
    app(LiveRecordingService::class)->attachReplay($session, $video);
    dispatch_sync(new RecalculateCourseProgress($user->id, $course->id));

    expect(CourseProgress::first())->percent->toBe(100)->total_lessons->toBe(1)
        ->and(Course::find($course->id)->lessons()->count())->toBe(2);
});

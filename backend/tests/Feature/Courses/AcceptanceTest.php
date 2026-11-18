<?php

/*
 * Phase 3 acceptance: an instructor uploads a course, an admin publishes it,
 * a Pro member can watch, a Starter member sees the lock, signed URLs expire.
 */

use App\Enums\MembershipTier;
use App\Models\Category;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use App\Video\BunnyTokenSigner;
use App\Video\VideoService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('public');
    Storage::fake('local');
    $this->instructor = User::factory()->instructor()->create();
    $this->admin = User::factory()->admin()->create();
    $this->category = Category::factory()->create(['slug' => 'marketing']);
    Http::fake([
        'video.bunnycdn.com/library/4242/videos' => Http::response(['guid' => 'bunny-guid-1', 'title' => 'Intro']),
        'video.bunnycdn.com/library/4242/videos/bunny-guid-1' => Http::response([
            'guid' => 'bunny-guid-1', 'status' => 4, 'length' => 754, 'thumbnailFileName' => 'thumbnail.jpg',
        ]),
    ]);
});

it('runs the full instructor → admin → member flow', function () {
    // 1. Instructor drafts a course
    $courseId = $this->actingAs($this->instructor)->postJson(api('instructor/courses'), [
        'title' => 'Meta Ads from Zero', 'subtitle' => 'Run profitable campaigns',
        'description' => '<p>Learn ads.</p><script>alert(1)</script>',
        'category_id' => $this->category->uuid, 'level' => 'beginner', 'required_tier' => 2, 'tags' => ['ads', 'meta'],
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    expect(Course::firstOrFail()->description)->toBe('<p>Learn ads.</p>'); // sanitized

    $this->actingAs($this->instructor)->post(api("instructor/courses/{$courseId}/thumbnail"), [
        'thumbnail' => UploadedFile::fake()->image('cover.jpg', 1280, 720),
    ], ['Accept' => 'application/json'])->assertOk();

    $sectionId = $this->actingAs($this->instructor)->postJson(api("instructor/courses/{$courseId}/sections"), ['title' => 'Basics'])
        ->assertOk()->json('data.sections.0.id');

    // 2. Direct upload to Bunny: server creates the video and signs a TUS ticket
    $ticket = $this->actingAs($this->instructor)->postJson(api('instructor/videos'), ['title' => 'Intro'])->assertCreated()->json('data');
    $expire = $ticket['upload']['headers']['AuthorizationExpire'];
    expect($ticket['upload']['protocol'])->toBe('tus')
        ->and($ticket['upload']['endpoint'])->toBe('https://video.bunnycdn.com/tusupload')
        ->and($ticket['upload']['headers']['VideoId'])->toBe('bunny-guid-1')
        ->and($ticket['upload']['headers']['AuthorizationSignature'])->toBe(hash('sha256', '4242'.'bunny-api-key'.$expire.'bunny-guid-1'))
        ->and(json_encode($ticket))->not->toContain('bunny-api-key');

    $this->actingAs($this->instructor)->postJson(api("instructor/videos/{$ticket['video']['id']}/uploaded"))->assertJsonPath('data.status', 'processing');

    // Lesson can't be submitted while the video is still processing
    $this->actingAs($this->instructor)->postJson(api("instructor/sections/{$sectionId}/lessons"), [
        'title' => 'Welcome', 'type' => 'video', 'video_id' => $ticket['video']['id'],
    ])->assertOk();
    $this->actingAs($this->instructor)->postJson(api("instructor/courses/{$courseId}/submit"))
        ->assertUnprocessable()->assertJsonFragment(['Lesson “Welcome” needs a processed video.']);

    // 3. Bunny webhook: encoding finished → duration + thumbnail stored, propagated to the lesson
    postBunnyWebhook(['VideoLibraryId' => 4242, 'VideoGuid' => 'bunny-guid-1', 'Status' => 3, 'EventId' => 'evt-1'])
        ->assertOk()->assertJsonPath('status', 'queued');
    $video = Video::firstOrFail();
    expect($video->status->value)->toBe('ready')->and($video->duration_seconds)->toBe(754)
        ->and(Lesson::firstOrFail()->duration_seconds)->toBe(754)
        ->and(Course::firstOrFail()->duration_minutes)->toBe(13);

    // 4. Submit → admin approves
    $this->actingAs($this->instructor)->postJson(api("instructor/courses/{$courseId}/submit"))->assertOk()->assertJsonPath('data.status', 'review');
    $this->actingAs($this->instructor)->patchJson(api("instructor/courses/{$courseId}"), ['title' => 'Sneaky edit'])->assertForbidden();
    $this->actingAs($this->admin)->getJson(api('admin/courses?status=review'))->assertJsonPath('data.0.id', $courseId);
    $this->actingAs($this->admin)->postJson(api("admin/courses/{$courseId}/approve"))->assertOk()->assertJsonPath('data.status', 'published');

    $slug = Course::firstOrFail()->slug;
    $lessonId = Lesson::firstOrFail()->uuid;
    $this->getJson(api('courses'))->assertJsonPath('data.0.slug', $slug);

    // 5. Pro member watches: signed, expiring HLS URL
    $pro = User::factory()->student()->create();
    subscribe($pro, MembershipTier::Pro);
    $playback = $this->actingAs($pro)->getJson(api("lessons/{$lessonId}/playback"))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');

    expect($playback['url'])->toStartWith('https://vz-test.b-cdn.net/bcdn_token=HS256-')
        ->toContain('&token_path=%2Fbunny-guid-1%2F')
        ->toEndWith('/bunny-guid-1/playlist.m3u8')
        ->and($playback['poster'])->toEndWith('/bunny-guid-1/thumbnail.jpg')
        ->and($playback['ttl_seconds'])->toBe(754 + 900);

    // 6. Starter member sees the lock (course page + playback refused)
    $starter = User::factory()->student()->create();
    subscribe($starter, MembershipTier::Starter);
    $this->actingAs($starter)->getJson(api("courses/{$slug}"))
        ->assertJsonPath('data.access.has_access', false)
        ->assertJsonPath('data.access.required_tier', 2)
        ->assertJsonPath('data.curriculum.0.lessons.0.unlocked', false)
        ->assertJsonPath('data.curriculum.0.lessons.0.lock_reason', 'membership_required');
    $this->actingAs($starter)->getJson(api("lessons/{$lessonId}/playback"))
        ->assertStatus(402)->assertJsonPath('code', 'membership_required')->assertJsonPath('required_tier_slug', 'pro');
});

it('issues URLs whose expiry is part of the signature and in the near future', function () {
    $course = publishedCourse(2, 1);
    $lesson = $course->sections[0]->lessons[0];
    $lesson->update(['is_preview' => false]);
    $pro = User::factory()->create();
    subscribe($pro, MembershipTier::Pro);

    $this->freezeTime();
    $url = $this->actingAs($pro)->getJson(api("lessons/{$lesson->uuid}/playback"))->json('data.url');
    parse_str(str_replace('&', '&', explode('/', parse_url($url, PHP_URL_PATH))[1]), $parts);
    $expires = (int) $parts['expires'];
    $ttl = app(VideoService::class)->ttlFor($lesson->video);

    expect($expires)->toBe(now()->getTimestamp() + $ttl)
        ->and($ttl)->toBeLessThanOrEqual(4 * 3600);

    // Changing the expiry invalidates the token (the CDN recomputes it), so a URL can't be extended.
    $guid = $lesson->video->provider_video_id;
    $signer = new BunnyTokenSigner('vz-test.b-cdn.net', 'bunny-token-key');
    expect($parts['bcdn_token'])->toBe($signer->token("/{$guid}/", $expires))
        ->and($signer->token("/{$guid}/", $expires + 3600))->not->toBe($parts['bcdn_token']);

    // A fresh request later gets a new URL with a later expiry.
    $this->travel(10)->minutes();
    $later = $this->actingAs($pro)->getJson(api("lessons/{$lesson->uuid}/playback"))->json('data.url');
    expect($later)->not->toBe($url);
});

it('matches Bunny\'s reference signing algorithm (url_signing.php, directory mode)', function () {
    // Re-implementation of BunnyWay/BunnyCDN.TokenAuthentication php/url_signing.php::sign_bcdn_url
    // with is_directory=true, path_allowed set, no IP/countries — kept generic on purpose.
    $reference = function (string $url, string $key, int $expires, string $pathAllowed): string {
        $parts = parse_url($url);
        $parameters = ['token_path' => $pathAllowed];
        ksort($parameters);
        $signingData = collect($parameters)->map(fn ($v, $k) => "{$k}={$v}")->implode('&');
        $urlData = collect($parameters)->map(fn ($v, $k) => "{$k}=".rawurlencode($v))->implode('&');
        $token = 'HS256-'.rtrim(strtr(base64_encode(hash_hmac('sha256', $pathAllowed.$expires.$signingData, $key, true)), '+/', '-_'), '=');

        return "{$parts['scheme']}://{$parts['host']}/bcdn_token={$token}&{$urlData}&expires={$expires}{$parts['path']}";
    };

    $signer = new BunnyTokenSigner('vz-test.b-cdn.net', 'secret-key');
    expect($signer->signDirectory('/abc/', '/abc/playlist.m3u8', 1800000000))
        ->toBe($reference('https://vz-test.b-cdn.net/abc/playlist.m3u8', 'secret-key', 1800000000, '/abc/'));
});

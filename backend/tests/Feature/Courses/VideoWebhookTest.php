<?php

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->video = Video::factory()->processing()->create(['provider_video_id' => 'g-1']);
    Http::fake(['video.bunnycdn.com/library/4242/videos/g-1' => Http::response(['guid' => 'g-1', 'status' => 5, 'length' => 0])]);
});

it('rejects unsigned, wrongly signed or foreign-library webhooks', function () {
    postBunnyWebhook(['VideoLibraryId' => 4242, 'VideoGuid' => 'g-1', 'Status' => 3, 'EventId' => 'e'], 'wrong-key')->assertUnauthorized();
    postBunnyWebhook(['VideoLibraryId' => 9999, 'VideoGuid' => 'g-1', 'Status' => 3, 'EventId' => 'e'])->assertUnauthorized();

    expect($this->video->fresh()->status)->toBe(VideoStatus::Processing);
});

it('marks failed encodes and dedupes by EventId', function () {
    postBunnyWebhook(['VideoLibraryId' => 4242, 'VideoGuid' => 'g-1', 'Status' => 5, 'EventId' => 'evt-9'])->assertJsonPath('status', 'queued');
    postBunnyWebhook(['VideoLibraryId' => 4242, 'VideoGuid' => 'g-1', 'Status' => 5, 'EventId' => 'evt-9'])->assertJsonPath('status', 'duplicate');

    expect($this->video->fresh())->status->toBe(VideoStatus::Failed)->error->not->toBeNull();
});

it('accepts uploads through the demo driver and marks them ready', function () {
    config(['video.driver' => 'demo']);
    $instructor = User::factory()->instructor()->create();

    $ticket = $this->actingAs($instructor)->postJson(api('instructor/videos'), ['title' => 'Demo'])->assertCreated()->json('data');
    expect($ticket['upload']['protocol'])->toBe('http');

    $this->actingAs($instructor)->post(api("instructor/videos/{$ticket['video']['id']}/demo-upload"), [
        'file' => UploadedFile::fake()->create('clip.mp4', 500, 'video/mp4'),
    ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'ready');

    // Someone else can't touch it
    $this->actingAs(User::factory()->instructor()->create())->getJson(api("instructor/videos/{$ticket['video']['id']}"))->assertNotFound();
});

<?php

namespace App\Video;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Local-development stand-in: accepts uploads to our own endpoint (discarding the bytes)
 * and plays a public test HLS stream. Its URLs are NOT access-protected — never use in production.
 */
class DemoVideoProvider implements VideoProvider
{
    public function name(): string
    {
        return 'demo';
    }

    public function createUpload(Video $video): UploadTicket
    {
        $video->forceFill(['provider_video_id' => 'demo-'.Str::lower(Str::random(12))])->save();

        return new UploadTicket('http', route('api.v1.instructor.videos.demo-upload', $video), [], now()->getTimestamp() + 3600);
    }

    public function fetchDetails(Video $video): array
    {
        return ['status' => VideoStatus::Ready, 'duration_seconds' => (int) config('video.demo.duration_seconds'), 'thumbnail_url' => null];
    }

    /** @return array{url: string, poster: string|null, expires_at: int} */
    public function playback(Video $video, int $ttlSeconds): array
    {
        $expires = now()->getTimestamp() + $ttlSeconds;

        return ['url' => config('video.demo.hls_url').'?expires='.$expires, 'poster' => null, 'expires_at' => $expires];
    }

    public function delete(Video $video): void {}

    /** @param array<string, string> $headers */
    public function importFromUrl(Video $video, string $url, array $headers = []): void
    {
        $video->forceFill(['provider_video_id' => 'demo-'.Str::lower(Str::random(12))])->save();
    }

    public function findImported(Video $video): ?string
    {
        return $video->provider_video_id;
    }

    /** @return array{0: string, 1: VideoStatus, 2: string}|null */
    public function parseWebhook(Request $request): ?array
    {
        return null;
    }
}

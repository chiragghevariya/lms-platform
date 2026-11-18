<?php

namespace App\Video;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Bunny Stream: TUS direct upload (presigned), webhook on encode, token-signed HLS playback.
 * Direct download is prevented by (a) never exposing MP4 URLs, (b) CDN token auth on the
 * pull zone, and (c) "MP4 fallback" being disabled in the library settings (see docs).
 */
class BunnyStreamProvider implements VideoProvider
{
    public function name(): string
    {
        return 'bunny';
    }

    public function createUpload(Video $video): UploadTicket
    {
        $remote = $this->api()->post("/library/{$this->library()}/videos", ['title' => $video->title])->throw()->json();
        $guid = (string) ($remote['guid'] ?? throw new RuntimeException('Bunny did not return a video id.'));
        $video->forceFill(['provider_video_id' => $guid])->save();

        $expires = now()->getTimestamp() + (int) config('video.upload_ttl_seconds');
        // Presigned TUS: sha256(library_id + api_key + expiration_time + video_id), hex.
        $signature = hash('sha256', $this->library().config('video.bunny.api_key').$expires.$guid);

        return new UploadTicket('tus', (string) config('video.bunny.tus_endpoint'), [
            'AuthorizationSignature' => $signature,
            'AuthorizationExpire' => (string) $expires,
            'VideoId' => $guid,
            'LibraryId' => $this->library(),
        ], $expires);
    }

    public function fetchDetails(Video $video): array
    {
        $remote = $this->api()->get("/library/{$this->library()}/videos/{$video->provider_video_id}")->throw()->json();

        // API status: 0 created, 1 uploaded, 2 processing, 3 transcoding, 4 finished, 5 error, 6 upload failed.
        $status = match ((int) ($remote['status'] ?? 0)) {
            4 => VideoStatus::Ready,
            5, 6 => VideoStatus::Failed,
            default => VideoStatus::Processing,
        };

        return [
            'status' => $status,
            'duration_seconds' => isset($remote['length']) ? (int) $remote['length'] : null,
            'thumbnail_url' => ! empty($remote['thumbnailFileName']) ? "/{$video->provider_video_id}/{$remote['thumbnailFileName']}" : null,
        ];
    }

    /** @return array{url: string, poster: string|null, expires_at: int} */
    public function playback(Video $video, int $ttlSeconds): array
    {
        $guid = (string) $video->provider_video_id;
        $expires = now()->getTimestamp() + $ttlSeconds;
        $signer = new BunnyTokenSigner((string) config('video.bunny.cdn_hostname'), (string) config('video.bunny.token_security_key'));

        return [
            'url' => $signer->signDirectory("/{$guid}/", "/{$guid}/playlist.m3u8", $expires),
            // thumbnail_url stores the path; signing it with the same directory token keeps posters private too.
            'poster' => $video->thumbnail_url ? $signer->signDirectory("/{$guid}/", $video->thumbnail_url, $expires) : null,
            'expires_at' => $expires,
        ];
    }

    /**
     * Bunny "Fetch Video": Bunny downloads the file itself. The endpoint returns no video id, so the title
     * carries our video uuid and findImported() searches for it.
     *
     * @param  array<string, string>  $headers
     */
    public function importFromUrl(Video $video, string $url, array $headers = []): void
    {
        $this->api()->post("/library/{$this->library()}/videos/fetch", array_filter([
            'url' => $url,
            'headers' => $headers ?: null,
            'title' => $this->importTitle($video),
        ]))->throw();
    }

    public function findImported(Video $video): ?string
    {
        $items = (array) $this->api()->get("/library/{$this->library()}/videos", [
            'search' => $video->uuid, 'itemsPerPage' => 5, 'page' => 1,
        ])->throw()->json('items', []);
        foreach ($items as $item) {
            if (str_contains((string) ($item['title'] ?? ''), $video->uuid)) {
                return (string) $item['guid'];
            }
        }

        return null;
    }

    private function importTitle(Video $video): string
    {
        return mb_substr($video->title, 0, 150).' ['.$video->uuid.']';
    }

    public function delete(Video $video): void
    {
        if ($video->provider_video_id) {
            $this->api()->delete("/library/{$this->library()}/videos/{$video->provider_video_id}");
        }
    }

    /**
     * Stream webhooks: X-BunnyStream-Signature = lowercase hex HMAC-SHA256(raw body, library Read-Only API key),
     * version v1. Status: 3 finished, 4 resolution finished (playable), 5 failed, 8 presigned upload failed.
     *
     * @return array{0: string, 1: VideoStatus, 2: string}|null
     */
    public function parseWebhook(Request $request): ?array
    {
        $secret = (string) config('video.bunny.read_only_api_key');
        $signature = (string) $request->header('X-BunnyStream-Signature');

        if ($secret === ''
            || $request->header('X-BunnyStream-Signature-Version') !== 'v1'
            || strtolower((string) $request->header('X-BunnyStream-Signature-Algorithm')) !== 'hmac-sha256'
            || ! hash_equals(hash_hmac('sha256', $request->getContent(), $secret), strtolower($signature))) {
            return null;
        }

        $guid = $request->json('VideoGuid');
        if (! is_string($guid) || (string) $request->json('VideoLibraryId') !== $this->library()) {
            return null;
        }

        $status = match ((int) $request->json('Status')) {
            3, 4 => VideoStatus::Ready,
            5, 8 => VideoStatus::Failed,
            default => VideoStatus::Processing,
        };

        return [$guid, $status, (string) ($request->json('EventId') ?? hash('sha256', $request->getContent()))];
    }

    private function api(): PendingRequest
    {
        return Http::baseUrl((string) config('video.bunny.api_base'))
            ->withHeaders(['AccessKey' => (string) config('video.bunny.api_key')])
            ->acceptJson()->asJson()->timeout(20);
    }

    private function library(): string
    {
        return (string) config('video.bunny.library_id');
    }
}

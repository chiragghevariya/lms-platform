<?php

namespace App\Video;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Http\Request;

/** Adapter so Bunny Stream can be swapped for Mux / Vimeo without touching courses. */
interface VideoProvider
{
    public function name(): string;

    /** Creates the remote asset (sets provider_video_id) and returns a direct-upload ticket. */
    public function createUpload(Video $video): UploadTicket;

    /** @return array{status: VideoStatus, duration_seconds: int|null, thumbnail_url: string|null} */
    public function fetchDetails(Video $video): array;

    /**
     * Short-lived signed HLS URL (and poster) valid for $ttlSeconds.
     *
     * @return array{url: string, poster: string|null, expires_at: int}
     */
    public function playback(Video $video, int $ttlSeconds): array;

    public function delete(Video $video): void;

    /**
     * Asks the provider to pull a video from a URL (e.g. a Zoom cloud recording). May not know the
     * remote id yet; `findImported()` resolves it later. `$headers` authenticate the download.
     *
     * @param  array<string, string>  $headers
     */
    public function importFromUrl(Video $video, string $url, array $headers = []): void;

    /** Remote id of a video started with importFromUrl(), or null while it isn't visible yet. */
    public function findImported(Video $video): ?string;

    /**
     * Verifies the provider's webhook signature; returns [provider video id, status, event id] or null when invalid.
     *
     * @return array{0: string, 1: VideoStatus, 2: string}|null
     */
    public function parseWebhook(Request $request): ?array;
}

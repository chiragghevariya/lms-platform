<?php

namespace App\Video;

use App\Enums\VideoStatus;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Video;
use Illuminate\Support\Facades\DB;

class VideoService
{
    public function __construct(private readonly VideoProvider $provider) {}

    public function provider(): VideoProvider
    {
        return $this->provider;
    }

    /** @return array{video: Video, upload: UploadTicket} */
    public function startUpload(User $user, string $title): array
    {
        $video = Video::create([
            'uploaded_by' => $user->id,
            'provider' => $this->provider->name(),
            'title' => $title,
            'status' => VideoStatus::Uploading,
        ]);

        return ['video' => $video, 'upload' => $this->provider->createUpload($video)];
    }

    /** Pulls duration/thumbnail from the provider and propagates duration to lessons + course totals. */
    public function sync(Video $video, ?VideoStatus $hint = null): Video
    {
        $details = $this->provider->fetchDetails($video);
        $status = $hint === VideoStatus::Failed ? VideoStatus::Failed : $details['status'];

        DB::transaction(function () use ($video, $details, $status) {
            $video->forceFill([
                'status' => $status,
                'duration_seconds' => $details['duration_seconds'] ?? $video->duration_seconds,
                'thumbnail_url' => $details['thumbnail_url'] ?? $video->thumbnail_url,
                'error' => $status === VideoStatus::Failed ? 'Encoding failed at the video provider.' : null,
            ])->save();

            if ($video->duration_seconds) {
                Lesson::where('video_id', $video->id)->with('course')->get()->each(function (Lesson $lesson) use ($video) {
                    $lesson->forceFill(['duration_seconds' => (int) $video->duration_seconds])->save();
                    $lesson->course->refreshStats();
                });
            }
        });

        return $video;
    }

    /** TTL long enough to finish this video, clamped to [min, max]. */
    public function ttlFor(Video $video): int
    {
        $cfg = config('video.playback_ttl');

        return max((int) $cfg['min_seconds'], min((int) $cfg['max_seconds'], (int) $video->duration_seconds + (int) $cfg['buffer_seconds']));
    }
}

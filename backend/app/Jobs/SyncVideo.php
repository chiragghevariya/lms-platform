<?php

namespace App\Jobs;

use App\Enums\VideoStatus;
use App\Models\Video;
use App\Video\VideoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SyncVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 120, 600];

    public function __construct(public int $videoId, public string $status)
    {
        $this->onQueue('media');
    }

    public function handle(VideoService $videos): void
    {
        $video = Video::find($this->videoId);
        if ($video) {
            $videos->sync($video, VideoStatus::from($this->status));
        }
    }
}

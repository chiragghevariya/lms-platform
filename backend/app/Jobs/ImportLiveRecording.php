<?php

namespace App\Jobs;

use App\Live\LiveRecordingService;
use App\Models\LiveSession;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-hosts a provider recording on our video provider and attaches the replay.
 * Encrypted on the queue: the payload carries Zoom's short-lived download token.
 */
class ImportLiveRecording implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    /** @param array<string, string> $headers */
    public function __construct(public int $sessionId, public string $url, public array $headers = []) {}

    public function handle(LiveRecordingService $recordings): void
    {
        $session = LiveSession::with('recordingVideo')->find($this->sessionId);
        if (! $session) {
            return;
        }
        if (! $recordings->import($session, $this->url, $this->headers)) {
            $this->release(60); // provider hasn't listed the fetched video yet
        }
    }
}

<?php

namespace App\Jobs;

use App\Live\LiveAttendanceService;
use App\Live\LiveProviders;
use App\Models\LiveSession;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Pulls the provider's participant report after a meeting ends. Reports lag, so it re-tries for ~2 hours. */
class SyncLiveAttendance implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 25;

    public int $uniqueFor = 600;

    public function __construct(public int $sessionId) {}

    public function uniqueId(): string
    {
        return (string) $this->sessionId;
    }

    public function handle(LiveProviders $providers, LiveAttendanceService $attendance): void
    {
        $session = LiveSession::find($this->sessionId);
        if (! $session) {
            return;
        }
        $rows = $providers->for($session->provider)->participants($session);
        if ($rows === null) {
            $this->release(300); // report not ready yet

            return;
        }
        $attendance->apply($session, $rows);
    }
}

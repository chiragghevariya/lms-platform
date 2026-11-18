<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LiveProviderType;
use App\Enums\LiveSessionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ImportLiveRecording;
use App\Jobs\SyncLiveAttendance;
use App\Live\LiveProviders;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Zoom event subscription: URL validation, meeting started/ended, live join/leave, recording.completed. */
class ZoomWebhookController extends Controller
{
    public function __invoke(Request $request, LiveProviders $providers): Response
    {
        $hook = $providers->for(LiveProviderType::Zoom)->parseWebhook($request);
        if ($hook === null) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        if ($hook->reply) {
            return $hook->reply;
        }
        if (! Cache::add('zoom-webhook:'.sha1($hook->eventId), true, now()->addDays(2))) {
            return response()->json(['status' => 'duplicate']);
        }

        $session = $hook->meetingId ? LiveSession::where('provider', LiveProviderType::Zoom)->where('meeting_id', $hook->meetingId)->first() : null;
        if (! $session) {
            return response()->json(['status' => 'ignored']);
        }

        match ($hook->event) {
            'started' => $session->status === LiveSessionStatus::Scheduled
                && $session->forceFill(['status' => LiveSessionStatus::Live, 'started_at' => now()])->save(),
            'ended' => $this->ended($session),
            'recording_completed' => $this->recording($session, $hook->data),
            'participant_joined', 'participant_left' => $this->participant($session, $hook->event, $hook->data),
            default => null,
        };

        return response()->json(['status' => 'processed']);
    }

    private function ended(LiveSession $session): void
    {
        if ($session->status !== LiveSessionStatus::Cancelled) {
            $session->forceFill(['status' => LiveSessionStatus::Ended, 'ended_at' => now()])->save();
        }
        // Zoom's participant report lags the end of the meeting by a few minutes.
        SyncLiveAttendance::dispatch($session->id)->delay(now()->addMinutes(5));
    }

    /** @param array<string, mixed> $data */
    private function recording(LiveSession $session, array $data): void
    {
        if (! empty($data['share_url'])) {
            $session->forceFill(['recording_url' => (string) $data['share_url']])->save();
        }
        if (! empty($data['download_url'])) {
            $headers = ! empty($data['download_token']) ? ['Authorization' => 'Bearer '.$data['download_token']] : [];
            ImportLiveRecording::dispatch($session->id, (string) $data['download_url'], $headers);
        }
    }

    /**
     * Live join/leave events give early attendance; the post-meeting report (SyncLiveAttendance) is authoritative.
     *
     * @param  array<string, mixed>  $data
     */
    private function participant(LiveSession $session, string $event, array $data): void
    {
        $p = (array) (((array) ($data['object'] ?? []))['participant'] ?? []);
        $key = (string) ($p['customer_key'] ?? '');
        if ($key === '') {
            return;
        }
        $reg = LiveRegistration::where('live_session_id', $session->id)->get()->first(fn (LiveRegistration $r) => $r->customerKey() === $key);
        if (! $reg) {
            return;
        }
        if ($event === 'participant_joined') {
            $reg->joined_at ??= isset($p['join_time']) ? Carbon::parse((string) $p['join_time']) : now();
        } else {
            $reg->left_at = isset($p['leave_time']) ? Carbon::parse((string) $p['leave_time']) : now();
        }
        $reg->save();
    }
}

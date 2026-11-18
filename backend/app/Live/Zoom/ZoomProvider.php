<?php

namespace App\Live\Zoom;

use App\Live\LiveProvider;
use App\Live\LiveProviderException;
use App\Live\LiveWebhook;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Http\Request;

/**
 * Zoom via Server-to-Server OAuth (meetings, reports) + Meeting SDK (embedded join).
 * Meetings are created in our own Zoom account, so the SDK joins without an OBF token
 * (Zoom requires OBF only when joining meetings hosted by *other* accounts).
 */
class ZoomProvider implements LiveProvider
{
    public function __construct(private readonly ZoomClient $zoom) {}

    public function create(LiveSession $session): void
    {
        $res = $this->zoom->api()->post('/users/'.rawurlencode((string) config('live.zoom.host_user')).'/meetings', $this->meetingBody($session));
        $this->ensure($res, 'create the Zoom meeting');

        $session->forceFill([
            'meeting_id' => (string) $res->json('id'),
            'join_url' => (string) $res->json('join_url'),
            'host_url' => (string) $res->json('start_url'),
            'meeting_password' => $res->json('password') ? (string) $res->json('password') : null,
        ]);
    }

    public function update(LiveSession $session): void
    {
        $res = $this->zoom->api()->patch("/meetings/{$session->meeting_id}", $this->meetingBody($session));
        $this->ensure($res, 'update the Zoom meeting');
    }

    public function delete(LiveSession $session): void
    {
        if (! $session->meeting_id) {
            return;
        }
        $res = $this->zoom->api()->delete("/meetings/{$session->meeting_id}", ['schedule_for_reminder' => 'false']);
        if ($res->status() !== 404) { // already gone is fine
            $this->ensure($res, 'delete the Zoom meeting');
        }
    }

    public function hostUrl(LiveSession $session): string
    {
        $res = $this->zoom->api()->get("/meetings/{$session->meeting_id}");
        $this->ensure($res, 'fetch the Zoom start link');

        return (string) $res->json('start_url');
    }

    public function joinPayload(LiveSession $session, User $user, LiveRegistration $registration): array
    {
        $key = (string) config('live.zoom.sdk_key');
        $secret = (string) config('live.zoom.sdk_secret');
        if ($key === '' || $secret === '') {
            // Without Meeting SDK credentials learners can still join through the Zoom app/web client.
            return ['mode' => 'external', 'url' => (string) $session->join_url];
        }

        return [
            'mode' => 'zoom_sdk',
            'sdk_key' => $key,
            'signature' => ZoomSdkSignature::make($key, $secret, (string) $session->meeting_id),
            'meeting_number' => (string) $session->meeting_id,
            'password' => (string) $session->meeting_password,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'customer_key' => $registration->customerKey(), // comes back as `customer_key` in participant reports
            'fallback_url' => (string) $session->join_url,
        ];
    }

    public function participants(LiveSession $session): ?array
    {
        $rows = [];
        $token = '';
        do {
            $res = $this->zoom->api()->get("/report/meetings/{$session->meeting_id}/participants", array_filter([
                'page_size' => 300, 'next_page_token' => $token,
            ]));
            // Reports appear some minutes after the meeting ends; 404 / 3001 means "not yet".
            if ($res->status() === 404 || $res->json('code') === 3001) {
                return null;
            }
            $this->ensure($res, 'fetch the Zoom participant report');

            foreach ((array) $res->json('participants', []) as $p) {
                $rows[] = [
                    'customer_key' => ($p['customer_key'] ?? '') !== '' ? (string) $p['customer_key'] : null,
                    'email' => ($p['user_email'] ?? '') !== '' ? strtolower((string) $p['user_email']) : null,
                    'join_time' => (string) $p['join_time'],
                    'leave_time' => isset($p['leave_time']) ? (string) $p['leave_time'] : null,
                    'duration' => (int) ($p['duration'] ?? 0),
                ];
            }
            $token = (string) $res->json('next_page_token', '');
        } while ($token !== '');

        return $rows;
    }

    public function parseWebhook(Request $request): ?LiveWebhook
    {
        $secret = (string) config('live.zoom.webhook_secret');
        $timestamp = (string) $request->header('x-zm-request-timestamp');
        $expected = 'v0='.hash_hmac('sha256', "v0:{$timestamp}:".$request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, (string) $request->header('x-zm-signature'))
            || abs(time() - (int) $timestamp) > 300) {
            return null;
        }

        $event = (string) $request->input('event');
        $object = (array) $request->input('payload.object', []);
        $eventId = $event.':'.($object['uuid'] ?? $object['id'] ?? '').':'.$request->input('event_ts');

        if ($event === 'endpoint.url_validation') {
            $plain = (string) $request->input('payload.plainToken');

            return new LiveWebhook('ignored', null, $eventId, reply: response()->json([
                'plainToken' => $plain,
                'encryptedToken' => hash_hmac('sha256', $plain, $secret),
            ]));
        }

        $mapped = match ($event) {
            'meeting.started' => 'started',
            'meeting.ended' => 'ended',
            'recording.completed' => 'recording_completed',
            'meeting.participant_joined' => 'participant_joined',
            'meeting.participant_left' => 'participant_left',
            default => 'ignored',
        };

        $data = ['object' => $object];
        if ($mapped === 'recording_completed') {
            $files = collect((array) ($object['recording_files'] ?? []))
                ->filter(fn ($f) => ($f['file_type'] ?? '') === 'MP4' && ($f['status'] ?? 'completed') === 'completed')
                // Prefer the speaker/gallery composite over single-stream files.
                ->sortBy(fn ($f) => match ($f['recording_type'] ?? '') {
                    'shared_screen_with_speaker_view' => 0, 'active_speaker' => 1, 'gallery_view' => 2, default => 3,
                });
            $mp4 = $files->first();
            $data = [
                'download_url' => $mp4['download_url'] ?? null,
                'download_token' => $request->input('download_token'),
                'share_url' => $object['share_url'] ?? null,
                'duration_seconds' => isset($mp4['recording_start'], $mp4['recording_end'])
                    ? max(0, strtotime((string) $mp4['recording_end']) - strtotime((string) $mp4['recording_start'])) : null,
            ];
        }

        return new LiveWebhook($mapped, isset($object['id']) ? (string) $object['id'] : null, $eventId, $data);
    }

    /** @return array<string, mixed> */
    private function meetingBody(LiveSession $session): array
    {
        return [
            'topic' => mb_substr($session->title, 0, 200),
            'type' => 2, // scheduled
            'start_time' => $session->starts_at->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration' => $session->duration,
            'timezone' => $session->timezone,
            'agenda' => mb_substr(strip_tags((string) $session->description), 0, 2000),
            'settings' => [
                'join_before_host' => false,
                'waiting_room' => false,
                'mute_upon_entry' => true,
                'approval_type' => 2, // no Zoom registration — our platform is the registration gate
                'auto_recording' => config('live.zoom.auto_recording'),
                'meeting_authentication' => false,
            ],
        ];
    }

    private function ensure(HttpResponse $res, string $what): void
    {
        if (! $res->successful()) {
            throw new LiveProviderException("Could not {$what}: ".($res->json('message') ?? 'HTTP '.$res->status()));
        }
    }
}

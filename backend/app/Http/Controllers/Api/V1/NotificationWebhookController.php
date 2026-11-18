<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notify\NotificationLogger;
use App\Notify\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/** Delivery tracking from providers: WhatsApp Cloud API, Mailgun, Twilio. All signature-verified. */
class NotificationWebhookController extends Controller
{
    public function __construct(private readonly NotificationLogger $logger) {}

    /** Meta's subscription handshake. */
    public function whatsappVerify(Request $request): Response
    {
        $ok = $request->query('hub_mode') === 'subscribe' && filled(config('notify.whatsapp.verify_token'))
            && hash_equals((string) config('notify.whatsapp.verify_token'), (string) $request->query('hub_verify_token'));

        return $ok ? response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']) : response('Forbidden', 403);
    }

    public function whatsapp(Request $request): JsonResponse
    {
        $secret = (string) config('notify.whatsapp.app_secret');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
        if ($secret === '' || ! hash_equals($expected, (string) $request->header('X-Hub-Signature-256'))) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                foreach ((array) ($value['statuses'] ?? []) as $s) {
                    $status = match ($s['status'] ?? '') {
                        'sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'failed', default => null,
                    };
                    if ($status) {
                        $this->logger->track('whatsapp', (string) $s['id'], $status, isset($s['errors'][0]) ? ($s['errors'][0]['title'] ?? '').' ('.($s['errors'][0]['code'] ?? '').')' : null);
                    }
                }
                // Inbound "STOP" → opt out (consent can be withdrawn on the channel itself).
                foreach ((array) ($value['messages'] ?? []) as $m) {
                    $text = strtoupper(trim((string) ($m['text']['body'] ?? $m['button']['text'] ?? '')));
                    if (in_array($text, ['STOP', 'UNSUBSCRIBE', 'STOP PROMOTIONS'], true)) {
                        $from = (string) ($m['from'] ?? '');
                        User::whereNotNull('phone')->get(['id', 'phone'])
                            ->filter(fn (User $u) => WhatsAppClient::digits((string) $u->phone) === $from)
                            ->each(fn (User $u) => $u->forceFill(['whatsapp_opt_out_at' => now()])->save());
                    }
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /** Mailgun "webhooks" (signed with the HTTP webhook signing key). */
    public function mailgun(Request $request): JsonResponse
    {
        $sig = (array) $request->input('signature', []);
        $key = (string) config('notify.mailgun.webhook_signing_key');
        $expected = hash_hmac('sha256', ($sig['timestamp'] ?? '').($sig['token'] ?? ''), $key);
        if ($key === '' || ! hash_equals($expected, (string) ($sig['signature'] ?? '')) || abs(time() - (int) ($sig['timestamp'] ?? 0)) > 900) {
            return response()->json(['message' => 'Invalid signature.'], 401);
        }
        $e = (array) $request->input('event-data', []);
        $kind = (string) Arr::get($e, 'event', '');
        $status = match ($kind) {
            'delivered' => 'delivered', 'opened' => 'read', 'failed', 'rejected', 'complained' => 'failed', default => null,
        };
        $notificationId = $e['user-variables']['notification_id'] ?? null;
        if ($status && ($kind !== 'failed' || Arr::get($e, 'severity', 'permanent') === 'permanent')) {
            $this->logger->track('mailgun', (string) ($e['message']['headers']['message-id'] ?? ''), $status,
                $status === 'failed' ? (string) (Arr::get($e, 'delivery-status.description') ?: Arr::get($e, 'reason', $kind)) : null,
                is_string($notificationId) ? $notificationId : null, 'mail');
        }

        return response()->json(['status' => 'ok']);
    }

    /** Twilio StatusCallback (X-Twilio-Signature = base64 HMAC-SHA1 of URL + sorted params). */
    public function twilio(Request $request): Response
    {
        $params = $request->post();
        ksort($params);
        $data = $request->fullUrl();
        foreach ($params as $k => $v) {
            $data .= $k.(is_scalar($v) ? (string) $v : '');
        }
        $expected = base64_encode(hash_hmac('sha1', $data, (string) config('notify.sms.twilio.token'), true));
        if (! config('notify.sms.twilio.token') || ! hash_equals($expected, (string) $request->header('X-Twilio-Signature'))) {
            return response('Invalid signature', 401);
        }
        $status = match ((string) $request->input('MessageStatus')) {
            'sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed', 'undelivered' => 'failed', default => null,
        };
        if ($status) {
            $this->logger->track('twilio', (string) $request->input('MessageSid'), $status, $request->input('ErrorCode') ? 'Twilio error '.$request->input('ErrorCode') : null);
        }

        return response()->noContent();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Gateway;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

/**
 * Verifies the signature, stores the event once (unique gateway+event_id) and queues it.
 * Duplicate deliveries return 200 without re-processing.
 */
class WebhookController extends Controller
{
    public function stripe(Request $request): JsonResponse
    {
        $payload = $request->getContent();

        try {
            $event = Webhook::constructEvent(
                $payload,
                (string) $request->header('Stripe-Signature'),
                (string) config('billing.stripe.webhook_secret'),
                (int) config('billing.stripe.webhook_tolerance'),
            );
        } catch (SignatureVerificationException|UnexpectedValueException) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        return $this->store(Gateway::Stripe, (string) $event->id, (string) $event->type, $payload);
    }

    public function razorpay(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $secret = (string) config('billing.razorpay.webhook_secret');
        $signature = (string) $request->header('X-Razorpay-Signature');

        if ($secret === '' || ! hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        $data = json_decode($payload, true);
        if (! is_array($data) || ! isset($data['event'])) {
            return response()->json(['message' => 'Malformed payload.'], 400);
        }

        // Razorpay sends a unique id per event in this header; fall back to a payload hash.
        $eventId = (string) ($request->header('X-Razorpay-Event-Id') ?: hash('sha256', $payload));

        return $this->store(Gateway::Razorpay, $eventId, (string) $data['event'], $payload);
    }

    private function store(Gateway $gateway, string $eventId, string $type, string $payload): JsonResponse
    {
        $inserted = WebhookEvent::query()->insertOrIgnore([
            'gateway' => $gateway->value,
            'event_id' => $eventId,
            'type' => mb_substr($type, 0, 80),
            'payload' => $payload,
            'status' => 'received',
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return response()->json(['status' => 'duplicate']);
        }

        $id = (int) WebhookEvent::where('gateway', $gateway)->where('event_id', $eventId)->value('id');
        ProcessWebhookEvent::dispatch($id);

        return response()->json(['status' => 'queued']);
    }
}

<?php

namespace App\Billing\Gateways;

use App\Billing\GatewayException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Thin wrapper over the Razorpay REST API (Laravel HTTP client → easy to fake in tests). */
class RazorpayClient
{
    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->post($path, $body));
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body = []): array
    {
        return $this->send(fn (PendingRequest $r) => $r->patch($path, $body));
    }

    /** @return array<string, mixed> */
    public function get(string $path): array
    {
        return $this->send(fn (PendingRequest $r) => $r->get($path));
    }

    public function keyId(): string
    {
        return (string) config('billing.razorpay.key_id');
    }

    /** Checkout handler signature: HMAC-SHA256("{payment_id}|{subscription_id or order_id}", key_secret). */
    public function verifyPaymentSignature(string $paymentId, string $subscriptionOrOrderId, string $signature, bool $isOrder): bool
    {
        $payload = $isOrder ? $subscriptionOrOrderId.'|'.$paymentId : $paymentId.'|'.$subscriptionOrOrderId;
        $expected = hash_hmac('sha256', $payload, (string) config('billing.razorpay.key_secret'));

        return hash_equals($expected, $signature);
    }

    /**
     * @param  callable(PendingRequest): Response  $fn
     * @return array<string, mixed>
     */
    private function send(callable $fn): array
    {
        $request = Http::baseUrl((string) config('billing.razorpay.base_url'))
            ->withBasicAuth($this->keyId(), (string) config('billing.razorpay.key_secret'))
            ->acceptJson()->asJson()->timeout(20)->retry(2, 300, throw: false);

        try {
            /** @var array<string, mixed> */
            return $fn($request)->throw()->json() ?? [];
        } catch (RequestException $e) {
            report($e);
            $message = $e->response->json('error.description') ?? 'The payment provider rejected the request.';

            throw new GatewayException((string) $message);
        }
    }
}

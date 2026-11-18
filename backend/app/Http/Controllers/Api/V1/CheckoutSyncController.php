<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\GatewayException;
use App\Billing\Gateways\RazorpayClient;
use App\Billing\Gateways\RazorpayGateway;
use App\Billing\Webhooks\RazorpayEventHandler;
use App\Billing\Webhooks\StripeEventHandler;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;

/**
 * Called by the success page right after payment so access is granted immediately,
 * without waiting for the webhook (which still arrives and is idempotent).
 */
class CheckoutSyncController extends Controller
{
    public function stripe(Request $request, string $sessionId, StripeEventHandler $handler): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId, ['expand' => ['subscription']]);
        } catch (ApiErrorException) {
            throw new GatewayException('Checkout session not found.', 'session_not_found', 404);
        }

        abort_unless($session->client_reference_id === $user->uuid, 404);

        $sub = $handler->syncCheckoutSession($session->toArray());

        return $this->respond($sub, (string) $session->status);
    }

    public function razorpay(Request $request, RazorpayClient $api, RazorpayGateway $gateway, RazorpayEventHandler $handler): JsonResponse
    {
        $data = $request->validate([
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
            'razorpay_subscription_id' => ['required_without:razorpay_order_id', 'nullable', 'string', 'max:64'],
            'razorpay_order_id' => ['required_without:razorpay_subscription_id', 'nullable', 'string', 'max:64'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $isOrder = ! empty($data['razorpay_order_id']);
        $reference = (string) ($isOrder ? $data['razorpay_order_id'] : $data['razorpay_subscription_id']);

        if (! $api->verifyPaymentSignature($data['razorpay_payment_id'], $reference, $data['razorpay_signature'], $isOrder)) {
            throw new GatewayException('Payment verification failed.', 'signature_invalid');
        }

        if ($isOrder) {
            $order = $api->get('/orders/'.$reference);
            abort_unless(($order['notes']['user_uuid'] ?? null) === $user->uuid, 404);
            $sub = $handler->syncLifetimeOrder($order, $gateway->fetchPayment($data['razorpay_payment_id']));
        } else {
            $remote = $gateway->fetchSubscription($reference);
            abort_unless(($remote['notes']['user_uuid'] ?? null) === $user->uuid, 404);
            $sub = $handler->syncSubscription($remote);
        }

        return $this->respond($sub, 'complete');
    }

    private function respond(?Subscription $sub, string $checkoutStatus): JsonResponse
    {
        return response()->json(['data' => [
            'checkout_status' => $checkoutStatus,
            'subscription' => $sub ? new SubscriptionResource($sub->load('plan', 'planPrice', 'scheduledPrice.plan')) : null,
        ]]);
    }
}

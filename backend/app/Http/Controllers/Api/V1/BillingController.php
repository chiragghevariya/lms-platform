<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\BillingService;
use App\Billing\CouponService;
use App\Billing\GatewayException;
use App\Billing\GatewayResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingDetailsRequest;
use App\Http\Requests\Billing\CheckoutRequest;
use App\Http\Resources\SubscriptionResource;
use App\Http\Resources\UserResource;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    /** Current membership (highest access-granting one), or the most recent one if none is active. */
    public function subscription(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $sub = $user->activeSubscription()
            ?? $user->subscriptions()->where('status', '!=', 'incomplete')->latest('id')->first();

        return response()->json(['data' => $sub ? new SubscriptionResource($sub->load('plan', 'planPrice', 'scheduledPrice.plan')) : null]);
    }

    public function validateCoupon(CheckoutRequest $request, CouponService $coupons, GatewayResolver $gateways): JsonResponse
    {
        $request->validate(['coupon_code' => ['required', 'string', 'max:40']]);
        $price = $request->price();
        $result = $coupons->validate((string) $request->validated('coupon_code'), $this->user($request), $price, $gateways->forPrice($price)->gateway());

        return response()->json(['data' => [
            'code' => $result['coupon']->code,
            'discount' => Money::of($result['discount'], $price->currency)->toArray(),
            'final_price' => Money::of($result['final_amount'], $price->currency)->toArray(),
            'applies_to' => $price->isLifetime() ? 'one_time' : 'first_payment',
        ]]);
    }

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $result = $this->billing->checkout($this->user($request), $request->price(), $request->validated('coupon_code'));

        return response()->json(['data' => $result->toArray()], 201);
    }

    public function previewChange(CheckoutRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->billing->previewChange($this->current($request), $request->price())]);
    }

    public function changePlan(CheckoutRequest $request): SubscriptionResource
    {
        $sub = $this->billing->changePlan($this->current($request), $request->price());

        return new SubscriptionResource($sub->load('plan', 'planPrice', 'scheduledPrice.plan'));
    }

    public function cancel(Request $request): SubscriptionResource
    {
        return new SubscriptionResource($this->billing->cancel($this->current($request))->load('plan', 'planPrice', 'scheduledPrice.plan'));
    }

    public function resume(Request $request): SubscriptionResource
    {
        return new SubscriptionResource($this->billing->resume($this->current($request))->load('plan', 'planPrice', 'scheduledPrice.plan'));
    }

    /** Stripe Customer Portal (update card). */
    public function portal(Request $request): JsonResponse
    {
        $url = $this->billing->paymentMethodUrl($this->user($request), $this->current($request), config('lms.frontend_url').'/app/billing');
        if (! $url) {
            throw new GatewayException('Card updates for this payment method are handled during renewal. Contact support if you need help.', 'portal_unavailable', 409);
        }

        return response()->json(['data' => ['url' => $url]]);
    }

    public function updateDetails(BillingDetailsRequest $request): UserResource
    {
        $user = $this->user($request);
        $data = $request->validated();
        if (isset($data['tax_id'])) {
            $data['tax_id'] = strtoupper($data['tax_id']);
        }
        $user->forceFill($data)->save();

        return new UserResource($user);
    }

    private function current(Request $request): Subscription
    {
        // Mutating actions must see the latest state, never a relation cached earlier in the request.
        $this->user($request)->unsetRelation('liveSubscriptions');
        $sub = $this->user($request)->activeSubscription()
            ?? $this->user($request)->subscriptions()->where('status', 'past_due')->latest('id')->first();

        if (! $sub) {
            throw new GatewayException('You do not have an active membership.', 'no_subscription', 404);
        }

        $this->authorize('manage', $sub);

        return $sub;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

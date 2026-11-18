<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Billing\GatewayException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\GrantSubscriptionRequest;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class UserSubscriptionController extends Controller
{
    public function index(User $user): AnonymousResourceCollection
    {
        $this->authorize('view', $user);

        return SubscriptionResource::collection($user->subscriptions()->with('plan', 'planPrice', 'scheduledPrice.plan')->latest('id')->get());
    }

    public function payments(User $user): AnonymousResourceCollection
    {
        $this->authorize('view', $user);

        return PaymentResource::collection($user->payments()->latest('id')->paginate(50));
    }

    /** Complimentary membership (no payment). */
    public function store(GrantSubscriptionRequest $request, User $user, MembershipService $membership): JsonResponse
    {
        $plan = Plan::where('uuid', $request->validated('plan_id'))->firstOrFail();
        /** @var User $admin */
        $admin = $request->user();

        $sub = $membership->grantComplimentary(
            $user,
            $plan,
            $request->filled('ends_at') ? Carbon::parse((string) $request->validated('ends_at')) : null,
            $admin,
            $request->validated('notes'),
        );

        return (new SubscriptionResource($sub->load('plan')))->response()->setStatusCode(201);
    }

    /** Revokes a complimentary membership. Paid ones are cancelled by the customer or in the gateway dashboard. */
    public function cancel(User $user, Subscription $subscription, MembershipService $membership): SubscriptionResource
    {
        abort_unless($subscription->user_id === $user->id, 404);
        $this->authorize('cancel', $subscription);

        if (! $subscription->isManual()) {
            throw new GatewayException('Paid memberships must be cancelled by the member or in the payment provider dashboard.', 'not_manual', 409);
        }

        return new SubscriptionResource($membership->revokeComplimentary($subscription)->load('plan'));
    }
}

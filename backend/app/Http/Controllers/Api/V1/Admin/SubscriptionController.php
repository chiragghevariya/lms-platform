<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPaymentResource;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class SubscriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(SubscriptionStatus::class)],
            'gateway' => ['nullable', Rule::enum(Gateway::class)],
            'tier' => ['nullable', 'integer', 'between:1,3'],
            'renewing' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $subs = Subscription::query()->with('plan', 'planPrice', 'scheduledPrice.plan', 'user:id,uuid,name,email')
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes((string) $s, '%_\\').'%';
                $q->where(fn ($q) => $q->where('gateway_subscription_id', $s)->orWhere('uuid', $s)
                    ->orWhereHas('user', fn ($q) => $q->where('email', 'like', $like)->orWhere('name', 'like', $like)));
            })
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['gateway'] ?? null, fn ($q, $v) => $q->where('gateway', $v))
            ->when($f['tier'] ?? null, fn ($q, $v) => $q->whereHas('plan', fn ($q) => $q->where('tier_level', $v)))
            ->when(isset($f['renewing']), fn ($q) => $q->where('cancel_at_period_end', ! filter_var($f['renewing'], FILTER_VALIDATE_BOOLEAN)))
            ->latest('id')->paginate($f['per_page'] ?? 25)->withQueryString();

        return SubscriptionResource::collection($subs)->additional(['meta' => [
            'counts' => Subscription::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]]);
    }

    public function show(Subscription $subscription): JsonResponse
    {
        $subscription->load('plan', 'planPrice', 'scheduledPrice.plan', 'user:id,uuid,name,email');

        return response()->json(['data' => [
            ...(new SubscriptionResource($subscription))->resolve(),
            'user' => ['id' => $subscription->user->uuid, 'name' => $subscription->user->name, 'email' => $subscription->user->email],
            'gateway_subscription_id' => $subscription->gateway_subscription_id,
            'created_at' => $subscription->created_at?->toIso8601String(),
            'payments' => AdminPaymentResource::collection($subscription->payments()->latest('id')->limit(24)->get()),
        ]]);
    }
}

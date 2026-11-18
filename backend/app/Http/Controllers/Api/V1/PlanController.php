<?php

namespace App\Http\Controllers\Api\V1;

use App\Billing\GatewayResolver;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    /** Active plans with prices in one currency (?currency=INR|USD; defaults by user country). */
    public function index(Request $request, GatewayResolver $gateways): AnonymousResourceCollection
    {
        $data = $request->validate(['currency' => ['nullable', Rule::in(config('billing.supported_currencies'))]]);
        $currency = $data['currency'] ?? $gateways->currencyFor($request->user() ?? $request->user('sanctum'));

        $plans = Plan::active()->orderBy('sort')->orderBy('tier_level')
            ->with(['prices' => fn ($q) => $q->active()->where('currency', $currency)->orderBy('amount')])
            ->get()
            ->filter(fn (Plan $p) => $p->prices->isNotEmpty())
            ->values();

        return PlanResource::collection($plans)->additional(['meta' => ['currency' => $currency]]);
    }

    public function show(string $plan): PlanResource
    {
        $model = Plan::active()->where('slug', $plan)->orWhere(fn ($q) => $q->active()->where('uuid', $plan))
            ->with(['prices' => fn ($q) => $q->active()->orderBy('amount')])->firstOrFail();

        return new PlanResource($model);
    }
}

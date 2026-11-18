<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanPriceRequest;
use App\Http\Requests\Admin\PlanRequest;
use App\Http\Resources\PlanPriceResource;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Plan::class);

        return PlanResource::collection(Plan::with(['prices' => fn ($q) => $q->orderBy('currency')->orderBy('amount')])->orderBy('sort')->get());
    }

    public function store(PlanRequest $request): JsonResponse
    {
        return (new PlanResource(Plan::create($request->validated())->load('prices')))->response()->setStatusCode(201);
    }

    public function update(PlanRequest $request, Plan $plan): PlanResource
    {
        $plan->update($request->validated());

        return new PlanResource($plan->load('prices'));
    }

    public function destroy(Plan $plan): Response
    {
        $this->authorize('delete', $plan);
        $plan->update(['is_active' => false]);
        $plan->delete();

        return response()->noContent();
    }

    public function storePrice(PlanPriceRequest $request, Plan $plan): JsonResponse
    {
        $data = $request->validated();
        $data['currency'] = strtoupper($data['currency']);

        return (new PlanPriceResource($plan->prices()->create($data)))->response()->setStatusCode(201);
    }

    public function updatePrice(PlanPriceRequest $request, Plan $plan, PlanPrice $price): PlanPriceResource
    {
        abort_unless($price->plan_id === $plan->id, 404);
        $price->update($request->validated());

        return new PlanPriceResource($price);
    }
}

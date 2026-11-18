<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CouponRequest;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

class CouponController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('manage', Coupon::class);

        return CouponResource::collection(Coupon::with('plans')->latest('id')->get()); // admins see every coupon (small table)
    }

    public function store(CouponRequest $request): JsonResponse
    {
        $coupon = Coupon::create(Arr::except($request->validated(), 'plan_ids'));
        $this->syncPlans($coupon, $request->validated('plan_ids'));

        return (new CouponResource($coupon->load('plans')))->response()->setStatusCode(201);
    }

    public function update(CouponRequest $request, Coupon $coupon): CouponResource
    {
        $coupon->update(Arr::except($request->validated(), 'plan_ids'));
        if ($request->has('plan_ids')) {
            $this->syncPlans($coupon, $request->validated('plan_ids'));
        }

        return new CouponResource($coupon->load('plans'));
    }

    public function destroy(Coupon $coupon): Response
    {
        $this->authorize('manage', Coupon::class);
        $coupon->update(['is_active' => false]);
        $coupon->delete();

        return response()->noContent();
    }

    /** @param list<string>|null $uuids */
    private function syncPlans(Coupon $coupon, ?array $uuids): void
    {
        if ($uuids !== null) {
            $coupon->plans()->sync(Plan::whereIn('uuid', $uuids)->pluck('id'));
        }
    }
}

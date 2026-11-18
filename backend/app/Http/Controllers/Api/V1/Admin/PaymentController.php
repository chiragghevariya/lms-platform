<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Admin\PaymentQuery;
use App\Billing\AdminRefunds;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPaymentResource;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate(PaymentQuery::rules());
        $query = PaymentQuery::build($filters);

        // Totals for the filtered set, per currency (shown above the table).
        $totals = (clone $query)->reorder()->whereIn('status', ['succeeded', 'refunded'])->groupBy('currency')
            ->select('currency', DB::raw('SUM(amount) as gross'), DB::raw('SUM(refunded_amount) as refunded'), DB::raw('COUNT(*) as n'))
            ->get()->map(fn ($r) => ['currency' => $r->currency, 'gross' => (int) $r->getAttribute('gross'), 'refunded' => (int) $r->getAttribute('refunded'), 'count' => (int) $r->getAttribute('n')]);

        return AdminPaymentResource::collection($query->with('user:id,uuid,name,email', 'subscription:id,uuid')->paginate($filters['per_page'] ?? 25)->withQueryString())
            ->additional(['meta' => ['totals' => $totals]]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $payment->load('user:id,uuid,name,email', 'subscription:id,uuid');
        $history = Activity::where('subject_type', $payment->getMorphClass())->where('subject_id', $payment->id)->with('causer')->latest('id')->get()
            ->map(fn (Activity $a) => ['event' => $a->event, 'description' => $a->description, 'properties' => $a->properties, 'causer' => $a->causer instanceof User ? $a->causer->name : null, 'created_at' => $a->created_at?->toIso8601String()]);

        return response()->json(['data' => [...(new AdminPaymentResource($payment))->resolve(), 'history' => $history]]);
    }

    public function refund(Request $request, Payment $payment, AdminRefunds $refunds): AdminPaymentResource
    {
        $data = $request->validate([
            'amount' => ['nullable', 'integer', 'min:1'], // minor units; omit = full remaining balance
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        /** @var User $admin */
        $admin = $request->user();

        return new AdminPaymentResource($refunds->refund($payment, $data['amount'] ?? null, $data['reason'], $admin)->load('user:id,uuid,name,email', 'subscription:id,uuid'));
    }
}

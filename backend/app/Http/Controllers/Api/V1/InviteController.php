<?php

namespace App\Http\Controllers\Api\V1;

use App\Affiliates\StudentReferrals;
use App\Http\Controllers\Controller;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Student "invite a friend" link and the rewards earned with it. */
class InviteController extends Controller
{
    public function __invoke(Request $request, StudentReferrals $students): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $code = $students->inviteCode($user);
        $rewards = ReferralReward::with('friend')->where('referrer_id', $user->id)->latest('id')->limit(100)->get();

        return response()->json(['data' => [
            'enabled' => (bool) config('affiliates.student_rewards.enabled'),
            'code' => $code,
            'link' => config('lms.frontend_url').'/register?invite='.$code,
            'reward' => ['type' => config('affiliates.student_rewards.type'), 'days' => (int) config('affiliates.student_rewards.days'),
                'credit' => config('affiliates.student_rewards.credit'), 'hold_days' => (int) config('affiliates.hold_days')],
            'stats' => [
                'invited' => $rewards->count(),
                'subscribed' => $rewards->whereIn('status', ['pending', 'banked', 'granted'])->count(),
                'days_earned' => (int) $rewards->whereIn('status', ['banked', 'granted'])->sum('days'),
                'days_banked' => (int) $rewards->where('status', 'banked')->sum('days'),
            ],
            'rewards' => $rewards->map(fn (ReferralReward $r) => [
                'id' => $r->uuid, 'friend' => explode(' ', $r->friend->name)[0], 'status' => $r->status, 'type' => $r->type,
                'days' => $r->days, 'credit_amount' => $r->credit_amount, 'currency' => $r->currency,
                'available_at' => $r->available_at?->toIso8601String(), 'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }
}

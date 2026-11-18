<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Http\Controllers\Controller;
use App\Instructors\EarningsService;
use App\Models\InstructorProfile;
use App\Models\PayoutStatement;
use App\Models\PayoutStatementLine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** An instructor's own statements (+ this month's running estimate). */
class EarningsController extends Controller
{
    public function index(Request $request, EarningsService $earnings): JsonResponse
    {
        $user = $this->user($request);
        $period = now()->utc()->format('Y-m');

        return response()->json(['data' => [
            'revenue_share_percent' => InstructorProfile::firstOrNew(['user_id' => $user->id])->sharePercent(),
            'pool_percent' => (int) config('earnings.pool_percent'),
            'current' => [
                'period' => $period,
                'estimate' => $earnings->compute($period, $user->id)->map(fn ($r) => array_diff_key($r, ['lines' => 1]) + [
                    'lines' => array_map(fn ($l) => array_diff_key($l, ['payment_id' => 1, 'course_id' => 1]), $r['lines']),
                ])->values(),
            ],
            'statements' => PayoutStatement::where('instructor_id', $user->id)->orderByDesc('period')->limit(36)->get()
                ->map(fn (PayoutStatement $s) => self::statementPayload($s))->values(),
        ]]);
    }

    public function show(Request $request, PayoutStatement $statement): JsonResponse
    {
        abort_unless($statement->instructor_id === $this->user($request)->id, 404);

        return response()->json(['data' => self::statementPayload($statement, true)]);
    }

    /** @return array<string, mixed> */
    public static function statementPayload(PayoutStatement $s, bool $withLines = false): array
    {
        return [
            'id' => $s->uuid, 'period' => $s->period, 'currency' => $s->currency, 'status' => $s->status,
            'pool_percent' => $s->pool_percent, 'revenue_share_percent' => $s->revenue_share_percent,
            'subscription_revenue' => $s->subscription_revenue, 'pool_amount' => $s->pool_amount,
            'watch_seconds' => $s->watch_seconds, 'total_watch_seconds' => $s->total_watch_seconds,
            'subscription_earnings' => $s->subscription_earnings, 'sales_earnings' => $s->sales_earnings, 'total' => $s->total,
            'finalized_at' => $s->finalized_at?->toIso8601String(), 'paid_at' => $s->paid_at?->toIso8601String(), 'paid_reference' => $s->paid_reference,
            'lines' => $withLines ? $s->lines()->with('course:id,title')->get()->map(fn (PayoutStatementLine $l) => [
                'type' => $l->type, 'description' => $l->description, 'course' => $l->course?->title,
                'watch_seconds' => $l->watch_seconds, 'gross' => $l->gross, 'amount' => $l->amount,
            ])->values() : null,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Instructor\EarningsController;
use App\Http\Controllers\Api\V1\Instructor\ProfileController;
use App\Http\Controllers\Controller;
use App\Instructors\EarningsService;
use App\Models\InstructorProfile;
use App\Models\PayoutStatement;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Admin: generate monthly statements, finalize, mark paid; per-instructor revenue share. */
class PayoutController extends Controller
{
    public function __construct(private readonly EarningsService $earnings) {}

    public function index(Request $request): JsonResponse
    {
        $period = (string) $request->query('period', now()->utc()->subMonthNoOverflow()->format('Y-m'));
        $this->period($period);
        $statements = PayoutStatement::with('instructor.instructorProfile')->where('period', $period)->orderByDesc('total')->get();

        return response()->json([
            'data' => $statements->map(fn (PayoutStatement $s) => [
                ...EarningsController::statementPayload($s),
                'instructor' => ['id' => $s->instructor->uuid, 'name' => $s->instructor->name, 'email' => $s->instructor->email],
                'payout' => [
                    'method' => $s->instructor->instructorProfile?->payout_method,
                    'summary' => $s->instructor->instructorProfile ? ProfileController::maskedPayout($s->instructor->instructorProfile) : null,
                ],
            ])->values(),
            'meta' => [
                'period' => $period,
                'totals' => $statements->groupBy('currency')->map(fn ($g) => ['total' => (int) $g->sum('total'), 'paid' => (int) $g->where('status', 'paid')->sum('total')]),
            ],
        ]);
    }

    public function show(PayoutStatement $statement): JsonResponse
    {
        $statement->load('instructor.instructorProfile');
        $profile = $statement->instructor->instructorProfile;

        // Full payout details are only ever shown here, to admins paying the statement.
        return response()->json(['data' => [
            ...EarningsController::statementPayload($statement, true),
            'instructor' => ['id' => $statement->instructor->uuid, 'name' => $statement->instructor->name, 'email' => $statement->instructor->email],
            'payout' => ['method' => $profile?->payout_method, 'details' => $profile?->payout_details, 'tax_id' => $profile?->tax_id],
        ]])->header('Cache-Control', 'no-store');
    }

    public function generate(Request $request): JsonResponse
    {
        $period = (string) $request->validate(['period' => ['required', 'string']])['period'];
        $this->period($period);
        try {
            $written = $this->earnings->generate($period);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => ['period' => [$e->getMessage()]]], 422);
        }

        return response()->json(['data' => ['written' => $written]]);
    }

    public function finalize(Request $request): JsonResponse
    {
        $period = (string) $request->validate(['period' => ['required', 'string']])['period'];
        $this->period($period);

        return response()->json(['data' => ['finalized' => $this->earnings->finalize($period)]]);
    }

    public function markPaid(Request $request, PayoutStatement $statement): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:120']]);
        /** @var User $admin */
        $admin = $request->user();

        return response()->json(['data' => EarningsController::statementPayload($this->earnings->markPaid($statement, $admin, $data['reference']))]);
    }

    public function instructors(): JsonResponse
    {
        $users = User::role('instructor')->with('instructorProfile')->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn (User $u) => [
            'id' => $u->uuid, 'name' => $u->name, 'email' => $u->email,
            'revenue_share_percent' => $u->instructorProfile?->sharePercent() ?? (int) config('earnings.default_revenue_share_percent'),
            'custom_share' => $u->instructorProfile?->revenue_share_percent !== null,
            'payout_ready' => $u->instructorProfile?->payout_method !== null,
        ])->values(), 'meta' => ['pool_percent' => (int) config('earnings.pool_percent'), 'default_share' => (int) config('earnings.default_revenue_share_percent')]]);
    }

    public function updateShare(Request $request, User $user): JsonResponse
    {
        abort_unless($user->hasRole('instructor'), 404);
        $data = $request->validate(['revenue_share_percent' => ['nullable', 'integer', 'between:0,100']]);
        InstructorProfile::firstOrCreate(['user_id' => $user->id])->forceFill(['revenue_share_percent' => $data['revenue_share_percent']])->save();

        return response()->json(['data' => ['revenue_share_percent' => $data['revenue_share_percent'] ?? (int) config('earnings.default_revenue_share_percent')]]);
    }

    private function period(string $period): void
    {
        try {
            $this->earnings->range($period);
        } catch (InvalidArgumentException $e) {
            abort(response()->json(['message' => $e->getMessage(), 'errors' => ['period' => [$e->getMessage()]]], 422));
        }
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Affiliates\AffiliateProgram;
use App\Affiliates\CommissionEngine;
use App\Affiliates\Payouts\PayoutService;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\Commission;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Admin API for the affiliate programme (the admin UI arrives in Phase 9). */
class AffiliateController extends Controller
{
    public function __construct(private readonly AffiliateProgram $program, private readonly PayoutService $payouts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'suspended'])]]);
        $rows = Affiliate::with('user')->withCount(['referrals', 'referrals as conversions_count' => fn ($q) => $q->whereNotNull('converted_at')])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(30);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Affiliate $a) => [
                'id' => $a->uuid, 'code' => $a->code, 'status' => $a->status, 'tier' => $a->tier,
                'terms' => $a->commissionTerms(), 'website' => $a->website, 'promotion_plan' => $a->promotion_plan,
                'user' => ['id' => $a->user->uuid, 'name' => $a->user->name, 'email' => $a->user->email],
                'referrals' => $a->referrals_count, 'conversions' => $a->getAttribute('conversions_count'),
                'balances' => $this->payouts->balances($a), 'payout_method' => $a->payout_method,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function decide(Request $request, Affiliate $affiliate, string $action): JsonResponse
    {
        $data = $request->validate(['notes' => [$action === 'approve' ? 'nullable' : 'required', 'string', 'max:2000']]);
        $affiliate->load('user');
        match ($action) {
            'approve' => $this->program->approve($affiliate, $data['notes'] ?? null),
            'reject' => $this->program->reject($affiliate, (string) $data['notes']),
            'suspend' => $this->program->suspend($affiliate, (string) $data['notes']),
            default => abort(404),
        };

        return response()->json(['data' => ['id' => $affiliate->uuid, 'status' => $affiliate->status]]);
    }

    public function updateTerms(Request $request, Affiliate $affiliate): JsonResponse
    {
        $data = $request->validate([
            'tier' => ['sometimes', Rule::in(array_keys((array) config('affiliates.tiers')))],
            'commission_type' => ['sometimes', 'nullable', Rule::in(['percent', 'fixed'])],
            'commission_rate' => ['required_if:commission_type,percent', 'nullable', 'integer', 'between:0,100'],
            'commission_fixed' => ['required_if:commission_type,fixed', 'nullable', 'array'],
            'commission_fixed.*' => ['integer', 'min:0'],
        ]);
        $affiliate->forceFill($data)->save();

        return response()->json(['data' => ['tier' => $affiliate->tier, 'terms' => $affiliate->commissionTerms()]]);
    }

    /** Fraud review queue: flagged referrals and their held commissions. */
    public function reviews(): JsonResponse
    {
        $rows = Referral::with('affiliate.user', 'referredUser')->where('status', 'flagged')->oldest()->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (Referral $r) => [
            'id' => $r->uuid, 'flags' => $r->flags ?? [],
            'affiliate' => ['code' => $r->affiliate->code, 'name' => $r->affiliate->user->name, 'email' => $r->affiliate->user->email],
            'customer' => ['name' => $r->referredUser->name, 'email' => $r->referredUser->email],
            'held' => Commission::where('referral_id', $r->id)->where('needs_review', true)->get(['currency', 'amount'])
                ->groupBy('currency')->map(fn ($g) => (int) $g->sum('amount')),
            'created_at' => $r->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function review(Request $request, Referral $referral, CommissionEngine $engine): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);
        /** @var User $admin */
        $admin = $request->user();
        $engine->review($referral, (bool) $data['approve'], $admin->id);

        return response()->json(['data' => ['id' => $referral->uuid, 'status' => $referral->fresh()?->status]]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $period = (string) $request->query('period', now()->format('Y-m'));

        return response()->json(['data' => AffiliatePayout::with('affiliate.user')->where('period', $period)->orderBy('id')->get()->map(fn (AffiliatePayout $p) => [
            'id' => $p->uuid, 'period' => $p->period, 'currency' => $p->currency, 'amount' => $p->amount, 'status' => $p->status,
            'commissions_count' => $p->commissions_count, 'method' => $p->method, 'gateway' => $p->gateway, 'reference' => $p->reference,
            'affiliate' => ['code' => $p->affiliate->code, 'name' => $p->affiliate->user->name, 'email' => $p->affiliate->user->email],
        ])->values()]);
    }

    public function batch(Request $request): JsonResponse
    {
        $period = (string) $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['period'];

        return response()->json(['data' => ['created' => $this->payouts->batch($period)]]);
    }

    public function export(Request $request): Response
    {
        $period = (string) $request->validate(['period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']])['period'];

        return response($this->payouts->csv($period), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"affiliate-payouts-{$period}.csv\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function markPaid(Request $request, AffiliatePayout $payout): JsonResponse
    {
        $data = $request->validate(['reference' => ['required', 'string', 'max:120']]);
        /** @var User $admin */
        $admin = $request->user();

        return response()->json(['data' => ['status' => $this->payouts->markPaid($payout, $admin, $data['reference'])->status]]);
    }

    public function markFailed(AffiliatePayout $payout): JsonResponse
    {
        return response()->json(['data' => ['status' => $this->payouts->markFailed($payout)->status]]);
    }
}

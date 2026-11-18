<?php

namespace App\Http\Controllers\Api\V1;

use App\Affiliates\AffiliateProgram;
use App\Affiliates\Payouts\PayoutService;
use App\Http\Controllers\Controller;
use App\Models\Affiliate;
use App\Models\AffiliatePayout;
use App\Models\Commission;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** The affiliate's own area: apply, status, funnel, earnings, commissions, payouts, payout details, assets. */
class AffiliateController extends Controller
{
    public function show(Request $request, PayoutService $payouts): JsonResponse
    {
        $a = Affiliate::where('user_id', $this->user($request)->id)->first();
        if (! $a) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => [
            'id' => $a->uuid,
            'code' => $a->code,
            'status' => $a->status,
            'review_notes' => in_array($a->status, ['rejected', 'suspended'], true) ? $a->review_notes : null,
            'link' => config('lms.frontend_url').'/?ref='.$a->code,
            'terms' => $a->commissionTerms() + [
                'recurring_months' => (int) config('affiliates.recurring_months'),
                'hold_days' => (int) config('affiliates.hold_days'),
                'cookie_days' => (int) config('affiliates.cookie.days'),
                'attribution' => config('affiliates.attribution'),
            ],
            'payout' => [
                'method' => $a->payout_method,
                'summary' => $this->maskedPayout($a),
                'minimum' => config('affiliates.payouts.minimum'),
            ],
            'balances' => $a->isApproved() ? $this->balances($a, $payouts) : null,
        ]]);
    }

    public function apply(Request $request, AffiliateProgram $program): JsonResponse
    {
        $data = $request->validate([
            'website' => ['nullable', 'url:https', 'max:300'],
            'promotion_plan' => ['required', 'string', 'min:30', 'max:3000'],
            'accept_terms' => ['accepted'],
        ]);
        $a = $program->apply($this->user($request), $data);

        return response()->json(['data' => ['id' => $a->uuid, 'status' => $a->status, 'code' => $a->code]], 201);
    }

    public function updatePayout(Request $request): JsonResponse
    {
        $a = $this->affiliate($request);
        $data = $request->validate([
            'payout_method' => ['required', Rule::in(['bank', 'upi', 'paypal'])],
            'payout_details.account_name' => ['required_if:payout_method,bank', 'nullable', 'string', 'max:120'],
            'payout_details.account_number' => ['required_if:payout_method,bank', 'nullable', 'string', 'regex:/^\d{6,18}$/'],
            'payout_details.ifsc' => ['required_if:payout_method,bank', 'nullable', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'payout_details.upi_id' => ['required_if:payout_method,upi', 'nullable', 'string', 'regex:/^[\w.\-]{2,256}@[a-zA-Z]{2,64}$/'],
            'payout_details.paypal_email' => ['required_if:payout_method,paypal', 'nullable', 'email', 'max:254'],
        ]);
        $keys = ['bank' => ['account_name', 'account_number', 'ifsc'], 'upi' => ['upi_id'], 'paypal' => ['paypal_email']][$data['payout_method']];
        $a->forceFill(['payout_method' => $data['payout_method'], 'payout_details' => array_intersect_key((array) ($data['payout_details'] ?? []), array_flip($keys))])->save();

        return response()->json(['data' => ['method' => $a->payout_method, 'summary' => $this->maskedPayout($a)]]);
    }

    /** Clicks → sign-ups → paying customers for a date range, with daily series and top campaigns. */
    public function stats(Request $request): JsonResponse
    {
        $a = $this->affiliate($request);
        $days = (int) $request->validate(['days' => ['nullable', Rule::in([7, 30, 90, 365])]])['days'] ?: 30;
        $from = now()->subDays($days - 1)->startOfDay();

        $clicks = DB::table('referral_clicks')->where('affiliate_id', $a->id)->where('created_at', '>=', $from);
        $signups = Referral::where('affiliate_id', $a->id)->where('created_at', '>=', $from)->where('status', '!=', 'rejected');
        $conversions = Referral::where('affiliate_id', $a->id)->whereNotNull('converted_at')->where('converted_at', '>=', $from)->where('status', '!=', 'rejected');
        $daily = fn ($q, string $col) => $q->selectRaw("DATE({$col}) as d, COUNT(*) as n")->groupBy('d')->pluck('n', 'd');
        $c = $daily(clone $clicks, 'created_at');
        $s = $daily(clone $signups, 'created_at');
        $v = $daily(clone $conversions, 'converted_at');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $series[] = ['date' => $d, 'clicks' => (int) ($c[$d] ?? 0), 'signups' => (int) ($s[$d] ?? 0), 'conversions' => (int) ($v[$d] ?? 0)];
        }
        $nClicks = (clone $clicks)->count();
        $nSignups = (clone $signups)->count();
        $nConv = (clone $conversions)->count();

        return response()->json(['data' => [
            'range_days' => $days,
            'funnel' => [
                'clicks' => $nClicks, 'unique_visitors' => (clone $clicks)->distinct()->count('ip_hash'),
                'signups' => $nSignups, 'conversions' => $nConv,
                'signup_rate' => $nClicks ? round($nSignups * 100 / $nClicks, 1) : null,
                'conversion_rate' => $nSignups ? round($nConv * 100 / $nSignups, 1) : null,
            ],
            'daily' => $series,
            'campaigns' => DB::table('referral_clicks')->where('affiliate_id', $a->id)->where('created_at', '>=', $from)->whereNotNull('utm')->get(['utm'])
                ->map(fn ($r) => json_decode((string) $r->utm, true)['utm_campaign'] ?? null)->filter()->countBy()->sortDesc()->take(10)
                ->map(fn ($n, $name) => ['campaign' => $name, 'clicks' => $n])->values(),
        ]]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $a = $this->affiliate($request);
        $rows = Commission::where('affiliate_id', $a->id)->with('referral.referredUser', 'payment')->latest('id')->paginate(30);

        return response()->json([
            'data' => $rows->getCollection()->map(fn (Commission $c) => [
                'id' => $c->uuid, 'kind' => $c->kind, 'level' => $c->level, 'currency' => $c->currency,
                'base_amount' => $c->base_amount, 'rate' => $c->rate, 'amount' => $c->amount, 'net' => $c->net(),
                'status' => $c->status, 'on_hold_for_review' => $c->needs_review,
                'available_at' => $c->available_at->toIso8601String(), 'created_at' => $c->created_at?->toIso8601String(),
                'reversal_reason' => $c->reversal_reason,
                // Only a first name — affiliates don't need their referrals' contact details.
                'customer' => explode(' ', $c->referral->referredUser->name)[0],
                'description' => $c->kind === 'clawback' ? 'Refund adjustment' : ($c->payment !== null ? $c->payment->description : 'Payment'),
            ])->values(),
            'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage()],
        ]);
    }

    public function payouts(Request $request): JsonResponse
    {
        $a = $this->affiliate($request);

        return response()->json(['data' => AffiliatePayout::where('affiliate_id', $a->id)->latest('id')->limit(60)->get()->map(fn (AffiliatePayout $p) => [
            'id' => $p->uuid, 'period' => $p->period, 'currency' => $p->currency, 'amount' => $p->amount,
            'commissions_count' => $p->commissions_count, 'status' => $p->status, 'method' => $p->method,
            'reference' => $p->reference, 'paid_at' => $p->paid_at?->toIso8601String(), 'created_at' => $p->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function assets(Request $request): JsonResponse
    {
        $a = $this->affiliate($request);
        $link = config('lms.frontend_url').'/?ref='.$a->code;
        $brand = (string) config('lms.brand.name');

        return response()->json(['data' => [
            'swipe_copy' => array_map(fn ($c) => ['title' => $c['title'], 'body' => strtr($c['body'], ['{brand}' => $brand, '{link}' => $link])], (array) config('affiliates.assets.swipe_copy')),
            'banners' => array_map(fn ($size) => ['size' => $size, 'url' => route('api.v1.affiliate-banner', ['size' => $size, 'code' => $a->code])], ['728x90', '300x250', '1080x1080']),
        ]]);
    }

    /** @return array<string, array{pending: int, approved: int, paid: int, in_payout: int}> */
    private function balances(Affiliate $a, PayoutService $payouts): array
    {
        $rows = Commission::where('affiliate_id', $a->id)->where('status', '!=', 'reversed')
            ->groupBy('currency', 'status')->selectRaw('currency, status, SUM(amount - reversed_amount) as t, SUM(CASE WHEN payout_id IS NOT NULL AND status = \'approved\' THEN amount - reversed_amount ELSE 0 END) as inp')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $cur = (string) $r->getAttribute('currency');
            $out[$cur] ??= ['pending' => 0, 'approved' => 0, 'paid' => 0, 'in_payout' => 0];
            $status = (string) $r->getAttribute('status');
            if (isset($out[$cur][$status])) {
                $out[$cur][$status] += (int) $r->getAttribute('t');
            }
            $out[$cur]['in_payout'] += (int) $r->getAttribute('inp');
        }

        return $out;
    }

    private function maskedPayout(Affiliate $a): ?string
    {
        $d = $a->payout_details ?? [];

        return match ($a->payout_method) {
            'bank' => ($d['account_name'] ?? '').' · A/C ••••'.substr((string) ($d['account_number'] ?? ''), -4),
            'upi' => preg_replace('/^(.{2}).*(@.*)$/', '$1•••$2', (string) ($d['upi_id'] ?? '')),
            'paypal' => preg_replace('/^(.{2}).*(@.*)$/', '$1•••$2', (string) ($d['paypal_email'] ?? '')),
            default => null,
        };
    }

    private function affiliate(Request $request): Affiliate
    {
        $a = Affiliate::where('user_id', $this->user($request)->id)->first();
        abort_unless($a && in_array($a->status, ['approved', 'suspended'], true), 403, 'Your affiliate account isn\'t active.');

        return $a;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

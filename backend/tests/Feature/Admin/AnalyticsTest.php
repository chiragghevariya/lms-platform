<?php

use App\Analytics\AnalyticsService;
use App\Enums\BillingInterval;
use App\Enums\Gateway;
use App\Enums\MembershipTier;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Events\PaymentSucceeded;
use App\Models\MetricSnapshot;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Range 2026-11-01 … 2026-11-12 (Asia/Kolkata). "Now" = 2026-11-12 17:30 IST.
 *  A  user1 Pro monthly   active     paid Oct 1 (99 900) + Nov 10 (99 900, tax 15 239)
 *  B  user2 Elite yearly  active     trial started Nov 4, paid Nov 5 (119 880, tax 18 287)
 *  C  user4 Pro monthly   cancelled  paid Oct 1 + Nov 1 (99 900; 30 000 refunded Nov 8), ended Nov 9
 *  D  user5 Starter       trialing   trial started Nov 3, never paid
 *  E  user7 Pro           complimentary
 *  course sales: user3 Nov 11 (49 900), user6 today (49 900), USD course Nov 10, one failed payment.
 */
beforeEach(function () {
    config(['analytics.timezone' => 'Asia/Kolkata', 'billing.default_currency' => 'INR']);
    $this->travelTo(Carbon::parse('2026-11-12 12:00:00', 'UTC'));

    $pro = planFor(MembershipTier::Pro);
    $elite = planFor(MembershipTier::Elite, BillingInterval::Year);
    $starter = planFor(MembershipTier::Starter);

    $sub = fn (User $u, $price, array $a) => Subscription::factory()->for($u)->forPrice($price)->create($a);
    $pay = fn (array $a) => Payment::factory()->create($a);
    $at = fn (string $local) => Carbon::parse($local, 'Asia/Kolkata')->utc();

    $this->u = collect(range(1, 7))->mapWithKeys(fn ($i) => [$i => User::factory()->create(['created_at' => now()->subMonths(3)])]);

    $this->A = $sub($this->u[1], $pro, ['created_at' => $at('2026-09-30 10:00')]);
    $pay(['user_id' => $this->u[1]->id, 'subscription_id' => $this->A->id, 'paid_at' => $at('2026-10-01 10:00'), 'period_start' => $at('2026-10-01'), 'period_end' => $at('2026-11-01')]);
    $pay(['user_id' => $this->u[1]->id, 'subscription_id' => $this->A->id, 'paid_at' => $at('2026-11-10 10:00'), 'period_start' => $at('2026-11-01'), 'period_end' => $at('2026-12-01')]);

    $this->B = $sub($this->u[2], $elite, ['created_at' => $at('2026-11-04 09:00'), 'trial_ends_at' => $at('2026-11-05 09:00')]);
    $pay(['user_id' => $this->u[2]->id, 'subscription_id' => $this->B->id, 'gateway' => Gateway::Razorpay, 'amount' => 119880, 'subtotal' => 101593, 'tax_amount' => 18287, 'paid_at' => $at('2026-11-05 09:00')]);

    $this->C = $sub($this->u[4], $pro, ['created_at' => $at('2026-09-30 11:00'), 'status' => SubscriptionStatus::Cancelled, 'ended_at' => $at('2026-11-09 12:00')]);
    $pay(['user_id' => $this->u[4]->id, 'subscription_id' => $this->C->id, 'paid_at' => $at('2026-10-01 11:00'), 'period_start' => $at('2026-10-01'), 'period_end' => $at('2026-11-01')]);
    $pay(['user_id' => $this->u[4]->id, 'subscription_id' => $this->C->id, 'gateway' => Gateway::Stripe, 'paid_at' => $at('2026-11-01 11:00'), 'refunded_amount' => 30000, 'refunded_at' => $at('2026-11-08 15:00')]);

    $sub($this->u[5], $starter, ['created_at' => $at('2026-11-03 08:00'), 'status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $at('2026-11-17 08:00')]);
    Subscription::factory()->for($this->u[7])->complimentary()->create(['plan_id' => $pro->plan_id]);

    $course = publishedCourse(null, 1, ['price_amount' => 49900]);
    $pay(['user_id' => $this->u[3]->id, 'course_id' => $course->id, 'amount' => 49900, 'tax_amount' => 7612, 'paid_at' => $at('2026-11-11 18:00')]);
    $pay(['user_id' => $this->u[6]->id, 'course_id' => $course->id, 'amount' => 49900, 'tax_amount' => 7612, 'paid_at' => $at('2026-11-12 13:30')]);
    $pay(['user_id' => $this->u[6]->id, 'course_id' => $course->id, 'currency' => 'USD', 'gateway' => Gateway::Stripe, 'amount' => 1500, 'tax_amount' => 0, 'paid_at' => $at('2026-11-10 13:30')]);
    $pay(['user_id' => $this->u[3]->id, 'status' => PaymentStatus::Failed, 'paid_at' => null, 'invoice_number' => null]);

    $this->admin = User::factory()->admin()->create();
});

/** Independent SQL over `payments` — what the analytics numbers must reconcile with. */
function paymentsSum(string $column, string $from, string $to, string $currency = 'INR', string $dateColumn = 'paid_at', ?callable $scope = null): int
{
    $q = DB::table('payments')->where('currency', $currency)
        ->whereBetween($dateColumn, [Carbon::parse($from, 'Asia/Kolkata')->startOfDay()->utc(), Carbon::parse($to, 'Asia/Kolkata')->endOfDay()->utc()]);
    if ($dateColumn === 'paid_at') {
        $q->whereIn('status', ['succeeded', 'refunded']);
    }
    if ($scope) {
        $scope($q);
    }

    return (int) $q->sum($column);
}

it('reconciles revenue with the payments table', function () {
    $r = app(AnalyticsService::class)->revenue('2026-11-01', '2026-11-12', 'INR');

    $gross = paymentsSum('amount', '2026-11-01', '2026-11-12');
    $refunds = paymentsSum('refunded_amount', '2026-11-01', '2026-11-12', 'INR', 'refunded_at');

    expect($gross)->toBe(99900 + 119880 + 99900 + 49900 + 49900)
        ->and($r['gross'])->toBe($gross)
        ->and($r['refunds'])->toBe($refunds)->toBe(30000)
        ->and($r['net'])->toBe($gross - $refunds)
        ->and($r['tax'])->toBe(paymentsSum('tax_amount', '2026-11-01', '2026-11-12'))
        ->and($r['membership'] + $r['courses'])->toBe($gross)
        ->and($r['courses'])->toBe(paymentsSum('amount', '2026-11-01', '2026-11-12', 'INR', 'paid_at', fn ($q) => $q->whereNotNull('course_id')))
        ->and($r['payments'])->toBe(5)
        ->and(array_sum(array_column($r['series'], 'net')))->toBe($r['net'])
        ->and(array_sum(array_column($r['by_gateway'], 'total')))->toBe($gross)
        ->and(array_sum(array_column($r['by_country'], 'total')))->toBe($gross)
        ->and(collect($r['series'])->firstWhere('period', '2026-11-08')['refunds'])->toBe(30000)
        ->and(collect($r['series'])->firstWhere('period', '2026-11-05')['membership'])->toBe(119880);

    // Other currencies never leak into INR (no FX conversion).
    expect(app(AnalyticsService::class)->revenue('2026-11-01', '2026-11-12', 'USD')['gross'])->toBe(1500);
});

it('computes MRR, ARR, churn, ARPU, LTV, trial conversion and members by tier', function () {
    $m = app(AnalyticsService::class)->membership('2026-11-01', '2026-11-12', 'INR');

    // A: 99 900 − 15 239 tax = 84 661 / month. B: (119 880 − 18 287) / 12 = 8 466 / month.
    expect($m['mrr'])->toBe(84661 + 8466)
        ->and($m['arr'])->toBe((84661 + 8466) * 12)
        ->and($m['paying_members'])->toBe(2)
        ->and($m['paying_at_start'])->toBe(2)     // A and C had paid before Nov 1
        ->and($m['churned_members'])->toBe(1)      // C ended Nov 9
        ->and($m['churn_rate'])->toBe(0.5)
        ->and($m['new_members'])->toBe(1)          // user2's first membership payment
        ->and($m['arpu'])->toBe(intdiv(84661 + 8466, 2))
        ->and($m['monthly_churn_rate'])->toBe(1.0) // 50% in 12 days, capped at 100%/month
        ->and($m['ltv'])->toBe(intdiv(84661 + 8466, 2))
        ->and($m['trials_started'])->toBe(2)
        ->and($m['trials_converted'])->toBe(1)
        ->and($m['trial_conversion'])->toBe(0.5);

    $byTier = collect($m['active_by_tier'])->keyBy('tier');
    expect($byTier[1]['members'])->toBe(1)
        ->and($byTier[2]['members'])->toBe(2)
        ->and($byTier[2]['paying'])->toBe(1)
        ->and($byTier[2]['complimentary'])->toBe(1)
        ->and($byTier[3]['members'])->toBe(1);
});

it('serves the dashboard overview with KPI cards that match the payments table', function () {
    $res = $this->actingAs($this->admin)->getJson(api('admin/analytics/overview?from=2026-11-01&to=2026-11-12&currency=INR'))->assertOk();

    $res->assertJsonPath('data.summary.mrr', 93127)
        ->assertJsonPath('data.summary.active_members', 4)
        ->assertJsonPath('data.summary.churn_rate', 0.5)
        ->assertJsonPath('data.summary.revenue_today', paymentsSum('amount', '2026-11-12', '2026-11-12'))
        ->assertJsonPath('data.summary.revenue_today', 49900)
        ->assertJsonPath('data.revenue.net', paymentsSum('amount', '2026-11-01', '2026-11-12') - 30000)
        ->assertJsonPath('meta.currencies', ['INR', 'USD'])
        ->assertJsonStructure(['data' => ['funnel' => [['step', 'label', 'count']], 'top_courses', 'members' => ['active_by_tier']]]);
});

it('caches reports and recomputes them when a payment is recorded', function () {
    $svc = app(AnalyticsService::class);
    expect($svc->revenue('2026-11-12', '2026-11-12', 'INR')['gross'])->toBe(49900);

    $p = Payment::factory()->create(['user_id' => $this->u[3]->id, 'amount' => 10000, 'tax_amount' => 0, 'paid_at' => now()]);
    expect($svc->revenue('2026-11-12', '2026-11-12', 'INR')['gross'])->toBe(49900); // cached

    event(new PaymentSucceeded($p));
    expect($svc->revenue('2026-11-12', '2026-11-12', 'INR')['gross'])->toBe(59900);
});

it('builds monthly retention cohorts from paid periods', function () {
    $rows = collect(app(AnalyticsService::class)->cohorts(3))->keyBy('cohort');

    // October cohort: user1 and user4 (both first paid Oct 1). In November user1 renewed,
    // user4's November payment was only partly refunded so they still count.
    expect($rows['2026-10']['size'])->toBe(2)
        ->and($rows['2026-10']['retention'])->toBe([1.0, 1.0])
        ->and($rows['2026-11']['size'])->toBe(1)     // user2
        ->and($rows['2026-11']['retention'])->toBe([1.0])
        ->and($rows['2026-09']['size'])->toBe(0);
});

it('counts the signup funnel for users who joined in the range', function () {
    $new = User::factory()->count(3)->create(['created_at' => Carbon::parse('2026-11-05')]);
    User::factory()->unverified()->create(['created_at' => Carbon::parse('2026-11-06')]);
    Payment::factory()->create(['user_id' => $new[0]->id, 'paid_at' => now()]);

    // Only these four joined in the range (the seeded learners joined in August).
    $steps = collect(app(AnalyticsService::class)->funnel('2026-11-05', '2026-11-06'))->pluck('count', 'step');
    expect($steps['signed_up'])->toBe(4)->and($steps['verified'])->toBe(3)->and($steps['learning'])->toBe(0)->and($steps['paid'])->toBe(1);
});

it('snapshots MRR and members nightly and serves the MRR trend', function () {
    $this->artisan('analytics:snapshot', ['--day' => '2026-11-11'])->assertSuccessful();

    expect(MetricSnapshot::where('metric', 'mrr')->where('dimension', 'INR')->value('value'))->toBe(93127)
        ->and(MetricSnapshot::where('metric', 'members')->where('dimension', 'tier:2')->value('value'))->toBe(2);

    $trend = app(AnalyticsService::class)->mrrTrend('INR', 30);
    expect(array_column($trend, 'day'))->toBe(['2026-11-11', '2026-11-12']);
});

it('reports affiliate revenue share and learning metrics', function () {
    $this->actingAs($this->admin)->getJson(api('admin/analytics/affiliates?from=2026-11-01&to=2026-11-12'))
        ->assertOk()->assertJsonPath('data.commissions', 0)->assertJsonPath('data.share_of_revenue', 0);
    $this->actingAs($this->admin)->getJson(api('admin/analytics/learning?from=2026-11-01&to=2026-11-12'))
        ->assertOk()->assertJsonStructure(['data' => ['watch_seconds', 'completion_rate', 'top_courses', 'live' => ['attendance_rate']]]);
    $this->actingAs($this->admin)->getJson(api('admin/analytics/cohorts?months=6'))->assertOk()->assertJsonCount(6, 'data');
});

it('keeps analytics away from non-admins', function () {
    $this->actingAs(User::factory()->instructor()->create())->getJson(api('admin/analytics/overview'))->assertForbidden();
    $this->actingAs($this->admin)->getJson(api('admin/analytics/overview?from=2024-01-01&to=2026-11-12'))->assertStatus(422);
});

it('exposes Plan names on the tier breakdown', function () {
    $tiers = app(AnalyticsService::class)->activeByTier();
    expect(collect($tiers)->pluck('name')->filter()->count())->toBe(3)
        ->and(Plan::count())->toBe(3);
});

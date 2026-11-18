<?php

namespace App\Notify;

use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Campaign audiences. Filters (all optional, combined with AND):
 *   tiers: ["free","starter","pro","elite"]   status: ["active","past_due","cancelled","none"]
 *   course_id: uuid (enrolled)                inactive_days: N (no learning activity for ≥ N days)
 *   countries: ["IN","US"]                    roles: ["student","instructor"]
 */
class Segment
{
    /**
     * @param  array<string, mixed>  $f
     * @return Builder<User>
     */
    public static function query(array $f): Builder
    {
        $q = User::query()->where('status', 'active');

        if (! empty($f['roles'])) {
            $q->whereHas('roles', fn ($r) => $r->whereIn('name', (array) $f['roles']));
        }
        if (! empty($f['countries'])) {
            $q->whereIn('country', array_map('strtoupper', (array) $f['countries']));
        }
        if (! empty($f['course_id'])) {
            $q->whereIn('id', DB::table('enrollments')->join('courses', 'courses.id', '=', 'enrollments.course_id')
                ->where('courses.uuid', $f['course_id'])->select('enrollments.user_id'));
        }
        if (! empty($f['inactive_days'])) {
            $cutoff = now()->subDays((int) $f['inactive_days'])->toDateString();
            $q->whereNotIn('id', DB::table('learning_days')->where('day', '>', $cutoff)->select('user_id'));
        }

        $live = fn () => DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.status', [SubscriptionStatus::Active->value, SubscriptionStatus::Trialing->value, SubscriptionStatus::PastDue->value])
            ->where(fn ($w) => $w->whereNull('subscriptions.current_period_end')->orWhere('subscriptions.current_period_end', '>', now()));

        if (! empty($f['tiers'])) {
            $tiers = (array) $f['tiers'];
            $levels = array_values(array_filter(array_map(fn ($t) => ['starter' => 1, 'pro' => 2, 'elite' => 3][$t] ?? null, $tiers)));
            $q->where(function ($w) use ($tiers, $levels, $live) {
                if ($levels) {
                    $w->orWhereIn('id', $live()->whereIn('plans.tier_level', $levels)->select('subscriptions.user_id'));
                }
                if (in_array('free', $tiers, true)) {
                    $w->orWhereNotIn('id', $live()->select('subscriptions.user_id'));
                }
            });
        }
        if (! empty($f['status'])) {
            $statuses = (array) $f['status'];
            $q->where(function ($w) use ($statuses) {
                foreach (array_intersect($statuses, ['active', 'past_due', 'cancelled']) as $s) {
                    $w->orWhereIn('id', DB::table('subscriptions')->where('status', $s)->where('gateway', '!=', Gateway::Manual->value)->select('user_id'));
                }
                if (in_array('none', $statuses, true)) {
                    $w->orWhereNotIn('id', DB::table('subscriptions')->select('user_id'));
                }
            });
        }

        return $q;
    }
}

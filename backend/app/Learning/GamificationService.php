<?php

namespace App\Learning;

use App\Models\Badge;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Points (idempotent ledger), daily learning streaks (in the learner's timezone) and badges.
 */
class GamificationService
{
    public const POINTS = [
        'lesson_completed' => 10,
        'quiz_passed' => 20,
        'quiz_perfect' => 10,
        'assignment_submitted' => 15,
        'live_attended' => 15,
        'course_completed' => 100,
    ];

    /** Badge catalogue (seeded by BadgeSeeder). */
    public const BADGES = [
        'first_lesson' => ['First steps', 'Completed your first lesson', 'footprints'],
        'first_course' => ['Course finisher', 'Completed your first course', 'graduation-cap'],
        'quiz_ace' => ['Quiz ace', 'Scored 100% on a quiz', 'target'],
        'streak_3' => ['On a roll', 'Learned 3 days in a row', 'flame'],
        'streak_7' => ['Week warrior', 'Learned 7 days in a row', 'flame-kindling'],
        'points_500' => ['High achiever', 'Earned 500 points', 'trophy'],
    ];

    /** Awards points once per (user, source key). Returns true if newly awarded. */
    public function award(User $user, string $reason, string $sourceKey, ?int $points = null): bool
    {
        $inserted = DB::table('points_ledger')->insertOrIgnore([
            'user_id' => $user->id,
            'points' => $points ?? self::POINTS[$reason],
            'reason' => $reason,
            'source_key' => $sourceKey,
            'created_at' => now(),
        ]) > 0;

        if ($inserted && $this->totalPoints($user) >= 500) {
            $this->grantBadge($user, 'points_500');
        }

        return $inserted;
    }

    public function totalPoints(User $user): int
    {
        return (int) DB::table('points_ledger')->where('user_id', $user->id)->sum('points');
    }

    /** Counts today (user's timezone) as an active learning day. */
    public function recordActivity(User $user, int $seconds = 0): void
    {
        $day = Carbon::now($user->timezone ?: 'UTC')->toDateString();

        DB::table('learning_days')->upsert(
            [['user_id' => $user->id, 'day' => $day, 'seconds' => max(0, $seconds)]],
            ['user_id', 'day'],
            // Qualified: Postgres can't tell the stored row's column from EXCLUDED's otherwise.
            ['seconds' => DB::raw('learning_days.seconds + '.max(0, $seconds))],
        );

        $streak = $this->streak($user)['current'];
        if ($streak >= 3) {
            $this->grantBadge($user, 'streak_3');
        }
        if ($streak >= 7) {
            $this->grantBadge($user, 'streak_7');
        }
    }

    /** @return array{current: int, longest: int, active_today: bool} */
    public function streak(User $user): array
    {
        $tz = $user->timezone ?: 'UTC';
        $days = DB::table('learning_days')->where('user_id', $user->id)->orderByDesc('day')->limit(400)->pluck('day')
            ->map(fn ($d) => Carbon::parse((string) $d, $tz)->startOfDay());

        $today = Carbon::now($tz)->startOfDay();
        $activeToday = $days->contains(fn (Carbon $d) => $d->equalTo($today));

        // Current streak: consecutive days ending today (or yesterday, so it isn't "lost" before you learn today).
        $current = 0;
        $cursor = $activeToday ? $today->copy() : $today->copy()->subDay();
        foreach ($days as $d) {
            if ($d->equalTo($cursor)) {
                $current++;
                $cursor->subDay();
            } elseif ($d->lessThan($cursor)) {
                break;
            }
        }

        $longest = 0;
        $run = 0;
        $prev = null;
        foreach ($days->sort()->values() as $d) {
            $run = $prev && $prev->copy()->addDay()->equalTo($d) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $prev = $d;
        }

        return ['current' => $current, 'longest' => $longest, 'active_today' => $activeToday];
    }

    public function grantBadge(User $user, string $key): void
    {
        $badge = Badge::where('key', $key)->first();
        if ($badge) {
            DB::table('user_badges')->insertOrIgnore(['user_id' => $user->id, 'badge_id' => $badge->id, 'awarded_at' => now()]);
        }
    }

    /** @return list<array{key: string, name: string, description: string, icon: string, awarded_at: string}> */
    public function badges(User $user): array
    {
        return DB::table('user_badges')->join('badges', 'badges.id', '=', 'user_badges.badge_id')
            ->where('user_badges.user_id', $user->id)->orderByDesc('awarded_at')
            ->get(['badges.key', 'badges.name', 'badges.description', 'badges.icon', 'user_badges.awarded_at'])
            ->map(fn ($b) => ['key' => (string) $b->key, 'name' => (string) $b->name, 'description' => (string) $b->description, 'icon' => (string) $b->icon, 'awarded_at' => Carbon::parse((string) $b->awarded_at)->toIso8601String()])
            ->values()->all();
    }
}

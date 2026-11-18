<?php

namespace App\Instructors;

use App\Enums\AttemptStatus;
use App\Enums\LiveSessionStatus;
use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** KPIs and trends for the courses an instructor teaches (owner or co-instructor). */
class InstructorAnalytics
{
    public function __construct(private readonly EarningsService $earnings) {}

    /** @return Builder<Course> */
    public function courses(User $instructor): Builder
    {
        return Course::query()->where(fn ($q) => $q->where('instructor_id', $instructor->id)
            ->orWhereHas('coInstructors', fn ($ci) => $ci->whereKey($instructor->id)));
    }

    /** @return array<string, mixed> */
    public function overview(User $instructor, int $days): array
    {
        $courseIds = $this->courses($instructor)->pluck('id');
        $from = now()->subDays($days - 1)->startOfDay();

        $enrolled = DB::table('enrollments')->whereIn('course_id', $courseIds);
        $completed = DB::table('course_progress')->whereIn('course_id', $courseIds)->whereNotNull('completed_at')->count();
        $enrollments = (clone $enrolled)->count();
        $quiz = DB::table('quiz_attempts')->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')
            ->join('lessons', 'lessons.id', '=', 'quizzes.lesson_id')->whereIn('lessons.course_id', $courseIds)
            ->where('quiz_attempts.status', '!=', AttemptStatus::InProgress->value)->where('quiz_attempts.submitted_at', '>=', $from)
            ->avg('quiz_attempts.percent');
        // Same source as the per-course table (cached on courses), weighted by review count.
        $ratings = DB::table('courses')->whereIn('id', $courseIds)->where('rating_count', '>', 0)
            ->selectRaw('SUM(rating_avg * rating_count) as w, SUM(rating_count) as n')->first();
        $live = DB::table('live_session_registrations')->join('live_sessions', 'live_sessions.id', '=', 'live_session_registrations.live_session_id')
            ->where('live_sessions.instructor_id', $instructor->id)->where('live_sessions.status', LiveSessionStatus::Ended->value)
            ->where('live_sessions.starts_at', '>=', $from)
            ->selectRaw('COUNT(*) as registered, SUM(CASE WHEN attended THEN 1 ELSE 0 END) as attended')->first();

        return [
            'range_days' => $days,
            'kpis' => [
                'students' => (clone $enrolled)->distinct()->count('user_id'),
                'new_enrollments' => (clone $enrolled)->where('started_at', '>=', $from)->count(),
                'completion_rate' => $enrollments ? (int) round($completed * 100 / $enrollments) : null,
                'avg_quiz_score' => $quiz !== null ? (int) round((float) $quiz) : null,
                'watch_minutes' => intdiv((int) DB::table('course_watch_daily')->whereIn('course_id', $courseIds)->where('day', '>=', $from->toDateString())->sum('seconds'), 60),
                'rating_avg' => $ratings && $ratings->n ? round((float) $ratings->w / (float) $ratings->n, 1) : null,
                'rating_count' => (int) ($ratings->n ?? 0),
                'live_attendance_rate' => $live && $live->registered ? (int) round($live->attended * 100 / $live->registered) : null,
                'open_questions' => DB::table('course_questions')->whereIn('course_id', $courseIds)->whereNull('instructor_answered_at')->whereNull('deleted_at')->count(),
                'pending_submissions' => DB::table('assignment_submissions')->join('assignments', 'assignments.id', '=', 'assignment_submissions.assignment_id')
                    ->join('lessons', 'lessons.id', '=', 'assignments.lesson_id')->whereIn('lessons.course_id', $courseIds)
                    ->where('assignment_submissions.status', 'submitted')->count(),
            ],
            'series' => [
                'daily' => $this->daily($courseIds, $from, $days),
                'earnings' => $this->earningsTrend($instructor),
            ],
            'courses' => $this->perCourse($courseIds, $from),
        ];
    }

    /**
     * @param  Collection<int, int>  $courseIds
     * @return list<array{date: string, enrollments: int, watch_minutes: int}>
     */
    private function daily(Collection $courseIds, Carbon $from, int $days): array
    {
        $enroll = DB::table('enrollments')->whereIn('course_id', $courseIds)->where('started_at', '>=', $from)
            ->selectRaw('DATE(started_at) as d, COUNT(*) as n')->groupBy('d')->pluck('n', 'd');
        $watch = DB::table('course_watch_daily')->whereIn('course_id', $courseIds)->where('day', '>=', $from->toDateString())
            ->selectRaw('day as d, SUM(seconds) as s')->groupBy('day')->pluck('s', 'd');

        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $out[] = ['date' => $d, 'enrollments' => (int) ($enroll[$d] ?? 0), 'watch_minutes' => intdiv((int) ($watch[$d] ?? 0), 60)];
        }

        return $out;
    }

    /**
     * Last 6 closed months from statements (or computed if not generated yet) + this month's estimate.
     *
     * @return list<array{period: string, currency: string, amount: int, estimate: bool}>
     */
    public function earningsTrend(User $instructor): array
    {
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $period = now()->utc()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
            $saved = DB::table('payout_statements')->where('instructor_id', $instructor->id)->where('period', $period)->get(['currency', 'total']);
            $rows = $saved->isNotEmpty() ? $saved->map(fn ($s) => ['currency' => $s->currency, 'total' => (int) $s->total])
                : $this->earnings->compute($period, $instructor->id)->map(fn ($r) => ['currency' => $r['currency'], 'total' => $r['total']]);
            foreach ($rows as $r) {
                $out[] = ['period' => $period, 'currency' => (string) $r['currency'], 'amount' => (int) $r['total'], 'estimate' => $saved->isEmpty()];
            }
            if ($rows->isEmpty()) {
                $out[] = ['period' => $period, 'currency' => (string) config('billing.default_currency', 'INR'), 'amount' => 0, 'estimate' => $saved->isEmpty()];
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, int>  $courseIds
     * @return list<array<string, mixed>>
     */
    private function perCourse(Collection $courseIds, Carbon $from): array
    {
        $enroll = DB::table('enrollments')->whereIn('course_id', $courseIds)->groupBy('course_id')->selectRaw('course_id, COUNT(*) as n')->pluck('n', 'course_id');
        $done = DB::table('course_progress')->whereIn('course_id', $courseIds)->whereNotNull('completed_at')->groupBy('course_id')->selectRaw('course_id, COUNT(*) as n')->pluck('n', 'course_id');
        $watch = DB::table('course_watch_daily')->whereIn('course_id', $courseIds)->where('day', '>=', $from->toDateString())->groupBy('course_id')->selectRaw('course_id, SUM(seconds) as s')->pluck('s', 'course_id');
        $quiz = DB::table('quiz_attempts')->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')->join('lessons', 'lessons.id', '=', 'quizzes.lesson_id')
            ->whereIn('lessons.course_id', $courseIds)->where('quiz_attempts.status', '!=', AttemptStatus::InProgress->value)
            ->groupBy('lessons.course_id')->selectRaw('lessons.course_id as c, AVG(quiz_attempts.percent) as p')->pluck('p', 'c');

        return Course::whereIn('id', $courseIds)->orderBy('title')->get(['id', 'uuid', 'title', 'status', 'rating_avg', 'rating_count'])
            ->map(fn (Course $c) => [
                'id' => $c->uuid, 'title' => $c->title, 'status' => $c->status->value,
                'students' => (int) ($enroll[$c->id] ?? 0),
                'completion_rate' => ($enroll[$c->id] ?? 0) ? (int) round(($done[$c->id] ?? 0) * 100 / $enroll[$c->id]) : null,
                'watch_minutes' => intdiv((int) ($watch[$c->id] ?? 0), 60),
                'avg_quiz_score' => isset($quiz[$c->id]) ? (int) round((float) $quiz[$c->id]) : null,
                'rating_avg' => $c->rating_count ? (float) $c->rating_avg : null,
                'rating_count' => (int) $c->rating_count,
            ])->values()->all();
    }

    /**
     * Newest events across the instructor's courses.
     *
     * @return list<array{type: string, at: string, text: string, link: string|null}>
     */
    public function activity(User $instructor, int $limit = 15): array
    {
        $courseIds = $this->courses($instructor)->pluck('id');
        $items = collect();

        DB::table('enrollments')->join('users', 'users.id', '=', 'enrollments.user_id')->join('courses', 'courses.id', '=', 'enrollments.course_id')
            ->whereIn('enrollments.course_id', $courseIds)->latest('enrollments.started_at')->limit($limit)
            ->get(['users.name', 'courses.title', 'enrollments.started_at as at'])
            ->each(fn ($r) => $items->push(['type' => 'enrollment', 'at' => (string) $r->at, 'text' => "{$r->name} started “{$r->title}”", 'link' => '/instructor/students']));
        DB::table('course_reviews')->join('users', 'users.id', '=', 'course_reviews.user_id')->join('courses', 'courses.id', '=', 'course_reviews.course_id')
            ->whereIn('course_reviews.course_id', $courseIds)->latest('course_reviews.created_at')->limit($limit)
            ->get(['users.name', 'courses.title', 'course_reviews.rating', 'course_reviews.created_at as at'])
            ->each(fn ($r) => $items->push(['type' => 'review', 'at' => (string) $r->at, 'text' => "{$r->name} rated “{$r->title}” {$r->rating}★", 'link' => null]));
        DB::table('course_questions')->join('users', 'users.id', '=', 'course_questions.user_id')
            ->whereIn('course_questions.course_id', $courseIds)->whereNull('course_questions.deleted_at')->latest('course_questions.created_at')->limit($limit)
            ->get(['users.name', 'course_questions.title', 'course_questions.created_at as at'])
            ->each(fn ($r) => $items->push(['type' => 'question', 'at' => (string) $r->at, 'text' => "{$r->name} asked “{$r->title}”", 'link' => '/instructor/questions']));
        DB::table('assignment_submissions')->join('users', 'users.id', '=', 'assignment_submissions.user_id')
            ->join('assignments', 'assignments.id', '=', 'assignment_submissions.assignment_id')->join('lessons', 'lessons.id', '=', 'assignments.lesson_id')
            ->whereIn('lessons.course_id', $courseIds)->latest('assignment_submissions.submitted_at')->limit($limit)
            ->get(['users.name', 'lessons.title', 'assignment_submissions.submitted_at as at'])
            ->each(fn ($r) => $items->push(['type' => 'submission', 'at' => (string) $r->at, 'text' => "{$r->name} submitted “{$r->title}”", 'link' => '/instructor/grading']));

        return $items->sortByDesc(fn ($i) => Carbon::parse($i['at'])->getTimestamp())->take($limit)
            ->map(fn ($i) => [...$i, 'at' => Carbon::parse($i['at'])->toIso8601String()])->values()->all();
    }
}

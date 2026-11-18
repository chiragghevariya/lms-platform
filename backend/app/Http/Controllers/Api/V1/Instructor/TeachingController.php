<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Courses\RichText;
use App\Enums\AttemptStatus;
use App\Http\Controllers\Controller;
use App\Instructors\InstructorAnalytics;
use App\Instructors\QuestionService;
use App\Models\Course;
use App\Models\CourseAnnouncement;
use App\Models\CourseQuestion;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\Instructors\CourseAnnouncementNotification;
use App\Services\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

/** Instructor-side views over their own courses: dashboard analytics, activity, students, Q&A inbox, announcements. */
class TeachingController extends Controller
{
    public function __construct(private readonly InstructorAnalytics $analytics) {}

    public function analytics(Request $request): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', Rule::in([7, 30, 90, 365])]]);

        return response()->json(['data' => $this->analytics->overview($this->user($request), (int) ($data['days'] ?? 30))]);
    }

    public function activity(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->analytics->activity($this->user($request))]);
    }

    public function students(Request $request, Course $course, AvatarService $avatars): JsonResponse
    {
        $this->authorize('view', $course);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', Rule::in(['recent', 'progress', 'name'])]]);

        $quiz = DB::table('quiz_attempts')->join('quizzes', 'quizzes.id', '=', 'quiz_attempts.quiz_id')->join('lessons', 'lessons.id', '=', 'quizzes.lesson_id')
            ->where('lessons.course_id', $course->id)->where('quiz_attempts.status', '!=', AttemptStatus::InProgress->value)
            ->groupBy('quiz_attempts.user_id')->selectRaw('quiz_attempts.user_id as u, AVG(quiz_attempts.percent) as p')->pluck('p', 'u');

        $rows = DB::table('enrollments')->where('enrollments.course_id', $course->id)
            ->join('users', 'users.id', '=', 'enrollments.user_id')
            ->leftJoin('course_progress', fn ($j) => $j->on('course_progress.user_id', '=', 'enrollments.user_id')->on('course_progress.course_id', '=', 'enrollments.course_id'))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('users.name', 'like', "%{$s}%")->orWhere('users.email', 'like', "%{$s}%")))
            ->when(($data['sort'] ?? 'recent') === 'progress', fn ($q) => $q->orderByDesc('course_progress.percent'))
            ->when(($data['sort'] ?? 'recent') === 'name', fn ($q) => $q->orderBy('users.name'))
            ->orderByDesc('enrollments.started_at')
            ->select(['users.id as uid', 'users.uuid', 'users.name', 'users.email', 'users.avatar_path', 'enrollments.started_at', 'enrollments.source',
                'course_progress.percent', 'course_progress.completed_lessons', 'course_progress.last_activity_at', 'course_progress.completed_at'])
            ->paginate(50);

        return response()->json([
            'data' => collect($rows->items())->map(fn (\stdClass $r) => [
                'id' => $r->uuid, 'name' => $r->name, 'email' => $r->email, 'avatar_url' => $avatars->url($r->avatar_path),
                'enrolled_at' => $r->started_at ? Carbon::parse((string) $r->started_at)->toIso8601String() : null,
                'source' => $r->source,
                'progress_percent' => (int) ($r->percent ?? 0),
                'completed_lessons' => (int) ($r->completed_lessons ?? 0),
                'last_activity_at' => $r->last_activity_at ? Carbon::parse((string) $r->last_activity_at)->toIso8601String() : null,
                'completed_at' => $r->completed_at ? Carbon::parse((string) $r->completed_at)->toIso8601String() : null,
                'avg_quiz_score' => isset($quiz[$r->uid]) ? (int) round((float) $quiz[$r->uid]) : null,
            ])->values(),
            'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'lessons_count' => $course->lessons_count],
        ]);
    }

    public function questions(Request $request, QuestionService $qa): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['status' => ['nullable', Rule::in(['unanswered', 'answered', 'all'])], 'course' => ['nullable', 'uuid']]);
        $status = $data['status'] ?? 'unanswered';

        $questions = CourseQuestion::with('user', 'lesson', 'course')
            ->whereIn('course_id', $this->analytics->courses($user)->pluck('id'))
            ->when($data['course'] ?? null, fn ($q, $c) => $q->whereHas('course', fn ($cq) => $cq->where('uuid', $c)))
            ->when($status === 'unanswered', fn ($q) => $q->whereNull('instructor_answered_at')->oldest())
            ->when($status === 'answered', fn ($q) => $q->whereNotNull('instructor_answered_at')->latest())
            ->when($status === 'all', fn ($q) => $q->latest())
            ->paginate(30);

        return response()->json([
            'data' => $questions->getCollection()->map(fn (CourseQuestion $q) => $qa->present($q, $user))->values(),
            'meta' => ['total' => $questions->total(), 'current_page' => $questions->currentPage(), 'last_page' => $questions->lastPage()],
        ]);
    }

    public function announcements(Request $request, Course $course): JsonResponse
    {
        $this->authorize('view', $course);

        return response()->json(['data' => CourseAnnouncement::where('course_id', $course->id)->with('author')->latest()->limit(100)->get()
            ->map(fn (CourseAnnouncement $a) => self::announcementPayload($a))->values()]);
    }

    public function announce(Request $request, Course $course): JsonResponse
    {
        $this->authorize('view', $course); // owner, co-instructor or admin — announcements are allowed even while in review
        $data = $request->validate(['title' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:20000']]);
        $body = (string) RichText::clean($data['body']);
        abort_if(trim(strip_tags($body)) === '', 422, 'The announcement is empty.');

        $recipients = User::whereIn('id', Enrollment::where('course_id', $course->id)->select('user_id'))->where('id', '!=', $this->user($request)->id);
        $announcement = CourseAnnouncement::create([
            'course_id' => $course->id, 'user_id' => $this->user($request)->id,
            'title' => $data['title'], 'body' => $body, 'recipients_count' => (clone $recipients)->count(),
        ]);
        $recipients->chunkById(500, fn ($users) => Notification::send($users, new CourseAnnouncementNotification($announcement->load('course', 'author'))));

        return response()->json(['data' => self::announcementPayload($announcement->load('author'))], 201);
    }

    /** @return array<string, mixed> */
    public static function announcementPayload(CourseAnnouncement $a): array
    {
        return [
            'id' => $a->uuid, 'title' => $a->title, 'body' => $a->body, 'recipients_count' => $a->recipients_count,
            'author' => ['name' => $a->author->name], 'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

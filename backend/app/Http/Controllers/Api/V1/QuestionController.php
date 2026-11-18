<?php

namespace App\Http\Controllers\Api\V1;

use App\Courses\CourseAccessService;
use App\Http\Controllers\Api\V1\Instructor\TeachingController;
use App\Http\Controllers\Controller;
use App\Instructors\QuestionService;
use App\Models\Course;
use App\Models\CourseAnnouncement;
use App\Models\CourseAnswer;
use App\Models\CourseQuestion;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Learner side of course Q&A and announcements. Only people with access to the course take part. */
class QuestionController extends Controller
{
    public function __construct(private readonly QuestionService $qa, private readonly CourseAccessService $access) {}

    public function index(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $this->user($request);
        $this->assertAccess($user, $lesson->course);
        $data = $request->validate(['scope' => ['nullable', 'in:lesson,course']]);

        $questions = CourseQuestion::with('user', 'lesson', 'course')->where('course_id', $lesson->course_id)
            ->when(($data['scope'] ?? 'lesson') === 'lesson', fn ($q) => $q->where('lesson_id', $lesson->id))
            ->latest()->limit(100)->get();

        return response()->json(['data' => $questions->map(fn (CourseQuestion $q) => $this->qa->present($q, $user))->values()]);
    }

    public function store(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $this->user($request);
        $this->assertAccess($user, $lesson->course);
        $data = $request->validate(['title' => ['required', 'string', 'min:5', 'max:200'], 'body' => ['required', 'string', 'min:10', 'max:5000']]);
        $q = $this->qa->ask($user, $lesson->course, $lesson, $data['title'], $data['body']);

        return response()->json(['data' => $this->qa->present($q->load('user', 'lesson', 'course'), $user, true)], 201);
    }

    public function show(Request $request, CourseQuestion $question): JsonResponse
    {
        $user = $this->user($request);
        $this->assertAccess($user, $question->course);

        return response()->json(['data' => $this->qa->present($question->load('user', 'lesson', 'course'), $user, true)]);
    }

    public function answer(Request $request, CourseQuestion $question): JsonResponse
    {
        $user = $this->user($request);
        $this->assertAccess($user, $question->course);
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:5000']]);
        $this->qa->answer($user, $question->load('course', 'user', 'lesson'), $data['body']);

        return response()->json(['data' => $this->qa->present($question->fresh(['user', 'lesson', 'course']) ?? $question, $user, true)], 201);
    }

    /** Authors delete their own posts; the course's instructors/admins moderate. */
    public function destroy(Request $request, CourseQuestion $question): Response
    {
        $user = $this->user($request);
        abort_unless($question->user_id === $user->id || $this->access->canManage($user, $question->course), 404);
        $question->delete();

        return response()->noContent();
    }

    public function destroyAnswer(Request $request, CourseAnswer $answer): Response
    {
        $user = $this->user($request);
        $question = $answer->question;
        abort_unless($answer->user_id === $user->id || $this->access->canManage($user, $question->course), 404);
        $answer->delete();
        $question->decrement('answers_count');
        if ($answer->is_instructor && ! $question->answers()->where('is_instructor', true)->exists()) {
            $question->forceFill(['instructor_answered_at' => null])->save();
        }

        return response()->noContent();
    }

    public function announcements(Request $request, string $slug): JsonResponse
    {
        $course = Course::where('slug', $slug)->firstOrFail();
        $this->assertAccess($this->user($request), $course);

        return response()->json(['data' => CourseAnnouncement::where('course_id', $course->id)->with('author')->latest()->limit(50)->get()
            ->map(fn (CourseAnnouncement $a) => TeachingController::announcementPayload($a))->values()]);
    }

    private function assertAccess(User $user, Course $course): void
    {
        abort_unless($this->access->canAccessCourse($user, $course), 404);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

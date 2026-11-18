<?php

namespace App\Http\Controllers\Api\V1;

use App\Courses\CourseAccessService;
use App\Enums\AttemptStatus;
use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Learning\QuizService;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    public function __construct(private readonly QuizService $quizzes, private readonly CourseAccessService $access) {}

    /** Quiz intro: settings + my attempts. */
    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $this->user($request);
        $quiz = $this->quizFor($user, $lesson);
        $attempts = QuizAttempt::where('quiz_id', $quiz->id)->where('user_id', $user->id)->latest('id')->get();
        $open = $attempts->firstWhere('status', AttemptStatus::InProgress);

        return response()->json(['data' => [
            'id' => $quiz->uuid,
            'question_count' => $quiz->questions()->count(),
            'pass_mark' => $quiz->pass_mark,
            'time_limit_seconds' => $quiz->time_limit_seconds,
            'attempts_allowed' => $quiz->attempts_allowed,
            'attempts_used' => $attempts->count(),
            'best_percent' => $attempts->where('status', '!=', AttemptStatus::InProgress)->max('percent'),
            'passed' => $attempts->contains('passed', true),
            'open_attempt_id' => $open?->isExpired() ? null : $open?->uuid,
            'attempts' => $attempts->where('status', '!=', AttemptStatus::InProgress)->map(fn (QuizAttempt $a) => [
                'id' => $a->uuid, 'percent' => $a->percent, 'passed' => $a->passed, 'status' => $a->status->value,
                'submitted_at' => $a->submitted_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function start(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $this->user($request);
        $attempt = $this->quizzes->start($user, $this->quizFor($user, $lesson, write: true));

        return response()->json(['data' => $this->quizzes->present($attempt)], 201);
    }

    public function attempt(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);

        return response()->json(['data' => $this->quizzes->present($attempt)]);
    }

    /** Autosave (answers keyed by question id). */
    public function save(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $this->quizzes->save($attempt, $this->answers($request));

        return response()->json(['data' => ['saved' => true, 'expired' => $attempt->isExpired()]]);
    }

    public function submit(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $this->own($request, $attempt);
        $graded = $this->quizzes->submit($attempt, $this->answers($request));

        return response()->json(['data' => $this->quizzes->present($graded->fresh() ?? $graded)]);
    }

    /** @return array<string, array{option_ids?: list<string>, text?: string|null}> */
    private function answers(Request $request): array
    {
        $data = $request->validate([
            'answers' => ['sometimes', 'array', 'max:200'],
            'answers.*.option_ids' => ['sometimes', 'array', 'max:20'],
            'answers.*.option_ids.*' => ['uuid'],
            'answers.*.text' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        /** @var array<string, array{option_ids?: list<string>, text?: string|null}> */
        return $data['answers'] ?? [];
    }

    private function quizFor(User $user, Lesson $lesson, bool $write = false): Quiz
    {
        $lesson->load('course', 'quiz');
        abort_unless($lesson->type === LessonType::Quiz && $lesson->quiz, 404);
        $state = $this->access->lessonState($user, $lesson, $this->access->enrollmentFor($user, $lesson->course));
        abort_unless($state['unlocked'], $state['reason'] === 'drip_locked' ? 403 : 402, 'You do not have access to this quiz.');
        // Preview lessons can be read by anyone, but only people with course access submit work.
        abort_if($write && ! $this->access->canAccessCourse($user, $lesson->course), 402, 'Join the course to submit this quiz.');
        if ($this->access->canAccessCourse($user, $lesson->course)) {
            $this->access->enroll($user, $lesson->course);
        }

        return $lesson->quiz;
    }

    private function own(Request $request, QuizAttempt $attempt): void
    {
        abort_unless($attempt->user_id === $this->user($request)->id, 404);
        $attempt->load('quiz.lesson.course');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

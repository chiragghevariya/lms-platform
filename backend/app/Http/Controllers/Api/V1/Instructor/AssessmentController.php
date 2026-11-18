<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Courses\RichText;
use App\Enums\LessonType;
use App\Enums\QuestionType;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Api\V1\AssignmentController;
use App\Http\Controllers\Controller;
use App\Learning\AssignmentService;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/** Quiz builder, assignment settings and the grading queue. */
class AssessmentController extends Controller
{
    public function showQuiz(Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson->course);
        abort_unless($lesson->type === LessonType::Quiz, 404);
        $quiz = $lesson->quiz;

        return response()->json(['data' => $quiz ? $this->quizPayload($quiz) : null]);
    }

    /** Replaces the quiz settings and its full question list in one save. */
    public function saveQuiz(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson->course);
        abort_unless($lesson->type === LessonType::Quiz, 404);

        $data = $request->validate([
            'pass_mark' => ['required', 'integer', 'between:0,100'],
            'time_limit_seconds' => ['nullable', 'integer', 'between:30,14400'],
            'attempts_allowed' => ['nullable', 'integer', 'between:1,100'],
            'shuffle' => ['boolean'],
            'show_answers' => ['boolean'],
            'questions' => ['required', 'array', 'min:1', 'max:200'],
            'questions.*.id' => ['nullable', 'uuid'],
            'questions.*.type' => ['required', Rule::enum(QuestionType::class)],
            'questions.*.prompt' => ['required', 'string', 'max:2000'],
            'questions.*.points' => ['required', 'integer', 'between:1,100'],
            'questions.*.explanation' => ['nullable', 'string', 'max:2000'],
            'questions.*.accepted_answers' => ['nullable', 'array', 'max:20'],
            'questions.*.accepted_answers.*' => ['string', 'max:200'],
            'questions.*.options' => ['nullable', 'array', 'max:10'],
            'questions.*.options.*.text' => ['required', 'string', 'max:500'],
            'questions.*.options.*.is_correct' => ['boolean'],
        ]);

        validator($data)->after(function (Validator $v) use ($data) {
            foreach ($data['questions'] as $i => $q) {
                $type = QuestionType::from($q['type']);
                /** @var list<array{text: string, is_correct?: bool}> $options */
                $options = $q['options'] ?? [];
                $optionCount = count($options);
                $correct = count(array_filter($options, fn ($o) => (bool) ($o['is_correct'] ?? false)));
                $msg = match ($type) {
                    QuestionType::ShortText => empty($q['accepted_answers']) ? 'Add at least one accepted answer.' : null,
                    QuestionType::TrueFalse => $optionCount !== 2 || $correct !== 1 ? 'True/false needs exactly two options with one correct.' : null,
                    QuestionType::McqSingle => $optionCount < 2 || $correct !== 1 ? 'Single choice needs 2+ options and exactly one correct.' : null,
                    QuestionType::McqMulti => $optionCount < 2 || $correct < 1 ? 'Multiple choice needs 2+ options and at least one correct.' : null,
                };
                if ($msg) {
                    $v->errors()->add("questions.{$i}", $msg);
                }
            }
        })->validate();

        $quiz = DB::transaction(function () use ($lesson, $data) {
            $quiz = Quiz::updateOrCreate(['lesson_id' => $lesson->id], [
                'pass_mark' => $data['pass_mark'],
                'time_limit_seconds' => $data['time_limit_seconds'] ?? null,
                'attempts_allowed' => $data['attempts_allowed'] ?? null,
                'shuffle' => $data['shuffle'] ?? false,
                'show_answers' => $data['show_answers'] ?? true,
            ]);

            // Update in place (by id) so past attempts keep their questions/answers; never delete answered questions.
            $existing = $quiz->questions()->with('options')->get()->keyBy('uuid');
            $keep = array_values(array_filter(array_column($data['questions'], 'id')));
            // Note: Eloquent\Collection::except() filters by primary key, so filter by uuid explicitly.
            foreach ($existing->reject(fn (QuizQuestion $q) => in_array($q->uuid, $keep, true)) as $gone) {
                if (QuizAnswer::where('question_id', $gone->id)->exists()) {
                    throw ValidationException::withMessages(['questions' => "“{$gone->prompt}” already has learner answers and can't be removed. Edit it instead."]);
                }
                $gone->delete();
            }

            foreach ($data['questions'] as $i => $q) {
                /** @var QuizQuestion $question */
                $question = isset($q['id']) && $existing->has($q['id']) ? $existing[$q['id']] : new QuizQuestion(['quiz_id' => $quiz->id]);
                $question->fill([
                    'type' => $q['type'],
                    'prompt' => $q['prompt'],
                    'points' => $q['points'],
                    'explanation' => $q['explanation'] ?? null,
                    'accepted_answers' => $q['type'] === 'short_text' ? array_values($q['accepted_answers'] ?? []) : null,
                    'sort' => $i,
                ])->save();

                $wanted = $q['type'] === 'short_text' ? [] : array_values($q['options'] ?? []);
                $current = $question->options()->get()->values();
                foreach ($wanted as $j => $o) {
                    $option = $current->get($j) ?? $question->options()->make();
                    $option->fill(['text' => $o['text'], 'is_correct' => (bool) ($o['is_correct'] ?? false), 'sort' => $j])->save();
                }
                $current->slice(count($wanted))->each->delete();
            }

            return $quiz;
        });

        return response()->json(['data' => $this->quizPayload($quiz->fresh() ?? $quiz)]);
    }

    public function saveAssignment(Request $request, Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson->course);
        abort_unless($lesson->type === LessonType::Assignment, 404);

        $data = $request->validate([
            'instructions' => ['nullable', 'string', 'max:50000'],
            'due_days' => ['nullable', 'integer', 'between:1,365'],
            'max_score' => ['required', 'integer', 'between:1,1000'],
            'pass_score' => ['nullable', 'integer', 'min:0', 'lte:max_score'],
            'file_types' => ['nullable', 'array', 'max:15'],
            'file_types.*' => ['string', Rule::in(['pdf', 'doc', 'docx', 'zip', 'png', 'jpg', 'jpeg', 'txt', 'csv', 'xlsx', 'pptx', 'psd', 'fig', 'mp4', 'mp3'])],
            'max_file_mb' => ['nullable', 'integer', 'between:1,200'],
            'allow_text' => ['boolean'],
        ]);
        $data['instructions'] = RichText::clean($data['instructions'] ?? null);

        $assignment = $lesson->assignment()->updateOrCreate(['lesson_id' => $lesson->id], $data);

        return response()->json(['data' => $this->assignmentPayload($assignment)]);
    }

    public function showAssignment(Lesson $lesson): JsonResponse
    {
        $this->authorize('update', $lesson->course);
        abort_unless($lesson->type === LessonType::Assignment, 404);
        $assignment = $lesson->assignment;

        return response()->json(['data' => $assignment ? $this->assignmentPayload($assignment) : null]);
    }

    /** @return array<string, mixed> */
    private function assignmentPayload(Assignment $assignment): array
    {
        return $assignment->only(['instructions', 'due_days', 'max_score', 'pass_score', 'file_types', 'max_file_mb', 'allow_text']);
    }

    /** Submissions for courses I teach (admins: all). */
    public function submissions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['status' => ['nullable', Rule::enum(SubmissionStatus::class)], 'course' => ['nullable', 'uuid']]);

        $subs = AssignmentSubmission::with(['user', 'assignment.lesson.course'])
            ->when(! $user->isStaff(), fn ($q) => $q->whereHas('assignment.lesson.course', fn ($c) => $c
                ->where('instructor_id', $user->id)->orWhereHas('coInstructors', fn ($ci) => $ci->whereKey($user->id))))
            ->when($data['course'] ?? null, fn ($q, $c) => $q->whereHas('assignment.lesson.course', fn ($cq) => $cq->where('uuid', $c)))
            ->when($data['status'] ?? 'submitted', fn ($q, $s) => $q->where('status', $s))
            ->oldest('submitted_at')->paginate(30);

        return response()->json([
            'data' => $subs->getCollection()->map(fn (AssignmentSubmission $s) => [
                ...AssignmentController::submissionPayload($s),
                'learner' => ['name' => $s->user->name, 'email' => $s->user->email],
                'lesson' => ['id' => $s->assignment->lesson->uuid, 'title' => $s->assignment->lesson->title],
                'course' => ['id' => $s->assignment->lesson->course->uuid, 'title' => $s->assignment->lesson->course->title],
                'max_score' => $s->assignment->max_score,
            ])->values(),
            'meta' => ['total' => $subs->total(), 'current_page' => $subs->currentPage(), 'last_page' => $subs->lastPage()],
        ]);
    }

    public function grade(Request $request, AssignmentSubmission $submission, AssignmentService $assignments): JsonResponse
    {
        $submission->load('assignment.lesson.course', 'user');
        /** @var Course $course */
        $course = $submission->assignment->lesson->course;
        $this->authorize('submit', $course); // owner / co-instructor / admin

        $data = $request->validate([
            'status' => ['required', Rule::in([SubmissionStatus::Graded->value, SubmissionStatus::Returned->value])],
            'score' => ['nullable', 'integer', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:10000'],
        ]);
        /** @var User $grader */
        $grader = $request->user();
        $graded = $assignments->grade($submission, $grader, $data['score'] ?? null, $data['feedback'] ?? null, SubmissionStatus::from($data['status']));

        return response()->json(['data' => AssignmentController::submissionPayload($graded)]);
    }

    /** @return array<string, mixed> */
    private function quizPayload(Quiz $quiz): array
    {
        return [
            'id' => $quiz->uuid,
            'pass_mark' => $quiz->pass_mark,
            'time_limit_seconds' => $quiz->time_limit_seconds,
            'attempts_allowed' => $quiz->attempts_allowed,
            'shuffle' => $quiz->shuffle,
            'show_answers' => $quiz->show_answers,
            'questions' => $quiz->questions()->with('options')->get()->map(fn (QuizQuestion $q) => [
                'id' => $q->uuid,
                'has_answers' => QuizAnswer::where('question_id', $q->id)->exists(),
                'type' => $q->type->value,
                'prompt' => $q->prompt,
                'points' => $q->points,
                'explanation' => $q->explanation,
                'accepted_answers' => $q->accepted_answers,
                'options' => $q->options->map(fn ($o) => ['text' => $o->text, 'is_correct' => $o->is_correct])->values(),
            ])->values(),
        ];
    }
}

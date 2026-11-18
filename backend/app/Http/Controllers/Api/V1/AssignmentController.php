<?php

namespace App\Http\Controllers\Api\V1;

use App\Courses\CourseAccessService;
use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Learning\AssignmentService;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Rules\CleanUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssignmentController extends Controller
{
    public function __construct(private readonly AssignmentService $assignments, private readonly CourseAccessService $access) {}

    public function show(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $this->user($request);
        $assignment = $this->assignmentFor($user, $lesson);
        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)->where('user_id', $user->id)->first();

        return response()->json(['data' => [
            'id' => $assignment->uuid,
            'instructions' => $assignment->instructions,
            'due_at' => $this->assignments->dueAt($user, $assignment)?->toIso8601String(),
            'max_score' => $assignment->max_score,
            'pass_score' => $assignment->pass_score,
            'file_types' => $assignment->file_types ?? [],
            'max_file_mb' => $assignment->max_file_mb,
            'allow_text' => $assignment->allow_text,
            'submission' => $submission ? self::submissionPayload($submission) : null,
        ]]);
    }

    public function submit(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $this->user($request);
        $assignment = $this->assignmentFor($user, $lesson, write: true);
        $types = $assignment->file_types ?? [];

        $data = $request->validate([
            'text' => [$assignment->allow_text ? 'nullable' : 'prohibited', 'string', 'max:20000'],
            'files' => [$types ? 'nullable' : 'prohibited', 'array', 'max:5'],
            'files.*' => ['file', 'max:'.($assignment->max_file_mb * 1024), 'extensions:'.implode(',', $types ?: ['none']), new CleanUpload],
        ]);
        $submission = $this->assignments->submit($user, $assignment, $data['text'] ?? null, $request->file('files', []));

        return response()->json(['data' => self::submissionPayload($submission)], 201);
    }

    /** Download a submitted file (the learner, or the course's instructors/staff). */
    public function file(Request $request, AssignmentSubmission $submission, int $index): StreamedResponse
    {
        $user = $this->user($request);
        $submission->load('assignment.lesson.course');
        abort_unless($submission->user_id === $user->id || $this->access->canManage($user, $submission->assignment->lesson->course), 404);
        $file = ($submission->files ?? [])[$index] ?? abort(404);

        return Storage::disk((string) config('video.resources_disk'))->download($file['path'], $file['name']);
    }

    /** @return array<string, mixed> */
    public static function submissionPayload(AssignmentSubmission $s): array
    {
        return [
            'id' => $s->uuid,
            'status' => $s->status->value,
            'text' => $s->text,
            'files' => collect($s->files ?? [])->map(fn ($f, $i) => ['index' => $i, 'name' => $f['name'], 'size' => $f['size']])->values(),
            'score' => $s->score,
            'feedback' => $s->feedback,
            'graded_at' => $s->graded_at?->toIso8601String(),
            'submitted_at' => $s->submitted_at->toIso8601String(),
            'revision' => $s->revision,
        ];
    }

    private function assignmentFor(User $user, Lesson $lesson, bool $write = false): Assignment
    {
        $lesson->load('course', 'assignment');
        abort_unless($lesson->type === LessonType::Assignment && $lesson->assignment, 404);
        $state = $this->access->lessonState($user, $lesson, $this->access->enrollmentFor($user, $lesson->course));
        abort_unless($state['unlocked'], $state['reason'] === 'drip_locked' ? 403 : 402, 'You do not have access to this assignment.');
        // Preview lessons can be read by anyone, but only people with course access submit work.
        abort_if($write && ! $this->access->canAccessCourse($user, $lesson->course), 402, 'Join the course to submit this assignment.');
        if ($this->access->canAccessCourse($user, $lesson->course)) {
            $this->access->enroll($user, $lesson->course);
        }

        return $lesson->assignment;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

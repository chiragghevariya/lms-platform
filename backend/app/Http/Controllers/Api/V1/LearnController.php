<?php

namespace App\Http\Controllers\Api\V1;

use App\Courses\CourseAccessService;
use App\Enums\LessonType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseCardResource;
use App\Learning\ProgressService;
use App\Models\Bookmark;
use App\Models\Course;
use App\Models\CourseProgress;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonNote;
use App\Models\LessonProgress;
use App\Models\LessonResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The lesson player's data: lesson content, progress/resume, notes, resources. */
class LearnController extends Controller
{
    public function __construct(private readonly CourseAccessService $access, private readonly ProgressService $progress) {}

    /** "Continue learning" list for the dashboard. */
    public function enrollments(Request $request): JsonResponse
    {
        $user = $this->user($request);
        // A course is "mine" once I'm enrolled or have any progress in it.
        $progress = CourseProgress::where('user_id', $user->id)->with('lastLesson')->get()->keyBy('course_id');
        $enrollments = Enrollment::where('user_id', $user->id)->get()->keyBy('course_id');
        $courses = Course::whereIn('id', $progress->keys()->merge($enrollments->keys())->unique())->with('instructor', 'category')->get();

        return response()->json(['data' => $courses->map(function (Course $course) use ($progress, $enrollments) {
            /** @var CourseProgress|null $p */
            $p = $progress->get($course->id);
            $lastActive = $p->last_activity_at ?? $enrollments->get($course->id)?->last_accessed_at;

            return [
                'course' => new CourseCardResource($course),
                'progress_percent' => $p->percent ?? 0,
                'completed_lessons' => $p->completed_lessons ?? 0,
                'total_lessons' => $p->total_lessons ?? $course->lessons_count,
                'last_lesson_id' => $p?->lastLesson?->uuid,
                'last_accessed_at' => $lastActive?->toIso8601String(),
                'completed_at' => $p?->completed_at?->toIso8601String(),
            ];
        })->sortByDesc('last_accessed_at')->values()]);
    }

    public function lesson(Request $request, Lesson $lesson): JsonResponse
    {
        $user = $this->user($request);
        $lesson->load('course.sections.lessons', 'video', 'resources');
        $course = $lesson->course;
        $enrollment = $this->access->enrollmentFor($user, $course);
        $state = $this->access->lessonState($user, $lesson, $enrollment);

        if (! $state['unlocked']) {
            return response()->json([
                'message' => $state['reason'] === 'drip_locked' ? 'This lesson is not available yet.' : 'You do not have access to this lesson.',
                'code' => $state['reason'],
                'required_tier' => $state['required_tier']?->value,
                'available_at' => $state['available_at']?->toIso8601String(),
            ], $state['reason'] === 'drip_locked' ? 403 : 402);
        }

        if ($this->access->canAccessCourse($user, $course)) {
            $enrollment = $this->access->enroll($user, $course);
            $enrollment->forceFill(['last_accessed_at' => now()])->save();
            $this->progress->touchCourse($user, $lesson);
        }

        $ordered = $course->sections->flatMap->lessons->values();
        $index = $ordered->search(fn (Lesson $l) => $l->id === $lesson->id);
        $progress = LessonProgress::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();

        return response()->json(['data' => [
            'id' => $lesson->uuid,
            'title' => $lesson->title,
            'type' => $lesson->type->value,
            'content' => $lesson->content,
            'duration_seconds' => $lesson->duration_seconds,
            'is_preview' => $lesson->is_preview,
            'section' => $lesson->section->title ?? null,
            'video' => $lesson->video ? ['status' => $lesson->video->status->value] : null,
            'has_attachment' => $lesson->attachment_path !== null,
            'resources' => $lesson->resources->map(fn (LessonResource $r) => ['id' => $r->uuid, 'title' => $r->title, 'size' => $r->size, 'mime' => $r->mime])->values(),
            'progress' => [
                'position_seconds' => $progress->position_seconds ?? 0,
                'watched_seconds' => $progress->watched_seconds ?? 0,
                'completed' => $progress?->completed_at !== null,
                'heartbeat_seconds' => ProgressService::HEARTBEAT_SECONDS,
                'can_mark_complete' => ! in_array($lesson->type, [LessonType::Video, LessonType::Quiz, LessonType::Assignment], true),
            ],
            'bookmarked' => Bookmark::where('user_id', $user->id)->where('lesson_id', $lesson->id)->exists(),
            'notes' => LessonNote::where('user_id', $user->id)->where('lesson_id', $lesson->id)->orderBy('position_seconds')->orderBy('id')->get()
                ->map(fn (LessonNote $n) => ['id' => $n->uuid, 'body' => $n->body, 'position_seconds' => $n->position_seconds, 'created_at' => $n->created_at?->toIso8601String()]),
            'prev_id' => $index > 0 ? $ordered[$index - 1]->uuid : null,
            'next_id' => $index !== false && $index < $ordered->count() - 1 ? $ordered[$index + 1]->uuid : null,
            'course' => ['id' => $course->uuid, 'slug' => $course->slug, 'title' => $course->title],
        ]]);
    }

    /** Resume position (seek/pause) and, for non-video lessons, manual completion. Never adds watch time. */
    public function progress(Request $request, Lesson $lesson): JsonResponse
    {
        $data = $request->validate([
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'completed' => ['nullable', 'boolean'],
        ]);
        $user = $this->user($request);
        $lesson->load('course');
        $this->assertUnlocked($user, $lesson);
        $this->ensureEnrolled($user, $lesson);

        if (isset($data['position_seconds'])) {
            $this->progress->savePosition($user, $lesson, $data['position_seconds']);
        }
        if (array_key_exists('completed', $data) && $data['completed'] !== null) {
            $this->progress->markManually($user, $lesson, (bool) $data['completed']);
        }

        $p = LessonProgress::where('user_id', $user->id)->where('lesson_id', $lesson->id)->first();

        return response()->json(['data' => [
            'position_seconds' => $p->position_seconds ?? 0,
            'watched_seconds' => $p->watched_seconds ?? 0,
            'completed' => $p?->completed_at !== null,
        ]]);
    }

    /** Player heartbeat (every 15 s while playing): accrues server-capped watch time, completes at 90%. */
    public function heartbeat(Request $request, Lesson $lesson): JsonResponse
    {
        $data = $request->validate([
            'position_seconds' => ['required', 'integer', 'min:0', 'max:86400'],
            'watched_seconds' => ['required', 'integer', 'min:0', 'max:120'],
            'playback_rate' => ['nullable', 'numeric', 'between:0.25,2'],
        ]);
        $user = $this->user($request);
        $lesson->load('course');
        $this->assertUnlocked($user, $lesson);
        $this->ensureEnrolled($user, $lesson);

        return response()->json(['data' => $this->progress->heartbeat(
            $user, $lesson, $data['position_seconds'], $data['watched_seconds'], (float) ($data['playback_rate'] ?? 1),
        )]);
    }

    public function storeNote(Request $request, Lesson $lesson): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
        ]);
        $user = $this->user($request);
        $lesson->load('course');
        $this->assertUnlocked($user, $lesson);

        $note = LessonNote::create(['user_id' => $user->id, 'lesson_id' => $lesson->id, ...$data]);

        return response()->json(['data' => ['id' => $note->uuid, 'body' => $note->body, 'position_seconds' => $note->position_seconds, 'created_at' => $note->created_at?->toIso8601String()]], 201);
    }

    public function destroyNote(Request $request, LessonNote $note): Response
    {
        abort_unless($note->user_id === $this->user($request)->id, 404);
        $note->delete();

        return response()->noContent();
    }

    public function resource(Request $request, LessonResource $resource): StreamedResponse
    {
        $resource->load('lesson.course');
        $this->assertUnlocked($this->user($request), $resource->lesson);

        return Storage::disk($resource->disk)->download($resource->path, $this->filename($resource->title, $resource->path));
    }

    /** PDF lesson body, streamed inline. */
    public function attachment(Request $request, Lesson $lesson): StreamedResponse
    {
        $lesson->load('course');
        $this->assertUnlocked($this->user($request), $lesson);
        abort_if($lesson->attachment_path === null, 404);

        return Storage::disk((string) config('video.resources_disk'))->response($lesson->attachment_path, null, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function assertUnlocked(User $user, Lesson $lesson): void
    {
        $state = $this->access->lessonState($user, $lesson, $this->access->enrollmentFor($user, $lesson->course));
        abort_unless($state['unlocked'], $state['reason'] === 'drip_locked' ? 403 : 402, 'You do not have access to this lesson.');
    }

    private function ensureEnrolled(User $user, Lesson $lesson): void
    {
        if ($this->access->canAccessCourse($user, $lesson->course)) {
            $this->access->enroll($user, $lesson->course);
        }
    }

    private function filename(string $title, string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        return trim(preg_replace('/[^\w\- ]+/', '', $title) ?: 'resource').($ext ? '.'.$ext : '');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

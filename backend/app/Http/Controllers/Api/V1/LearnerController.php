<?php

namespace App\Http\Controllers\Api\V1;

use App\Courses\CourseAccessService;
use App\Enums\CourseStatus;
use App\Enums\LessonType;
use App\Enums\SubmissionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CourseCardResource;
use App\Learning\AssignmentService;
use App\Learning\CertificateService;
use App\Learning\GamificationService;
use App\Models\AssignmentSubmission;
use App\Models\Badge;
use App\Models\Bookmark;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseProgress;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonNote;
use App\Models\LiveSession;
use App\Models\User;
use App\Models\UserProfile;
use App\Services\AvatarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The learner's personal area: dashboard, bookmarks, notes, certificates, achievements, profile. */
class LearnerController extends Controller
{
    public function __construct(
        private readonly GamificationService $gamification,
        private readonly CertificateService $certificates,
        private readonly CourseAccessService $access,
    ) {}

    public function dashboard(Request $request, AssignmentService $assignments): JsonResponse
    {
        $user = $this->user($request);

        $continue = CourseProgress::where('user_id', $user->id)->whereNull('completed_at')
            ->with('course.instructor', 'course.category', 'lastLesson')->orderByDesc('last_activity_at')->limit(4)->get()
            ->filter(fn (CourseProgress $p) => $p->course !== null && $p->course->isPublished())
            ->map(fn (CourseProgress $p) => [
                'course' => new CourseCardResource($p->course),
                'percent' => $p->percent,
                'completed_lessons' => $p->completed_lessons,
                'total_lessons' => $p->total_lessons,
                'next_lesson_id' => $p->lastLesson?->uuid,
                'last_activity_at' => $p->last_activity_at?->toIso8601String(),
            ])->values();

        // Pending assignments: assignment lessons in my enrolled courses without a graded submission.
        $courseIds = Enrollment::where('user_id', $user->id)->pluck('course_id');
        $pending = Lesson::whereIn('course_id', $courseIds)->where('type', LessonType::Assignment)
            ->whereHas('assignment')->with('assignment', 'course')->get()
            ->map(function (Lesson $l) use ($user, $assignments) {
                $sub = AssignmentSubmission::where('assignment_id', $l->assignment?->id)->where('user_id', $user->id)->first();
                if ($sub && $sub->status !== SubmissionStatus::Returned) {
                    return null;
                }

                return [
                    'lesson_id' => $l->uuid,
                    'title' => $l->title,
                    'course' => ['slug' => $l->course->slug, 'title' => $l->course->title],
                    'status' => $sub ? 'returned' : 'not_started',
                    'due_at' => $l->assignment ? $assignments->dueAt($user, $l->assignment)?->toIso8601String() : null,
                ];
            })->filter()->sortBy(fn ($a) => $a['due_at'] ?? '9999')->values()->take(5);

        // Recommended: published courses within my tier I haven't started, most popular first.
        $started = CourseProgress::where('user_id', $user->id)->pluck('course_id')->merge($courseIds)->unique();
        $recommended = Course::published()->whereNotIn('id', $started)->with('instructor', 'category')
            ->orderByDesc('is_featured')->orderByDesc('enrollments_count')->limit(20)->get()
            ->filter(fn (Course $c) => $this->access->canAccessCourse($user, $c))->take(4)
            ->map(fn (Course $c) => new CourseCardResource($c))->values();

        return response()->json(['data' => [
            'continue_learning' => $continue,
            'pending_assignments' => $pending,
            // Sessions I registered for that haven't finished, soonest first.
            'upcoming_live_classes' => LiveSession::upcoming()->whereHas('registrations', fn ($q) => $q->where('user_id', $user->id))
                ->orderBy('starts_at')->limit(3)->get()
                ->map(fn (LiveSession $s) => ['id' => $s->uuid, 'title' => $s->title, 'starts_at' => $s->starts_at->toIso8601String(), 'joinable' => $s->isJoinable()])->values(),
            'stats' => [
                'points' => $this->gamification->totalPoints($user),
                'streak' => $this->gamification->streak($user),
                'courses_completed' => CourseProgress::where('user_id', $user->id)->whereNotNull('completed_at')->count(),
                'certificates' => Certificate::where('user_id', $user->id)->count(),
            ],
            'recent_badges' => array_slice($this->gamification->badges($user), 0, 3),
            'recommended' => $recommended,
        ]]);
    }

    public function achievements(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $earned = collect($this->gamification->badges($user))->keyBy('key');

        return response()->json(['data' => [
            'points' => $this->gamification->totalPoints($user),
            'streak' => $this->gamification->streak($user),
            'badges' => Badge::orderBy('id')->get()->map(fn (Badge $b) => [
                'key' => $b->key, 'name' => $b->name, 'description' => $b->description, 'icon' => $b->icon,
                'earned' => $earned->has($b->key), 'awarded_at' => $earned[$b->key]['awarded_at'] ?? null,
            ])->values(),
            'recent_points' => DB::table('points_ledger')->where('user_id', $user->id)->orderByDesc('id')->limit(10)
                ->get(['points', 'reason', 'created_at']),
        ]]);
    }

    // ---- Bookmarks -----------------------------------------------------

    public function bookmarks(Request $request): JsonResponse
    {
        $items = Bookmark::where('user_id', $this->user($request)->id)->with('course', 'lesson')->latest('id')->get()
            ->filter(fn (Bookmark $b) => $b->course !== null)
            ->map(fn (Bookmark $b) => [
                'id' => $b->uuid,
                'course' => ['id' => $b->course->uuid, 'slug' => $b->course->slug, 'title' => $b->course->title, 'thumbnail_url' => $b->course->thumbnailUrl()],
                'lesson' => $b->lesson ? ['id' => $b->lesson->uuid, 'title' => $b->lesson->title, 'type' => $b->lesson->type->value] : null,
                'position_seconds' => $b->position_seconds,
                'label' => $b->label,
                'created_at' => $b->created_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $items]);
    }

    /** Bookmark a course (course_id) or a lesson (lesson_id, optional position). Idempotent. */
    public function storeBookmark(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id' => ['required_without:lesson_id', 'nullable', 'uuid', 'exists:courses,uuid'],
            'lesson_id' => ['required_without:course_id', 'nullable', 'uuid', 'exists:lessons,uuid'],
            'position_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'label' => ['nullable', 'string', 'max:160'],
        ]);
        $user = $this->user($request);
        $lesson = isset($data['lesson_id']) ? Lesson::where('uuid', $data['lesson_id'])->with('course')->firstOrFail() : null;
        $course = $lesson->course ?? Course::where('uuid', $data['course_id'])->firstOrFail();
        abort_unless($course->status === CourseStatus::Published || $this->access->canManage($user, $course), 404);
        if ($lesson) {
            // Courses can be wish-listed while locked; lessons only once you can open them.
            $state = $this->access->lessonState($user, $lesson);
            abort_unless($state['unlocked'], $state['reason'] === 'drip_locked' ? 403 : 402, 'You do not have access to this lesson.');
        }

        $bookmark = Bookmark::updateOrCreate(
            ['user_id' => $user->id, 'course_id' => $course->id, 'lesson_id' => $lesson?->id],
            ['position_seconds' => $data['position_seconds'] ?? null, 'label' => $data['label'] ?? null],
        );

        return response()->json(['data' => ['id' => $bookmark->uuid]], $bookmark->wasRecentlyCreated ? 201 : 200);
    }

    public function destroyBookmark(Request $request, Bookmark $bookmark): Response
    {
        abort_unless($bookmark->user_id === $this->user($request)->id, 404);
        $bookmark->delete();

        return response()->noContent();
    }

    /** Remove by target (used by toggle buttons). */
    public function unbookmark(Request $request): Response
    {
        $data = $request->validate(['course_id' => ['nullable', 'uuid'], 'lesson_id' => ['nullable', 'uuid']]);
        $q = Bookmark::where('user_id', $this->user($request)->id);
        if (! empty($data['lesson_id'])) {
            $q->whereHas('lesson', fn ($l) => $l->where('uuid', $data['lesson_id']));
        } elseif (! empty($data['course_id'])) {
            $q->whereNull('lesson_id')->whereHas('course', fn ($c) => $c->where('uuid', $data['course_id']));
        } else {
            abort(422, 'course_id or lesson_id is required.');
        }
        $q->delete();

        return response()->noContent();
    }

    // ---- Notes (all courses) -------------------------------------------

    public function notes(Request $request): JsonResponse
    {
        $data = $request->validate(['course' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100']]);
        $notes = LessonNote::where('user_id', $this->user($request)->id)
            ->join('lessons', 'lessons.id', '=', 'lesson_notes.lesson_id')
            ->join('courses', 'courses.id', '=', 'lessons.course_id')
            ->when($data['course'] ?? null, fn ($q, $c) => $q->where('courses.uuid', $c))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where('lesson_notes.body', 'like', "%{$s}%"))
            ->orderByDesc('lesson_notes.id')->limit(500)
            ->get(['lesson_notes.uuid', 'lesson_notes.body', 'lesson_notes.position_seconds', 'lesson_notes.created_at',
                'lessons.uuid as lesson_uuid', 'lessons.title as lesson_title', 'courses.slug as course_slug', 'courses.title as course_title', 'courses.uuid as course_uuid']);

        return response()->json(['data' => $notes->map(fn ($n) => [
            'id' => $n->uuid, 'body' => $n->body, 'position_seconds' => $n->position_seconds, 'created_at' => $n->created_at?->toIso8601String(),
            'lesson' => ['id' => $n->getAttribute('lesson_uuid'), 'title' => $n->getAttribute('lesson_title')],
            'course' => ['id' => $n->getAttribute('course_uuid'), 'slug' => $n->getAttribute('course_slug'), 'title' => $n->getAttribute('course_title')],
        ])->values()]);
    }

    // ---- Certificates ----------------------------------------------------

    public function certificates(Request $request): JsonResponse
    {
        $certs = Certificate::where('user_id', $this->user($request)->id)->whereNull('revoked_at')->with('course')->latest('issued_at')->get();

        return response()->json(['data' => $certs->map(fn (Certificate $c) => $this->certificatePayload($c, true))->values()]);
    }

    public function certificatePdf(Request $request, Certificate $certificate): StreamedResponse
    {
        abort_unless($certificate->user_id === $this->user($request)->id, 404);
        $disk = Storage::disk((string) config('billing.invoices.disk'));
        if (! $certificate->pdf_path || ! $disk->exists($certificate->pdf_path)) {
            $this->certificates->renderPdf($certificate);
        }

        return $disk->download((string) $certificate->pdf_path, 'certificate-'.$certificate->verification_code.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** Public verification by code (no auth). */
    public function verify(string $code): JsonResponse
    {
        $cert = Certificate::where('verification_code', strtoupper(trim($code)))->with('course')->first();
        if (! $cert) {
            return response()->json(['data' => ['valid' => false]], 404);
        }

        return response()->json(['data' => ['valid' => $cert->revoked_at === null] + $this->certificatePayload($cert, false)]);
    }

    // ---- Learning profile ------------------------------------------------

    public function learningProfile(Request $request): JsonResponse
    {
        return $this->learningProfilePayload($this->user($request)->load('profile')->profileOrNew());
    }

    private function learningProfilePayload(UserProfile $profile): JsonResponse
    {
        return response()->json(['data' => [
            'skills' => $profile->skills ?? [],
            'learning_goals' => $profile->learning_goals,
            'is_public' => $profile->is_public,
            'public_handle' => $profile->public_handle,
        ]]);
    }

    public function updateLearningProfile(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $profile = $user->load('profile')->profileOrNew();
        $data = $request->validate([
            'skills' => ['sometimes', 'array', 'max:30'],
            'skills.*' => ['string', 'max:40'],
            'learning_goals' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_public' => ['sometimes', 'boolean'],
            'public_handle' => ['sometimes', 'nullable', 'string', 'min:3', 'max:40', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('user_profiles', 'public_handle')->ignore($profile->id)],
        ]);
        if (($data['is_public'] ?? $profile->is_public) && empty($data['public_handle'] ?? $profile->public_handle)) {
            throw ValidationException::withMessages(['public_handle' => 'Choose a public handle to make your profile public.']);
        }
        $profile->fill($data)->save();

        return $this->learningProfilePayload($profile);
    }

    /** Public learner profile (only when the learner opted in). */
    public function publicProfile(string $handle): JsonResponse
    {
        $profile = UserProfile::where('public_handle', strtolower($handle))->where('is_public', true)->with('user')->firstOrFail();
        $user = $profile->user;

        return response()->json(['data' => [
            'name' => $user->name,
            'avatar_url' => app(AvatarService::class)->url($user->avatar_path),
            'headline' => $profile->headline,
            'bio' => $user->bio,
            'skills' => $profile->skills ?? [],
            'learning_goals' => $profile->learning_goals,
            'points' => $this->gamification->totalPoints($user),
            'badges' => $this->gamification->badges($user),
            'certificates' => Certificate::where('user_id', $user->id)->whereNull('revoked_at')->latest('issued_at')->get()
                ->map(fn (Certificate $c) => $this->certificatePayload($c, false))->values(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function certificatePayload(Certificate $c, bool $owner): array
    {
        return array_filter([
            'id' => $owner ? $c->uuid : null,
            'verification_code' => $c->verification_code,
            'holder_name' => $c->holder_name,
            'course_title' => $c->course_title,
            'course_slug' => $c->course->slug,
            'instructor_name' => $c->instructor_name,
            'issuer' => config('lms.brand.name'),
            'issued_at' => $c->issued_at->toIso8601String(),
            'verify_url' => $c->verificationUrl(),
            'linkedin_url' => $owner ? $this->certificates->linkedInUrl($c) : null,
        ], fn ($v) => $v !== null);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Instructor;

use App\Enums\LiveProviderType;
use App\Enums\LiveSessionStatus;
use App\Enums\LiveSessionType;
use App\Enums\VideoStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SyncLiveAttendance;
use App\Live\LivePresenter;
use App\Live\LiveProviderException;
use App\Live\LiveProviders;
use App\Live\LiveRecordingService;
use App\Live\LiveScheduler;
use App\Models\Course;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Instructor scheduling: one-off or weekly series, edit, cancel, start as host, attendance, replays. */
class LiveSessionController extends Controller
{
    public function __construct(
        private readonly LiveScheduler $scheduler,
        private readonly LivePresenter $presenter,
        private readonly LiveProviders $providers,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $courses = Course::query()
            ->when(! $user->can('live.manage-any'), fn ($q) => $q->where(fn ($w) => $w->where('instructor_id', $user->id)
                ->orWhereHas('coInstructors', fn ($ci) => $ci->whereKey($user->id))))
            ->orderBy('title')->get(['id', 'uuid', 'title']);

        return response()->json(['data' => [
            'providers' => $this->providers->available(),
            'types' => array_map(fn (LiveSessionType $t) => ['value' => $t->value, 'label' => $t->label()], LiveSessionType::cases()),
            'courses' => $courses->map(fn (Course $c) => ['id' => $c->uuid, 'title' => $c->title])->values(),
            'sdk_embedded' => filled(config('live.zoom.sdk_key')),
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $past = $request->query('scope') === 'past';
        $sessions = LiveSession::with('instructor', 'course', 'recordingVideo', 'replayLesson')->withCount('registrations')
            ->when(! ($user->can('live.manage-any') && $request->boolean('all')), fn ($q) => $q->where('instructor_id', $user->id))
            ->when($past, fn ($q) => $q->whereIn('status', [LiveSessionStatus::Ended, LiveSessionStatus::Cancelled])->orderByDesc('starts_at'),
                fn ($q) => $q->whereIn('status', [LiveSessionStatus::Scheduled, LiveSessionStatus::Live])->orderBy('starts_at'))
            ->paginate(30);

        return response()->json([
            'data' => $sessions->getCollection()->map(fn (LiveSession $s) => [
                ...$this->presenter->present($s, $user, null, false),
                'attended_count' => $s->status === LiveSessionStatus::Ended ? LiveRegistration::where('live_session_id', $s->id)->where('attended', true)->count() : null,
                'attendance_synced_at' => $s->attendance_synced_at?->toIso8601String(),
                'recording_status' => $s->recordingVideo?->status->value,
            ])->values(),
            'meta' => ['total' => $sessions->total(), 'current_page' => $sessions->currentPage(), 'last_page' => $sessions->lastPage()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('live.manage-own') || $request->user()?->can('live.manage-any'), 403);
        $data = $this->validated($request, creating: true);

        try {
            $sessions = $this->scheduler->schedule($this->user($request), $data);
        } catch (LiveProviderException $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        return response()->json([
            'data' => $sessions->map(fn (LiveSession $s) => $this->presenter->present($s->load('instructor', 'course'), $this->user($request), null, false))->values(),
        ], 201);
    }

    public function update(Request $request, LiveSession $session): JsonResponse
    {
        $this->authorizeManage($request, $session);
        $data = $this->validated($request, creating: false);

        try {
            $this->scheduler->update($session, $data);
        } catch (LiveProviderException $e) {
            throw ValidationException::withMessages(['provider' => $e->getMessage()]);
        }

        return response()->json(['data' => $this->presenter->present($session->load('instructor', 'course'), $this->user($request), null, false)]);
    }

    public function cancel(Request $request, LiveSession $session): JsonResponse
    {
        $this->authorizeManage($request, $session);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500'], 'following' => ['boolean']]);
        $count = $this->scheduler->cancel($session, $data['reason'] ?? null, (bool) ($data['following'] ?? false));

        return response()->json(['data' => ['cancelled' => $count]]);
    }

    /** Fresh host link (Zoom start URLs expire), so instructors start from the platform. */
    public function start(Request $request, LiveSession $session): JsonResponse
    {
        $this->authorizeManage($request, $session);
        abort_if(in_array($session->status, [LiveSessionStatus::Cancelled, LiveSessionStatus::Ended], true), 409, 'This session is over.');

        try {
            $url = $this->providers->for($session->provider)->hostUrl($session);
        } catch (LiveProviderException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json(['data' => ['url' => $url]])->header('Cache-Control', 'no-store');
    }

    public function registrations(Request $request, LiveSession $session): JsonResponse
    {
        $this->authorizeManage($request, $session);

        return response()->json(['data' => $session->registrations()->with('user')->orderBy('created_at')->get()->map(fn (LiveRegistration $r) => [
            'id' => $r->uuid,
            'name' => $r->user->name,
            'email' => $r->user->email,
            'registered_at' => $r->created_at?->toIso8601String(),
            'attended' => $r->attended,
            'joined_at' => $r->joined_at?->toIso8601String(),
            'left_at' => $r->left_at?->toIso8601String(),
            'attended_minutes' => intdiv($r->attended_seconds, 60),
        ])->values(), 'meta' => ['attendance_synced_at' => $session->attendance_synced_at?->toIso8601String()]]);
    }

    public function syncAttendance(Request $request, LiveSession $session): JsonResponse
    {
        $this->authorizeManage($request, $session);
        abort_unless($session->provider === LiveProviderType::Zoom && $session->status === LiveSessionStatus::Ended, 409, 'Attendance sync is available for ended Zoom sessions.');
        SyncLiveAttendance::dispatch($session->id);

        return response()->json(['data' => ['queued' => true]], 202);
    }

    /** Attach a recording manually (e.g. Google Meet sessions): a video uploaded through the course video uploader. */
    public function attachReplay(Request $request, LiveSession $session, LiveRecordingService $recordings): JsonResponse
    {
        $this->authorizeManage($request, $session);
        $data = $request->validate(['video_id' => ['required', 'uuid']]);
        $video = Video::where('uuid', $data['video_id'])->firstOrFail();
        abort_unless($video->uploaded_by === $this->user($request)->id || $this->user($request)->can('live.manage-any'), 403);
        if ($video->status === VideoStatus::Failed) {
            throw ValidationException::withMessages(['video_id' => 'That video failed to process.']);
        }
        $recordings->attachReplay($session, $video);

        return response()->json(['data' => $this->presenter->present($session->fresh(['instructor', 'course', 'recordingVideo', 'replayLesson']) ?? $session, $this->user($request), null, false)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => [$req, 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'type' => [$req, Rule::enum(LiveSessionType::class)],
            'required_tier' => ['nullable', 'integer', 'between:1,3'],
            'course_id' => ['nullable', 'uuid'],
            'starts_at' => [$req, 'date_format:Y-m-d\TH:i'],                // wall-clock time in `timezone`
            'timezone' => [$req, 'timezone:all_with_bc'], // browsers still report aliases like Asia/Calcutta
            'duration' => [$req, 'integer', 'between:15,480'],
            'capacity' => ['nullable', 'integer', 'between:1,10000'],
            'provider' => [$creating ? 'required' : 'prohibited', Rule::enum(LiveProviderType::class)],
            'join_url' => ['nullable', 'required_if:provider,external_link', 'url:https', 'max:500'],
            'repeat' => [$creating ? 'nullable' : 'prohibited', 'array'],
            'repeat.weekdays' => ['required_with:repeat', 'array', 'min:1'],
            'repeat.weekdays.*' => ['integer', 'between:1,7'],
            'repeat.occurrences' => ['required_with:repeat', 'integer', 'between:2,52'],
        ]);

        if (! empty($data['course_id'])) {
            $course = Course::where('uuid', $data['course_id'])->first();
            if (! $course || ! ($course->isTaughtBy($this->user($request)) || $this->user($request)->can('live.manage-any'))) {
                throw ValidationException::withMessages(['course_id' => 'Pick one of your courses.']);
            }
            $data['course_id'] = $course->id;
            $data['required_tier'] ??= $course->required_tier; // course sessions inherit the course's tier by default
        } elseif (array_key_exists('course_id', $data)) {
            $data['course_id'] = null;
        }
        if (! $creating) {
            unset($data['join_url']); // the link of an external session is changed by re-scheduling
        }

        return $data;
    }

    private function authorizeManage(Request $request, LiveSession $session): void
    {
        $user = $this->user($request);
        abort_unless($user->can('live.manage-any') || ($user->can('live.manage-own') && $session->instructor_id === $user->id), 403);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\LiveSessionStatus;
use App\Enums\LiveSessionType;
use App\Http\Controllers\Controller;
use App\Live\CalendarLinks;
use App\Live\LivePresenter;
use App\Live\LiveRegistrationService;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use App\Services\MembershipService;
use App\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Learner side of live classes: browse (list/calendar), register, join, calendar files, replays. */
class LiveSessionController extends Controller
{
    public function __construct(
        private readonly LivePresenter $presenter,
        private readonly LiveRegistrationService $registrations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
            'tier' => ['nullable', Rule::in(['starter', 'pro', 'elite', 'open'])],
            'instructor' => ['nullable', 'uuid'],
            'type' => ['nullable', Rule::enum(LiveSessionType::class)],
            'mine' => ['nullable', 'boolean'],
        ]);
        $user = $this->user($request);
        // Default: from now (incl. sessions in progress) for 60 days. Calendar views pass a month range.
        $from = isset($data['from']) ? Carbon::parse($data['from']) : now()->subHours(6);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : now()->addDays(60);
        abort_if($from->diffInDays($to) > 93, 422, 'Pick a range of at most three months.');

        $sessions = LiveSession::with('instructor', 'course', 'recordingVideo', 'replayLesson')->withCount('registrations')
            ->whereBetween('starts_at', [$from, $to])
            ->when(! isset($data['from']), fn ($q) => $q->upcoming())
            ->when($data['tier'] ?? null, fn ($q, $t) => $t === 'open' ? $q->whereNull('required_tier') : $q->where('required_tier', ['starter' => 1, 'pro' => 2, 'elite' => 3][$t]))
            ->when($data['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($data['instructor'] ?? null, fn ($q, $i) => $q->whereHas('instructor', fn ($iq) => $iq->where('uuid', $i)))
            ->when($data['mine'] ?? false, fn ($q) => $q->whereHas('registrations', fn ($rq) => $rq->where('user_id', $user->id)))
            ->orderBy('starts_at')->limit(300)->get();

        $mine = LiveRegistration::where('user_id', $user->id)->whereIn('live_session_id', $sessions->pluck('id'))->get()->keyBy('live_session_id');

        return response()->json([
            'data' => $sessions->map(fn (LiveSession $s) => $this->presenter->present($s, $user, $mine->get($s->id), false))->values(),
            'meta' => [
                'instructors' => User::whereIn('id', LiveSession::upcoming()->distinct()->pluck('instructor_id'))->orderBy('name')->get()
                    ->map(fn (User $u) => ['id' => $u->uuid, 'name' => $u->name])->values(),
                'join_early_minutes' => (int) config('live.join_early_minutes'),
            ],
        ]);
    }

    public function show(Request $request, LiveSession $session): JsonResponse
    {
        $session->load('instructor', 'course', 'recordingVideo', 'replayLesson')->loadCount('registrations');

        return response()->json(['data' => $this->presenter->present($session, $this->user($request))]);
    }

    public function register(Request $request, LiveSession $session): JsonResponse
    {
        $reg = $this->registrations->register($this->user($request), $session);

        return response()->json(['data' => $this->presenter->present($session->load('instructor', 'course')->loadCount('registrations'), $this->user($request), $reg)],
            $reg->wasRecentlyCreated ? 201 : 200);
    }

    public function unregister(Request $request, LiveSession $session): Response
    {
        $this->registrations->unregister($this->user($request), $session);

        return response()->noContent();
    }

    /** Embedded-SDK credentials (or a link to open). Only for tier-eligible registrants inside the join window. */
    public function join(Request $request, LiveSession $session): JsonResponse
    {
        return response()->json(['data' => $this->registrations->join($this->user($request), $session)])->header('Cache-Control', 'no-store');
    }

    /** One session, or every upcoming session I registered for (`/live-sessions/mine.ics`). */
    public function ics(Request $request, CalendarLinks $calendar, ?LiveSession $session = null): Response
    {
        $sessions = $session ? [$session] : LiveSession::whereHas('registrations', fn ($q) => $q->where('user_id', $this->user($request)->id))
            ->where('starts_at', '>', now()->subDay())->orderBy('starts_at')->get();
        $name = $session ? $session->title : config('lms.brand.name').' live classes';

        return response($calendar->ics($sessions, $name), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.($session ? 'live-'.$session->uuid : 'my-live-classes').'.ics"',
        ]);
    }

    // ---- Replays ---------------------------------------------------------

    public function replays(Request $request, MembershipService $membership): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $sessions = LiveSession::with('instructor', 'course', 'recordingVideo', 'replayLesson')->withCount('registrations')
            ->where('status', LiveSessionStatus::Ended)
            ->whereHas('recordingVideo', fn ($q) => $q->where('status', 'ready'))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->orderByDesc('starts_at')->limit(200)->get();

        return response()->json(['data' => $sessions->map(fn (LiveSession $s) => [
            ...$this->presenter->present($s, $user, null, false),
            'replay_duration_seconds' => $s->recordingVideo?->duration_seconds,
            'locked' => ! $this->registrations->eligible($user, $s),
        ])->values()]);
    }

    public function replayPlayback(Request $request, LiveSession $session, VideoService $videos): JsonResponse
    {
        $user = $this->user($request);
        $video = $session->recordingVideo;
        abort_unless($video?->isReady(), 404);
        if (! $this->registrations->eligible($user, $session)) {
            $tier = $session->requiredTier();

            return response()->json([
                'message' => 'This replay is for '.$tier?->label().' members and above.',
                'code' => 'membership_required', 'required_tier' => $tier?->value, 'required_tier_slug' => $tier?->slug(),
            ], 402);
        }
        $playback = $videos->provider()->playback($video, $videos->ttlFor($video));

        return response()->json(['data' => [
            'url' => $playback['url'], 'poster' => $playback['poster'], 'expires_at' => date(DATE_ATOM, $playback['expires_at']),
        ]])->header('Cache-Control', 'no-store');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}

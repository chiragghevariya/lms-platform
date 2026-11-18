<?php

namespace App\Http\Controllers\Api\V1;

use App\Courses\CourseAccessService;
use App\Enums\MembershipTier;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Issues short-lived signed HLS URLs, only to viewers who pass access checks
 * (MembershipService::canAccess via CourseAccessService, purchase, preview, drip).
 */
class PlaybackController extends Controller
{
    public function lesson(Request $request, Lesson $lesson, CourseAccessService $access, VideoService $videos): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user() ?? $request->user('sanctum');
        $lesson->load('course', 'video');
        $enrollment = $access->enrollmentFor($user, $lesson->course);
        $state = $access->lessonState($user, $lesson, $enrollment);

        if (! $state['unlocked']) {
            return $this->denied($state);
        }

        if (! $lesson->video?->isReady()) {
            return response()->json(['message' => 'This video is still processing.', 'code' => 'video_not_ready'], 409);
        }

        if ($user && $access->canAccessCourse($user, $lesson->course)) {
            $access->enroll($user, $lesson->course);
        }

        $ttl = $videos->ttlFor($lesson->video);
        $playback = $videos->provider()->playback($lesson->video, $ttl);

        return response()->json(['data' => [
            'url' => $playback['url'],
            'poster' => $playback['poster'],
            'expires_at' => date(DATE_ATOM, $playback['expires_at']),
            'ttl_seconds' => $ttl,
            // Drawn over the player (moving position) so screen recordings identify the account.
            'watermark' => $user ? ['text' => $user->email, 'id' => substr($user->uuid, -8)] : null,
        ]])->header('Cache-Control', 'no-store');
    }

    /** Course promo video: public once the course is published. */
    public function promo(Request $request, string $slug, CourseAccessService $access, VideoService $videos): JsonResponse
    {
        $course = Course::where('slug', $slug)->with('promoVideo')->firstOrFail();
        $user = $request->user() ?? $request->user('sanctum');
        abort_unless($course->isPublished() || $access->canManage($user, $course), 404);
        abort_unless($course->promoVideo?->isReady(), 404);

        $playback = $videos->provider()->playback($course->promoVideo, $videos->ttlFor($course->promoVideo));

        return response()->json(['data' => ['url' => $playback['url'], 'poster' => $playback['poster'], 'expires_at' => date(DATE_ATOM, $playback['expires_at'])]])
            ->header('Cache-Control', 'no-store');
    }

    /** @param array{unlocked: bool, reason: string, required_tier: MembershipTier|null, available_at: Carbon|null} $state */
    private function denied(array $state): JsonResponse
    {
        return match ($state['reason']) {
            'login_required' => response()->json(['message' => 'Sign in to watch this lesson.', 'code' => 'login_required'], 401),
            'drip_locked' => response()->json([
                'message' => 'This lesson unlocks on '.$state['available_at']?->toFormattedDayDateString().'.',
                'code' => 'drip_locked',
                'available_at' => $state['available_at']?->toIso8601String(),
            ], 403),
            'unpublished' => response()->json(['message' => 'Not found.'], 404),
            default => response()->json([
                'message' => 'This lesson requires the '.$state['required_tier']?->label().' membership or higher.',
                'code' => 'membership_required',
                'required_tier' => $state['required_tier']?->value,
                'required_tier_slug' => $state['required_tier']?->slug(),
            ], 402),
        };
    }
}

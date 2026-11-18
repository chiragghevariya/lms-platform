<?php

namespace App\Live;

use App\Enums\LiveSessionStatus;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\Live\LiveRegistrationConfirmedNotification;
use App\Services\MembershipService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Registration, capacity and join rules. Tier eligibility is re-checked at join time (memberships can lapse). */
class LiveRegistrationService
{
    public function __construct(
        private readonly MembershipService $membership,
        private readonly LiveProviders $providers,
    ) {}

    public function canManage(?User $user, LiveSession $session): bool
    {
        return $user !== null && ($user->can('live.manage-any') || $session->instructor_id === $user->id);
    }

    public function eligible(?User $user, LiveSession $session): bool
    {
        return $user !== null && ($this->canManage($user, $session) || $this->membership->canAccess($user, $session));
    }

    public function registrationFor(?User $user, LiveSession $session): ?LiveRegistration
    {
        return $user ? LiveRegistration::where('live_session_id', $session->id)->where('user_id', $user->id)->first() : null;
    }

    public function register(User $user, LiveSession $session): LiveRegistration
    {
        if (in_array($session->status, [LiveSessionStatus::Ended, LiveSessionStatus::Cancelled], true) || now()->gte($session->endsAt())) {
            throw ValidationException::withMessages(['session' => 'This session has ended or was cancelled.']);
        }
        $this->assertEligible($user, $session);

        $registration = DB::transaction(function () use ($user, $session) {
            $locked = LiveSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $existing = $this->registrationFor($user, $locked);
            if ($existing) {
                return $existing;
            }
            if ($locked->capacity !== null && $locked->registrations()->count() >= $locked->capacity) {
                throw ValidationException::withMessages(['session' => 'This session is full.']);
            }

            return LiveRegistration::create(['live_session_id' => $locked->id, 'user_id' => $user->id]);
        });

        if ($registration->wasRecentlyCreated) {
            $user->notify(new LiveRegistrationConfirmedNotification($session));
        }

        return $registration;
    }

    public function unregister(User $user, LiveSession $session): void
    {
        if ($session->status === LiveSessionStatus::Live || $session->status === LiveSessionStatus::Ended) {
            throw ValidationException::withMessages(['session' => 'You can\'t leave a session that has started.']);
        }
        LiveRegistration::where('live_session_id', $session->id)->where('user_id', $user->id)->delete();
    }

    /** @return array<string, mixed> */
    public function join(User $user, LiveSession $session): array
    {
        $this->assertEligible($user, $session);
        $registration = $this->registrationFor($user, $session)
            ?? throw ValidationException::withMessages(['session' => 'Register for this session first.']);

        if (! $session->isJoinable()) {
            throw new HttpResponseException(response()->json([
                'message' => $session->status === LiveSessionStatus::Cancelled ? 'This session was cancelled.'
                    : (now()->lt($session->starts_at) ? 'You can join '.config('live.join_early_minutes').' minutes before the start.' : 'This session has ended.'),
                'code' => 'join_not_open',
                'opens_at' => $session->starts_at->copy()->subMinutes((int) config('live.join_early_minutes'))->toIso8601String(),
            ], 403));
        }

        $payload = $this->providers->for($session->provider)->joinPayload($session, $user, $registration);

        // Join intent. For providers without reports (external links) this is the only attendance signal we get.
        $registration->joined_at ??= now();
        if ($payload['mode'] === 'external' && $session->provider->value === 'external_link') {
            $registration->attended = true;
        }
        $registration->save();

        return $payload;
    }

    private function assertEligible(User $user, LiveSession $session): void
    {
        if (! $this->eligible($user, $session)) {
            $tier = $session->requiredTier();
            throw new HttpResponseException(response()->json([
                'message' => 'This session is for '.$tier?->label().' members and above.',
                'code' => 'membership_required',
                'required_tier' => $tier?->value,
                'required_tier_slug' => $tier?->slug(),
            ], 402));
        }
    }
}

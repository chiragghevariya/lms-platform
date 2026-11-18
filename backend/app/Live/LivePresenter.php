<?php

namespace App\Live;

use App\Enums\LiveSessionStatus;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use App\Services\AvatarService;

/** The one place that shapes live sessions for the API (never exposes join/host links or passcodes). */
class LivePresenter
{
    public function __construct(
        private readonly LiveRegistrationService $registrations,
        private readonly CalendarLinks $calendar,
        private readonly AvatarService $avatars,
    ) {}

    /** @return array<string, mixed> */
    public function present(LiveSession $s, ?User $viewer, ?LiveRegistration $registration = null, bool $withRegistration = true): array
    {
        $registration ??= $withRegistration ? $this->registrations->registrationFor($viewer, $s) : null;
        $eligible = $this->registrations->eligible($viewer, $s);
        $count = $s->registrations_count ?? $s->registrations()->count();
        $closed = in_array($s->status, [LiveSessionStatus::Ended, LiveSessionStatus::Cancelled], true) || now()->gte($s->endsAt());

        return [
            'id' => $s->uuid,
            'title' => $s->title,
            'description' => $s->description,
            'type' => $s->type->value,
            'type_label' => $s->type->label(),
            'status' => $s->status->value,
            'starts_at' => $s->starts_at->toIso8601String(),
            'ends_at' => $s->endsAt()->toIso8601String(),
            'duration' => $s->duration,
            'timezone' => $s->timezone,
            'required_tier' => $s->requiredTier()?->slug(),
            'provider' => $s->provider->value,
            'capacity' => $s->capacity,
            'registered_count' => $count,
            'seats_left' => $s->capacity !== null ? max(0, $s->capacity - $count) : null,
            'is_series' => $s->series_id !== null,
            'instructor' => ['id' => $s->instructor->uuid, 'name' => $s->instructor->name, 'avatar_url' => $this->avatars->url($s->instructor->avatar_path)],
            'course' => $s->course ? ['id' => $s->course->uuid, 'slug' => $s->course->slug, 'title' => $s->course->title] : null,
            'cancel_reason' => $s->status === LiveSessionStatus::Cancelled ? $s->cancel_reason : null,
            'has_replay' => $s->recordingVideo?->isReady() ?? false,
            'replay_lesson_id' => $s->replayLesson?->uuid,
            'viewer' => $viewer ? [
                'eligible' => $eligible,
                'registered' => $registration !== null,
                'can_register' => $eligible && ! $registration && ! $closed && ($s->capacity === null || $count < $s->capacity),
                'joinable' => $registration !== null && $eligible && $s->isJoinable(),
                'join_opens_at' => $s->starts_at->copy()->subMinutes((int) config('live.join_early_minutes'))->toIso8601String(),
                'attended' => $registration?->attended,
                'can_manage' => $this->registrations->canManage($viewer, $s),
            ] : null,
            'calendar' => ['google' => $this->calendar->google($s), 'ics' => route('api.v1.live.ics', $s)],
        ];
    }
}

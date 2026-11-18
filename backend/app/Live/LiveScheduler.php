<?php

namespace App\Live;

use App\Enums\LiveProviderType;
use App\Enums\LiveSessionStatus;
use App\Enums\LiveSessionType;
use App\Models\LiveRegistration;
use App\Models\LiveSeries;
use App\Models\LiveSession;
use App\Models\User;
use App\Notifications\Live\LiveSessionCancelledNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Schedules one-off sessions and weekly series (timezone/DST-aware), keeps the remote meeting in sync, cancels. */
class LiveScheduler
{
    public function __construct(private readonly LiveProviders $providers) {}

    /**
     * @param  array{title: string, description?: string|null, type: string, required_tier?: int|null, course_id?: int|null,
     *     starts_at: string, timezone: string, duration: int, capacity?: int|null, provider: string, join_url?: string|null,
     *     repeat?: array{weekdays: list<int>, occurrences: int}|null}  $data  starts_at is local wall-clock time in `timezone`
     * @return Collection<int, LiveSession>
     */
    public function schedule(User $instructor, array $data): Collection
    {
        $type = LiveSessionType::from($data['type']);
        $provider = LiveProviderType::from($data['provider']);
        $adapter = $this->providers->for($provider);
        $first = Carbon::parse($data['starts_at'], $data['timezone']);
        $starts = isset($data['repeat']) ? $this->occurrences($first, $data['repeat']['weekdays'], $data['repeat']['occurrences']) : collect([$first]);

        if ($starts->first()?->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'Pick a time in the future.']);
        }

        $series = isset($data['repeat']) ? LiveSeries::create([
            'instructor_id' => $instructor->id,
            'weekdays' => array_values(array_unique($data['repeat']['weekdays'])),
            'local_time' => $first->format('H:i'),
            'timezone' => $data['timezone'],
            'starts_on' => $first->toDateString(),
            'occurrences' => $starts->count(),
        ]) : null;

        $created = collect();
        try {
            foreach ($starts as $start) {
                $session = new LiveSession([
                    'series_id' => $series?->id,
                    'instructor_id' => $instructor->id,
                    'course_id' => $data['course_id'] ?? null,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'type' => $type,
                    'required_tier' => $data['required_tier'] ?? null,
                    'starts_at' => $start->copy()->utc(),
                    'duration' => $data['duration'],
                    'timezone' => $data['timezone'],
                    'capacity' => $type === LiveSessionType::Mentorship ? 1 : ($data['capacity'] ?? null),
                    'provider' => $provider,
                    'join_url' => $provider === LiveProviderType::ExternalLink ? $data['join_url'] : null,
                ]);
                $adapter->create($session); // remote meeting first, so we never store a session without one
                $session->save();
                $created->push($session);
            }
        } catch (Throwable $e) {
            // Roll back remote meetings already created for this request, then the rows.
            $created->each(function (LiveSession $s) use ($adapter) {
                rescue(fn () => $adapter->delete($s), report: false);
                $s->forceDelete();
            });
            $series?->delete();
            throw $e;
        }

        return $created;
    }

    /**
     * Local wall-clock occurrences on the given ISO weekdays, starting at $first (which counts if it matches).
     *
     * @param  list<int>  $weekdays
     * @return Collection<int, Carbon>
     */
    public function occurrences(Carbon $first, array $weekdays, int $count): Collection
    {
        $out = collect();
        $day = $first->copy();
        $guard = 0;
        while ($out->count() < $count && $guard++ < 400) {
            if (in_array($day->dayOfWeekIso, $weekdays, true)) {
                // Rebuild from the date + local time each time so DST shifts keep the same wall-clock time.
                $out->push(Carbon::parse($day->toDateString().' '.$first->format('H:i'), $first->getTimezone()));
            }
            $day->addDay();
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    public function update(LiveSession $session, array $data): LiveSession
    {
        if (in_array($session->status, [LiveSessionStatus::Ended, LiveSessionStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['session' => 'Ended or cancelled sessions can no longer be edited.']);
        }
        if (isset($data['starts_at'])) {
            $start = Carbon::parse($data['starts_at'], $data['timezone'] ?? $session->timezone);
            if ($start->isPast() && $session->status === LiveSessionStatus::Scheduled) {
                throw ValidationException::withMessages(['starts_at' => 'Pick a time in the future.']);
            }
            $data['starts_at'] = $start->utc();
        }
        if (isset($data['capacity']) && $data['capacity'] < $session->registrations()->count()) {
            throw ValidationException::withMessages(['capacity' => 'Capacity can\'t be below the number of people already registered.']);
        }

        $session->fill($data);
        $remoteFields = ['title', 'description', 'starts_at', 'duration', 'timezone'];
        if ($session->isDirty($remoteFields)) {
            $this->providers->for($session->provider)->update($session);
        }
        // Reschedule → reminders go out again for the new time.
        if ($session->isDirty('starts_at')) {
            LiveRegistration::where('live_session_id', $session->id)->update(['reminded_24h_at' => null, 'reminded_15m_at' => null]);
        }
        $session->save();

        return $session;
    }

    /**
     * Cancels this session (or this and the following ones in its series), deletes remote meetings, emails registrants.
     *
     * @return int number of sessions cancelled
     */
    public function cancel(LiveSession $session, ?string $reason, bool $following = false): int
    {
        $targets = $following && $session->series_id
            ? LiveSession::where('series_id', $session->series_id)->where('starts_at', '>=', $session->starts_at)
                ->where('status', LiveSessionStatus::Scheduled)->get()
            : collect([$session]);

        $count = 0;
        foreach ($targets as $s) {
            if (in_array($s->status, [LiveSessionStatus::Ended, LiveSessionStatus::Cancelled], true)) {
                continue;
            }
            rescue(fn () => $this->providers->for($s->provider)->delete($s)); // reported, but never blocks the cancel
            DB::transaction(fn () => $s->forceFill(['status' => LiveSessionStatus::Cancelled, 'cancel_reason' => $reason])->save());
            $users = User::whereIn('id', $s->registrations()->pluck('user_id'))->get();
            Notification::send($users, new LiveSessionCancelledNotification($s));
            $count++;
        }

        return $count;
    }
}

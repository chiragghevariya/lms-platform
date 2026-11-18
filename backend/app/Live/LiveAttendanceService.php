<?php

namespace App\Live;

use App\Learning\GamificationService;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Turns provider participant rows into registration attendance (matched by SDK customer key, then email). */
class LiveAttendanceService
{
    public function __construct(private readonly GamificationService $gamification) {}

    /** A registrant "attended" after this many seconds in the meeting (capped at half the session for short ones). */
    public function threshold(LiveSession $session): int
    {
        return min(300, (int) ($session->duration * 60 / 2));
    }

    /**
     * @param  list<array{customer_key: string|null, email: string|null, join_time: string, leave_time: string|null, duration: int}>  $rows
     * @return int registrations marked as attended
     */
    public function apply(LiveSession $session, array $rows): int
    {
        $registrations = LiveRegistration::where('live_session_id', $session->id)->with('user')->get();
        $byKey = $registrations->keyBy(fn (LiveRegistration $r) => $r->customerKey());
        $byEmail = $registrations->keyBy(fn (LiveRegistration $r) => strtolower($r->user->email));

        /** @var array<int, array{reg: LiveRegistration, seconds: int, join: Carbon, leave: Carbon|null}> $agg */
        $agg = [];
        foreach ($rows as $row) {
            $reg = ($row['customer_key'] ? $byKey->get($row['customer_key']) : null) ?? ($row['email'] ? $byEmail->get($row['email']) : null);
            if (! $reg) {
                continue; // host, guests, people who joined without registering
            }
            $join = Carbon::parse($row['join_time']);
            $leave = $row['leave_time'] ? Carbon::parse($row['leave_time']) : null;
            $cur = $agg[$reg->id] ?? ['reg' => $reg, 'seconds' => 0, 'join' => $join, 'leave' => $leave];
            $cur['seconds'] += $row['duration'];  // re-joins produce several rows
            $cur['join'] = $join->lt($cur['join']) ? $join : $cur['join'];
            $cur['leave'] = $leave && (! $cur['leave'] || $leave->gt($cur['leave'])) ? $leave : $cur['leave'];
            $agg[$reg->id] = $cur;
        }

        $attended = 0;
        foreach ($agg as $a) {
            $ok = $a['seconds'] >= $this->threshold($session);
            $a['reg']->forceFill([
                'attended' => $ok,
                'attended_seconds' => $a['seconds'],
                'joined_at' => $a['join'],
                'left_at' => $a['leave'],
            ])->save();
            if ($ok) {
                $attended++;
                /** @var User $user */
                $user = $a['reg']->user;
                $this->gamification->award($user, 'live_attended', 'live:'.$session->id);
                $this->gamification->recordActivity($user, $a['seconds']);
            }
        }
        $session->forceFill(['attendance_synced_at' => now()])->save();

        return $attended;
    }
}

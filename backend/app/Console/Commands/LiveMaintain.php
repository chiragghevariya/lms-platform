<?php

namespace App\Console\Commands;

use App\Enums\LiveProviderType;
use App\Enums\LiveSessionStatus;
use App\Events\LiveSessionReminderDue;
use App\Jobs\SyncLiveAttendance;
use App\Models\LiveRegistration;
use App\Models\LiveSession;
use Illuminate\Console\Command;

/** Every minute: time-based status changes (when no webhook arrives), reminders, attendance sync fallback. */
class LiveMaintain extends Command
{
    protected $signature = 'live:maintain';

    protected $description = 'Advance live session statuses and send reminders';

    public function handle(): int
    {
        $now = now();

        // External links have no "meeting started" webhook: they are live during their slot.
        LiveSession::where('provider', LiveProviderType::ExternalLink)->where('status', LiveSessionStatus::Scheduled)
            ->where('starts_at', '<=', $now)->get()
            ->each(fn (LiveSession $s) => $now->lt($s->endsAt()) && $s->forceFill(['status' => LiveSessionStatus::Live, 'started_at' => $s->starts_at])->save());

        // End sessions whose slot (+ grace) is over and no "ended" webhook arrived; then fetch attendance.
        $ended = 0;
        LiveSession::whereIn('status', [LiveSessionStatus::Scheduled, LiveSessionStatus::Live])
            ->where('starts_at', '<', $now)->get()
            ->filter(fn (LiveSession $s) => $now->gte($s->endsAt()->addMinutes((int) config('live.auto_end_grace_minutes'))))
            ->each(function (LiveSession $s) use (&$ended) {
                $s->forceFill(['status' => LiveSessionStatus::Ended, 'ended_at' => $s->ended_at ?? $s->endsAt()])->save();
                if ($s->provider === LiveProviderType::Zoom && ! $s->attendance_synced_at) {
                    SyncLiveAttendance::dispatch($s->id);
                }
                $ended++;
            });

        // Reminders (24 h and 15 min). Someone who registers inside a window skips that reminder.
        $sent = 0;
        foreach ((array) config('live.reminders') as $minutes) {
            $column = $minutes >= 60 ? 'reminded_24h_at' : 'reminded_15m_at';
            LiveRegistration::whereNull($column)
                ->whereHas('session', fn ($q) => $q->where('status', LiveSessionStatus::Scheduled)
                    ->where('starts_at', '>', $now)->where('starts_at', '<=', $now->copy()->addMinutes((int) $minutes)))
                ->with('session', 'user')->chunkById(200, function ($regs) use ($column, $minutes, &$sent) {
                    foreach ($regs as $reg) {
                        /** @var LiveRegistration $reg */
                        $lateSignup = $minutes >= 60 && $reg->created_at?->gt($reg->session->starts_at->copy()->subMinutes((int) $minutes));
                        $reg->forceFill([$column => now()])->save();
                        if (! $lateSignup) {
                            LiveSessionReminderDue::dispatch($reg, (int) $minutes);
                            $sent++;
                        }
                    }
                });
        }

        $this->info("ended={$ended} reminders={$sent}");

        return self::SUCCESS;
    }
}

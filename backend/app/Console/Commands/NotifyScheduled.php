<?php

namespace App\Console\Commands;

use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Jobs\SendCampaign;
use App\Models\Campaign;
use App\Models\CourseProgress;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Platform\InactivityNotification;
use App\Notifications\Platform\SubscriptionExpiringNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Time-based notifications, each sent once (notification_markers):
 *  - memberships that won't renew, ending in 7 / 1 day(s);
 *  - inactivity nudges after 7 / 14 days without learning (members only);
 *  - scheduled campaigns that are due.
 */
class NotifyScheduled extends Command
{
    protected $signature = 'notify:scheduled';

    protected $description = 'Expiring-membership reminders, inactivity nudges and due campaigns';

    public function handle(): int
    {
        $sent = ['expiring' => 0, 'inactivity' => 0, 'campaigns' => 0];

        foreach ((array) config('notify.expiring_days') as $days) {
            Subscription::with('user', 'plan')
                ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing])
                ->where(fn ($q) => $q->where('cancel_at_period_end', true)->orWhere('gateway', Gateway::Manual))
                ->whereBetween('current_period_end', [now()->addDays($days - 1)->addMinute(), now()->addDays($days)])
                ->each(function (Subscription $s) use ($days, &$sent) {
                    if ($this->once("expiring:{$s->id}:{$days}:".$s->current_period_end?->toDateString())) {
                        $s->user->notify(new SubscriptionExpiringNotification($s, (int) $days));
                        $sent['expiring']++;
                    }
                });
        }

        foreach ((array) config('notify.inactivity_days') as $days) {
            $day = now()->subDays((int) $days)->toDateString();
            // Members whose last learning day was exactly N days ago.
            $users = DB::table('learning_days')->groupBy('user_id')->havingRaw('MAX(day) = ?', [$day])->pluck('user_id');
            User::whereIn('id', $users)->each(function (User $u) use ($days, $day, &$sent) {
                if (! $u->activeSubscription() || ! $this->once("inactive:{$u->id}:{$days}:{$day}")) {
                    return;
                }
                $last = CourseProgress::with('course', 'lastLesson')->where('user_id', $u->id)->whereNull('completed_at')->latest('last_activity_at')->first();
                $url = $last?->course && $last->lastLesson ? config('lms.frontend_url').'/learn/'.$last->course->slug.'/'.$last->lastLesson->uuid : null;
                $u->notify(new InactivityNotification((int) $days, $last?->course?->title, $url));
                $sent['inactivity']++;
            });
        }

        Campaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->each(function (Campaign $c) use (&$sent) {
            SendCampaign::dispatch($c->id);
            $sent['campaigns']++;
        });

        $this->info(json_encode($sent) ?: '');

        return self::SUCCESS;
    }

    private function once(string $key): bool
    {
        return DB::table('notification_markers')->insertOrIgnore(['key' => $key, 'created_at' => now()]) > 0;
    }
}

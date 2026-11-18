<?php

namespace App\Notify;

use App\Models\NotificationPreference;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Decides which channels a notification goes out on: event defaults → the user's preferences
 * (except mandatory channels) → whether the channel can reach this user right now.
 * Channels that were wanted but couldn't be used are logged as "skipped" (with the reason).
 */
class NotificationRouter
{
    /** @return list<string> Laravel channel drivers */
    public function channels(object $notifiable, PlatformNotification $notification): array
    {
        if (! $notifiable instanceof User) {
            return ['mail']; // on-demand (Notification::route) recipients
        }
        $event = $notification->event();
        $cfg = (array) config("notify.events.{$event}");
        $mandatory = (array) ($cfg['mandatory'] ?? []);
        $prefs = $this->preferences($notifiable);
        $only = $notification->onlyChannels();

        $out = [];
        foreach (Channels::ALL as $ch) {
            $wanted = $only !== null ? in_array($ch, $only, true) : (bool) ($cfg['channels'][$ch] ?? false);
            if (! $wanted) {
                continue;
            }
            if (! in_array($ch, $mandatory, true) && ($prefs[$event][$ch] ?? null) === false) {
                $this->skip($notifiable, $notification, $ch, 'preference_off');

                continue;
            }
            $reason = $this->unreachable($notifiable, $notification, $ch);
            if ($reason !== null) {
                $this->skip($notifiable, $notification, $ch, $reason);
                // WhatsApp not possible → SMS fallback for events that allow it.
                if ($ch === 'whatsapp' && ($cfg['sms_fallback'] ?? false) && $this->unreachable($notifiable, $notification, 'sms') === null) {
                    $out[] = 'sms';
                }

                continue;
            }
            $out[] = $ch;
        }

        return array_values(array_unique(array_map(fn ($k) => Channels::driver($k), $out)));
    }

    /** Whether the user would get this event on a channel (for the preferences screen). */
    public function enabled(User $user, string $event, string $channel): bool
    {
        $cfg = (array) config("notify.events.{$event}");
        if (in_array($channel, (array) ($cfg['mandatory'] ?? []), true)) {
            return true;
        }

        return $this->preferences($user)[$event][$channel] ?? (bool) ($cfg['channels'][$channel] ?? false);
    }

    /** @return array<string, array<string, bool>> */
    public function preferences(User $user): array
    {
        /** @var Collection<int, NotificationPreference> $rows */
        $rows = NotificationPreference::where('user_id', $user->id)->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->event][$r->channel] = $r->enabled;
        }

        return $out;
    }

    private function unreachable(User $user, PlatformNotification $n, string $ch): ?string
    {
        return match ($ch) {
            'mail' => $user->email ? null : 'no_email',
            'database' => null,
            'whatsapp' => match (true) {
                ! Channels::whatsappConfigured() => 'not_configured',
                ! $user->phone || ! $user->phone_verified_at => 'no_verified_phone',
                ! $user->whatsapp_opt_in_at || ($user->whatsapp_opt_out_at && $user->whatsapp_opt_out_at->gt($user->whatsapp_opt_in_at)) => 'no_whatsapp_consent',
                $n->toWhatsApp($user) === null => 'no_template',
                default => null,
            },
            'push' => ! Channels::pushConfigured() ? 'not_configured' : (PushToken::where('user_id', $user->id)->exists() ? null : 'no_device'),
            'sms' => ! $user->phone || ! $user->phone_verified_at ? 'no_verified_phone' : null,
            default => 'unknown_channel',
        };
    }

    private function skip(User $user, PlatformNotification $n, string $ch, string $reason): void
    {
        app(NotificationLogger::class)->skipped($user, $n, $ch, $reason);
    }
}

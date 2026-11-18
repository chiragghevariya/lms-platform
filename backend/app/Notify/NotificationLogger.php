<?php

namespace App\Notify;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;

/** notification_logs: one row per notification × channel, moved forward by sends and provider webhooks. */
class NotificationLogger
{
    /** Our notifications know their event; Laravel's built-in auth mails are mapped. */
    public static function eventFor(object $notification): ?string
    {
        return match (true) {
            $notification instanceof PlatformNotification => $notification->event(),
            $notification instanceof VerifyEmail => 'email_verify',
            $notification instanceof ResetPassword => 'password_reset',
            default => null,
        };
    }

    public function sending(NotificationSending $e): void
    {
        $event = self::eventFor($e->notification);
        if ($event === null || ! $e->notification->id) {
            return;
        }
        $n = $e->notification;
        $channel = Channels::key($e->channel);
        $log = NotificationLog::firstOrNew(['notification_id' => $n->id, 'channel' => $channel]);
        $log->fill([
            'user_id' => $e->notifiable instanceof User ? $e->notifiable->id : null,
            'campaign_id' => $n instanceof PlatformNotification ? $n->campaignId : null,
            'event' => $event,
            'status' => 'sending',
            'recipient' => Channels::mask($this->recipient($e->notifiable, $channel)),
            // Template variables for retries — never for auth mails (tokens) and never OTP codes.
            'vars' => $n instanceof PlatformNotification ? Arr::except($n->allVars($e->notifiable), ['code']) : null,
        ]);
        $log->attempts++;
        $log->save();
    }

    public function sent(NotificationSent $e): void
    {
        if (self::eventFor($e->notification) === null || ! $e->notification->id) {
            return;
        }
        $channel = Channels::key($e->channel);
        $r = $e->response;
        [$status, $provider, $id, $error] = match (true) {
            $r instanceof SentMessage => ['sent', (string) config('mail.default'), $r->getMessageId(), null],
            $channel === 'database' => ['delivered', 'database', null, null],
            is_array($r) => [(string) ($r['status'] ?? 'sent'), $r['provider'] ?? null, $r['id'] ?? null, $r['error'] ?? null],
            default => ['sent', null, null, null],
        };
        $log = NotificationLog::where('notification_id', $e->notification->id)->where('channel', $channel)->first();
        if (! $log) {
            return;
        }
        $log->forceFill([
            'status' => $status, 'provider' => $provider, 'provider_message_id' => $id,
            'error' => $error ? Str::limit((string) $error, 480) : null,
            'sent_at' => $status !== 'failed' ? now() : $log->sent_at,
            'delivered_at' => $status === 'delivered' ? now() : $log->delivered_at,
            'failed_at' => $status === 'failed' ? now() : null,
        ])->save();
    }

    public function skipped(User $user, PlatformNotification $n, string $channel, string $reason): void
    {
        NotificationLog::updateOrCreate(['notification_id' => $n->id ?: (string) Str::uuid(), 'channel' => $channel], [
            'user_id' => $user->id, 'campaign_id' => $n->campaignId, 'event' => $n->event(), 'status' => 'skipped', 'error' => $reason,
        ]);
    }

    public function failedNotification(string $notificationId, string $error): void
    {
        NotificationLog::where('notification_id', $notificationId)->where('status', 'sending')
            ->update(['status' => 'failed', 'failed_at' => now(), 'error' => Str::limit($error, 480)]);
    }

    /** Direct sends that aren't Laravel notifications (OTP codes, SMS fallbacks). */
    public function record(?User $user, string $event, string $channel, string $status, ?string $provider, ?string $id, ?string $recipient, ?string $error = null): NotificationLog
    {
        return NotificationLog::create([
            'notification_id' => (string) Str::uuid(), 'user_id' => $user?->id, 'event' => $event, 'channel' => $channel,
            'status' => $status, 'provider' => $provider, 'provider_message_id' => $id, 'recipient' => Channels::mask($recipient),
            'error' => $error ? Str::limit($error, 480) : null, 'attempts' => 1,
            'sent_at' => $status === 'failed' ? null : now(), 'failed_at' => $status === 'failed' ? now() : null,
        ]);
    }

    /** Provider webhook (delivered / read / failed …). Status never moves backwards. */
    public function track(string $provider, string $messageId, string $status, ?string $error = null, ?string $notificationId = null, ?string $channel = null): bool
    {
        $log = $notificationId
            ? NotificationLog::where('notification_id', $notificationId)->when($channel, fn ($q) => $q->where('channel', $channel))->first()
            : NotificationLog::where('provider_message_id', $messageId)->first();
        if (! $log || ! isset(NotificationLog::RANK[$status])) {
            return false;
        }
        if ($status !== 'failed' && NotificationLog::RANK[$status] <= (NotificationLog::RANK[$log->status] ?? 0)) {
            return true;
        }
        $log->forceFill([
            'status' => $status,
            'provider_message_id' => $log->provider_message_id ?? $messageId,
            'delivered_at' => in_array($status, ['delivered', 'read'], true) ? ($log->delivered_at ?? now()) : $log->delivered_at,
            'read_at' => $status === 'read' ? now() : $log->read_at,
            'failed_at' => $status === 'failed' ? now() : $log->failed_at,
            'error' => $error ? Str::limit($error, 480) : $log->error,
        ])->save();

        return true;
    }

    private function recipient(object $notifiable, string $channel): ?string
    {
        if (! $notifiable instanceof User) {
            return null;
        }

        return match ($channel) {
            'mail' => $notifiable->email,
            'whatsapp', 'sms' => $notifiable->phone,
            default => null,
        };
    }
}

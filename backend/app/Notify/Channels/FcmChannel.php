<?php

namespace App\Notify\Channels;

use App\Models\PushToken;
use App\Notify\FcmClient;
use App\Notify\PlatformNotification;
use RuntimeException;

/** Sends to every registered device; tokens FCM reports as dead are removed. */
class FcmChannel
{
    public function __construct(private readonly FcmClient $fcm) {}

    /** @return array{status: string, provider: string, id: string|null, error?: string|null} */
    public function send(object $notifiable, PlatformNotification $notification): array
    {
        $tokens = PushToken::where('user_id', $notifiable->id ?? 0)->get();
        $msg = $notification->toPush($notifiable);
        $ok = null;
        $errors = [];
        $transient = false;
        foreach ($tokens as $t) {
            $r = $this->fcm->send($t->token, $msg);
            if ($r['ok']) {
                $ok ??= $r['id'];
                $t->forceFill(['last_used_at' => now()])->save();
            } elseif ($r['unregistered']) {
                $t->delete();
            } else {
                $errors[] = $r['error'];
                $transient = $transient || $r['transient'];
            }
        }
        if (! $ok && $transient) {
            throw new RuntimeException('FCM: '.implode('; ', $errors));
        }

        return $ok ? ['status' => 'sent', 'provider' => 'fcm', 'id' => $ok]
            : ['status' => 'failed', 'provider' => 'fcm', 'id' => null, 'error' => $errors ? implode('; ', $errors) : 'no_valid_tokens'];
    }
}

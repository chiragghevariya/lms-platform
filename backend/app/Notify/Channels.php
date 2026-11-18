<?php

namespace App\Notify;

use App\Notify\Channels\FcmChannel;
use App\Notify\Channels\SmsChannel;
use App\Notify\Channels\WhatsAppChannel;

/** Short channel keys (preferences, logs, templates) ↔ Laravel channel names/classes. */
final class Channels
{
    public const ALL = ['mail', 'database', 'whatsapp', 'push', 'sms'];

    public const DRIVERS = [
        'mail' => 'mail',
        'database' => 'database',
        'whatsapp' => WhatsAppChannel::class,
        'push' => FcmChannel::class,
        'sms' => SmsChannel::class,
    ];

    public static function key(string $driver): string
    {
        return (string) (array_search($driver, self::DRIVERS, true) ?: $driver);
    }

    public static function driver(string $key): string
    {
        return self::DRIVERS[$key];
    }

    public static function whatsappConfigured(): bool
    {
        return filled(config('notify.whatsapp.token')) && filled(config('notify.whatsapp.phone_number_id'));
    }

    public static function pushConfigured(): bool
    {
        return filled(config('notify.fcm.project_id')) && filled(config('notify.fcm.credentials'));
    }

    public static function smsConfigured(): bool
    {
        return config('notify.sms.driver') !== null;
    }

    /** "+919876543210" → "+91••••••3210" (logs never hold full numbers / emails). */
    public static function mask(?string $recipient): ?string
    {
        if ($recipient === null || $recipient === '') {
            return null;
        }
        if (str_contains($recipient, '@')) {
            [$l, $d] = explode('@', $recipient, 2);

            return substr($l, 0, 2).'•••@'.$d;
        }

        return substr($recipient, 0, 3).str_repeat('•', max(0, strlen($recipient) - 7)).substr($recipient, -4);
    }
}

<?php

namespace App\Notify\Channels;

use App\Models\User;
use App\Notify\PlatformNotification;
use App\Notify\Sms\SmsException;
use App\Notify\Sms\SmsSender;

class SmsChannel
{
    public function __construct(private readonly SmsSender $sms) {}

    /** @return array{status: string, provider: string, id: string|null, error?: string|null} */
    public function send(object $notifiable, PlatformNotification $notification): array
    {
        if (! $notifiable instanceof User || ! $notifiable->phone) {
            return ['status' => 'failed', 'provider' => $this->sms->name(), 'id' => null, 'error' => 'no_phone'];
        }
        try {
            return ['status' => 'sent', 'provider' => $this->sms->name(), 'id' => $this->sms->send($notifiable->phone, $notification->toSms($notifiable))];
        } catch (SmsException $e) {
            if ($e->transient) {
                throw $e;
            }

            return ['status' => 'failed', 'provider' => $this->sms->name(), 'id' => null, 'error' => $e->getMessage()];
        }
    }
}

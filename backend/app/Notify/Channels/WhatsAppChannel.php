<?php

namespace App\Notify\Channels;

use App\Models\User;
use App\Notify\NotificationLogger;
use App\Notify\PlatformNotification;
use App\Notify\Sms\SmsException;
use App\Notify\Sms\SmsSender;
use App\Notify\WhatsAppClient;
use RuntimeException;

class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppClient $client, private readonly SmsSender $sms, private readonly NotificationLogger $logger) {}

    /** @return array{status: string, provider: string, id: string|null, error?: string|null} */
    public function send(object $notifiable, PlatformNotification $notification): array
    {
        $msg = $notification->toWhatsApp($notifiable);
        if (! $notifiable instanceof User || ! $notifiable->phone || ! $msg) {
            return ['status' => 'failed', 'provider' => 'whatsapp', 'id' => null, 'error' => 'no_template_or_phone'];
        }
        $r = $this->client->sendTemplate($notifiable->phone, $msg);
        if ($r['ok']) {
            return ['status' => 'sent', 'provider' => 'whatsapp', 'id' => $r['id']];
        }
        if ($r['transient']) {
            throw new RuntimeException('WhatsApp: '.$r['error']); // queue retries with backoff
        }
        // Permanent failure (template rejected, number not on WhatsApp…) → SMS fallback where the event allows it.
        if (config('notify.events.'.$notification->event().'.sms_fallback') && $notifiable->phone_verified_at && $msg->fallbackText) {
            try {
                $id = $this->sms->send($notifiable->phone, $msg->fallbackText);
                $this->logger->record($notifiable, $notification->event(), 'sms', 'sent', $this->sms->name(), $id, $notifiable->phone, 'fallback_from_whatsapp');
            } catch (SmsException $e) {
                $this->logger->record($notifiable, $notification->event(), 'sms', 'failed', $this->sms->name(), null, $notifiable->phone, $e->getMessage());
            }
        }

        return ['status' => 'failed', 'provider' => 'whatsapp', 'id' => null, 'error' => $r['error']];
    }
}

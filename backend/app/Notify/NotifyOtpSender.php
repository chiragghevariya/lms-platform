<?php

namespace App\Notify;

use App\Models\User;
use App\Notify\Sms\SmsException;
use App\Notify\Sms\SmsSender;
use App\Services\Otp\OtpSender;

/**
 * Phone OTPs (Phase 1) through the notification stack: WhatsApp authentication template when
 * OTP_CHANNEL=whatsapp (falls back to SMS), otherwise SMS. Every attempt is logged without the code.
 */
class NotifyOtpSender implements OtpSender
{
    public function __construct(private readonly SmsSender $sms, private readonly WhatsAppClient $whatsapp, private readonly NotificationLogger $logger) {}

    public function send(string $phone, string $message): void
    {
        $code = preg_match('/\b(\d{4,8})\b/', $message, $m) ? $m[1] : '';
        $user = User::where('phone', $phone)->first();

        if (config('notify.otp_channel') === 'whatsapp' && Channels::whatsappConfigured()) {
            $tpl = (array) config('notify.events.otp.whatsapp');
            $r = $this->whatsapp->sendTemplate($phone, new WhatsAppMessage((string) $tpl['template'], (string) config('notify.whatsapp.language'), [$code], null, $code));
            $this->logger->record($user, 'otp', 'whatsapp', $r['ok'] ? 'sent' : 'failed', 'whatsapp', $r['id'], $phone, $r['error']);
            if ($r['ok']) {
                return;
            }
        }
        try {
            $id = $this->sms->send($phone, $message, ['otp' => $code]);
            $this->logger->record($user, 'otp', 'sms', 'sent', $this->sms->name(), $id, $phone);
        } catch (SmsException $e) {
            $this->logger->record($user, 'otp', 'sms', 'failed', $this->sms->name(), null, $phone, $e->getMessage());
            throw $e; // the OTP job retries
        }
    }
}

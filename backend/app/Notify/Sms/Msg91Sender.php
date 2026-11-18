<?php

namespace App\Notify\Sms;

use Illuminate\Support\Facades\Http;

/**
 * MSG91 Flow API v5 (India; DLT-registered templates required). OTPs use the OTP template with an
 * `otp` variable; other alerts use a generic alert template with a `message` variable.
 */
class Msg91Sender implements SmsSender
{
    public function name(): string
    {
        return 'msg91';
    }

    public function send(string $phone, string $text, array $hints = []): ?string
    {
        $cfg = (array) config('notify.sms.msg91');
        $isOtp = isset($hints['otp']);
        $templateId = $isOtp ? ($cfg['otp_template_id'] ?? null) : ($cfg['alert_template_id'] ?? null);
        if (! $templateId || empty($cfg['authkey'])) {
            throw new SmsException('MSG91 is not configured (MSG91_AUTHKEY / template ids).');
        }
        $recipient = ['mobiles' => ltrim(preg_replace('/\D/', '', $phone) ?? '', '0')] + ($isOtp ? ['otp' => $hints['otp']] : ['message' => $text]);

        $res = Http::withHeaders(['authkey' => (string) $cfg['authkey']])->acceptJson()->timeout(10)
            ->post('https://control.msg91.com/api/v5/flow', ['template_id' => $templateId, 'short_url' => '0', 'recipients' => [$recipient]]);
        if (! $res->successful() || $res->json('type') === 'error') {
            throw new SmsException('MSG91: '.($res->json('message') ?? 'HTTP '.$res->status()), $res->serverError() || $res->status() === 429);
        }

        return is_string($res->json('message')) ? $res->json('message') : null; // request id
    }
}

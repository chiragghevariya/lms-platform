<?php

namespace App\Notify\Sms;

use Illuminate\Support\Facades\Http;

/** Twilio Programmable Messaging. Delivery updates arrive at /webhooks/twilio (StatusCallback). */
class TwilioSender implements SmsSender
{
    public function name(): string
    {
        return 'twilio';
    }

    public function send(string $phone, string $text, array $hints = []): ?string
    {
        $cfg = (array) config('notify.sms.twilio');
        if (empty($cfg['sid']) || empty($cfg['token']) || empty($cfg['from'])) {
            throw new SmsException('Twilio is not configured (TWILIO_ACCOUNT_SID / TWILIO_AUTH_TOKEN / TWILIO_FROM).');
        }
        $from = (string) $cfg['from'];
        $res = Http::asForm()->withBasicAuth((string) $cfg['sid'], (string) $cfg['token'])->timeout(10)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$cfg['sid']}/Messages.json", [
                'To' => $phone, 'Body' => $text,
                str_starts_with($from, 'MG') ? 'MessagingServiceSid' : 'From' => $from,
                'StatusCallback' => route('api.v1.webhooks.twilio'),
            ]);
        if (! $res->successful()) {
            throw new SmsException('Twilio: '.($res->json('message') ?? 'HTTP '.$res->status()), $res->serverError() || $res->status() === 429);
        }

        return (string) $res->json('sid');
    }
}

<?php

namespace App\Notify\Sms;

/** SMS provider adapter. Returns the provider's message id. Throws SmsException (transient = retry). */
interface SmsSender
{
    public function name(): string;

    /** @param array{otp?: string} $hints  templated providers (MSG91 DLT) need the OTP separately */
    public function send(string $phone, string $text, array $hints = []): ?string;
}

<?php

namespace App\Notify\Sms;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Local development: writes the SMS to the log. */
class LogSmsSender implements SmsSender
{
    public function name(): string
    {
        return 'log';
    }

    public function send(string $phone, string $text, array $hints = []): ?string
    {
        Log::info('[sms] to '.$phone.': '.$text);

        return 'log-'.Str::lower(Str::random(12));
    }
}

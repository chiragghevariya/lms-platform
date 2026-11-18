<?php

namespace App\Services\Otp;

use Illuminate\Support\Facades\Log;

/** Development driver: writes the message to the log instead of sending an SMS. */
class LogOtpSender implements OtpSender
{
    public function send(string $phone, string $message): void
    {
        Log::info('[OTP] to '.$phone.': '.$message);
    }
}

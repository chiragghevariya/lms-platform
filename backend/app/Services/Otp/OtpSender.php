<?php

namespace App\Services\Otp;

/** Delivery channel for one-time codes (SMS/WhatsApp provider plugs in here once chosen). */
interface OtpSender
{
    public function send(string $phone, string $message): void;
}

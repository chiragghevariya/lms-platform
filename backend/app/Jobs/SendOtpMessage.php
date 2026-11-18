<?php

namespace App\Jobs;

use App\Services\Otp\OtpSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Encrypted on the queue because the payload contains the plaintext code. */
class SendOtpMessage implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public function __construct(public string $phone, public string $message)
    {
        $this->onQueue('notifications');
    }

    public function handle(OtpSender $sender): void
    {
        $sender->send($this->phone, $this->message);
    }
}

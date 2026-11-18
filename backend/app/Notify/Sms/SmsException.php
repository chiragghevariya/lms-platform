<?php

namespace App\Notify\Sms;

use RuntimeException;

class SmsException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false)
    {
        parent::__construct($message);
    }
}

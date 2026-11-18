<?php

namespace App\Billing\Data;

final class CheckoutUrls
{
    public function __construct(public readonly string $success, public readonly string $cancel) {}

    public static function default(): self
    {
        $frontend = (string) config('lms.frontend_url');

        return new self($frontend.'/billing/success', $frontend.'/billing/failed');
    }
}

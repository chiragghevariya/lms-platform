<?php

namespace App\Affiliates\Payouts;

use App\Models\AffiliatePayout;

/**
 * Sends money to an affiliate. Only `manual` exists today (admins pay by bank/UPI/PayPal and record the
 * reference). Stripe Connect / RazorpayX Payouts implement this interface later — both are paid services.
 */
interface PayoutGateway
{
    public function name(): string;

    public function supports(string $method): bool;

    /** Initiates the transfer; returns the provider reference, or null when it happens outside the platform. */
    public function send(AffiliatePayout $payout): ?string;
}

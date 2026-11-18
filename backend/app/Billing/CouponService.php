<?php

namespace App\Billing;

use App\Enums\Gateway;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\PlanPrice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CouponService
{
    /**
     * Validates a code for this user + price and returns the coupon with the discount it gives.
     *
     * @return array{coupon: Coupon, discount: int, final_amount: int}
     *
     * @throws ValidationException
     */
    public function validate(string $code, User $user, PlanPrice $price, Gateway $gateway): array
    {
        $coupon = Coupon::where('code', strtoupper(trim($code)))->first();
        $fail = fn (string $msg) => throw ValidationException::withMessages(['coupon_code' => $msg]);

        if (! $coupon || ! $coupon->is_active) {
            $fail('This coupon code is not valid.');
        }
        /** @var Coupon $coupon */
        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            $fail('This coupon has expired.');
        }
        if ($coupon->max_uses !== null && $coupon->times_used >= $coupon->max_uses) {
            $fail('This coupon has reached its usage limit.');
        }
        if ($coupon->plans()->exists() && ! $coupon->plans()->whereKey($price->plan_id)->exists()) {
            $fail('This coupon does not apply to the selected plan.');
        }
        if (CouponRedemption::where('coupon_id', $coupon->id)->where('user_id', $user->id)->exists()) {
            $fail('You have already used this coupon.');
        }

        $discount = $coupon->discountFor($price->amount, $price->currency);
        if ($discount <= 0) {
            $fail('This coupon does not apply to prices in '.$price->currency.'.');
        }

        // Razorpay subscriptions only accept discounts via Offers created in the Razorpay dashboard.
        if ($gateway === Gateway::Razorpay && ! $price->isLifetime() && ! $coupon->razorpay_offer_id) {
            $fail('This coupon cannot be used for this payment method.');
        }

        return ['coupon' => $coupon, 'discount' => $discount, 'final_amount' => $price->amount - $discount];
    }

    /** Records a successful use (idempotent per user). */
    public function redeem(Coupon $coupon, User $user, int $discount, ?int $paymentId, ?int $subscriptionId): void
    {
        DB::transaction(function () use ($coupon, $user, $discount, $paymentId, $subscriptionId) {
            $created = CouponRedemption::firstOrCreate(
                ['coupon_id' => $coupon->id, 'user_id' => $user->id],
                ['payment_id' => $paymentId, 'subscription_id' => $subscriptionId, 'discount_amount' => $discount],
            );

            if ($created->wasRecentlyCreated) {
                Coupon::whereKey($coupon->id)->increment('times_used');
            }
        });
    }
}

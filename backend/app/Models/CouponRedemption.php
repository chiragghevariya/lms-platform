<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $coupon_id
 * @property int $user_id
 * @property int|null $payment_id
 * @property int|null $subscription_id
 * @property int $discount_amount
 */
class CouponRedemption extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['coupon_id', 'user_id', 'payment_id', 'subscription_id', 'discount_amount'];

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}

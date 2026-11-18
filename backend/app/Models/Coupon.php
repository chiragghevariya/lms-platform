<?php

namespace App\Models;

use App\Enums\CouponType;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $code
 * @property CouponType $type
 * @property int $value
 * @property string|null $currency
 * @property int|null $max_uses
 * @property int $times_used
 * @property Carbon|null $expires_at
 * @property bool $is_active
 * @property string|null $stripe_coupon_id
 * @property string|null $razorpay_offer_id
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use Audited, HasFactory, HasPublicUuid, SoftDeletes;

    protected $fillable = ['code', 'type', 'value', 'currency', 'max_uses', 'expires_at', 'is_active', 'stripe_coupon_id', 'razorpay_offer_id'];

    protected $attributes = [
        'currency' => null, 'max_uses' => null, 'times_used' => 0, 'expires_at' => null, 'is_active' => true,
        'stripe_coupon_id' => null, 'razorpay_offer_id' => null,
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'integer',
            'max_uses' => 'integer',
            'times_used' => 'integer',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Plan scope; empty = every plan.
     *
     * @return BelongsToMany<Plan, $this>
     */
    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class);
    }

    /** @return HasMany<CouponRedemption, $this> */
    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** Discount in minor units for the given amount/currency (never exceeds the amount). */
    public function discountFor(int $amount, string $currency): int
    {
        $discount = match ($this->type) {
            CouponType::Percent => intdiv($amount * min(100, $this->value), 100),
            CouponType::Fixed => strtoupper((string) $this->currency) === strtoupper($currency) ? $this->value : 0,
        };

        return max(0, min($amount, $discount));
    }
}

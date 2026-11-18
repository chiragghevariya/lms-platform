<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Models\Concerns\Audited;
use App\Models\Concerns\HasPublicUuid;
use App\Support\Money;
use Database\Factories\PlanPriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $uuid
 * @property int $plan_id
 * @property BillingInterval $interval
 * @property int $amount
 * @property string $currency
 * @property string|null $stripe_price_id
 * @property string|null $razorpay_plan_id
 * @property int $trial_days
 * @property bool $is_active
 * @property Plan $plan
 */
class PlanPrice extends Model
{
    /** @use HasFactory<PlanPriceFactory> */
    use Audited, HasFactory, HasPublicUuid, SoftDeletes;

    protected $fillable = ['plan_id', 'interval', 'amount', 'currency', 'stripe_price_id', 'razorpay_plan_id', 'trial_days', 'is_active'];

    protected $attributes = ['stripe_price_id' => null, 'razorpay_plan_id' => null, 'trial_days' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'amount' => 'integer',
            'trial_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @param  Builder<PlanPrice>  $query
     * @return Builder<PlanPrice>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function price(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function isLifetime(): bool
    {
        return $this->interval === BillingInterval::Lifetime;
    }
}

<?php

namespace App\Models;

use App\Enums\Gateway;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Gateway-agnostic membership record; the source of truth for access.
 * Stripe/Razorpay state is synced in via webhooks.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $plan_id
 * @property int|null $plan_price_id
 * @property Gateway $gateway
 * @property string|null $gateway_subscription_id
 * @property string|null $gateway_customer_id
 * @property SubscriptionStatus $status
 * @property bool $is_lifetime
 * @property Carbon|null $current_period_start
 * @property Carbon|null $current_period_end
 * @property Carbon|null $trial_ends_at
 * @property bool $cancel_at_period_end
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $past_due_since
 * @property int|null $scheduled_plan_price_id
 * @property Carbon|null $scheduled_change_at
 * @property Carbon|null $gateway_cancel_requested_at
 * @property int|null $coupon_id
 * @property int|null $granted_by
 * @property string|null $notes
 * @property Plan $plan
 * @property PlanPrice|null $planPrice
 * @property PlanPrice|null $scheduledPrice
 * @property User $user
 */
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasPublicUuid, SoftDeletes;

    protected $fillable = [
        'user_id', 'plan_id', 'plan_price_id', 'gateway', 'gateway_subscription_id', 'gateway_customer_id',
        'status', 'is_lifetime', 'current_period_start', 'current_period_end', 'trial_ends_at',
        'cancel_at_period_end', 'cancelled_at', 'ended_at', 'past_due_since', 'scheduled_plan_price_id',
        'scheduled_change_at', 'gateway_cancel_requested_at', 'coupon_id', 'granted_by', 'notes',
    ];

    protected $attributes = [
        'plan_price_id' => null, 'gateway_subscription_id' => null, 'gateway_customer_id' => null,
        'is_lifetime' => false, 'current_period_start' => null, 'current_period_end' => null,
        'trial_ends_at' => null, 'cancel_at_period_end' => false, 'cancelled_at' => null, 'ended_at' => null,
        'past_due_since' => null, 'scheduled_plan_price_id' => null, 'scheduled_change_at' => null,
        'gateway_cancel_requested_at' => null, 'coupon_id' => null, 'granted_by' => null, 'notes' => null,
    ];

    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'status' => SubscriptionStatus::class,
            'is_lifetime' => 'boolean',
            'cancel_at_period_end' => 'boolean',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'trial_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
            'past_due_since' => 'datetime',
            'scheduled_change_at' => 'datetime',
            'gateway_cancel_requested_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /** @return BelongsTo<PlanPrice, $this> */
    public function planPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class)->withTrashed();
    }

    /** @return BelongsTo<PlanPrice, $this> */
    public function scheduledPrice(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class, 'scheduled_plan_price_id')->withTrashed();
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isManual(): bool
    {
        return $this->gateway === Gateway::Manual;
    }

    /** Within grace after a failed renewal? */
    public function inGracePeriod(): bool
    {
        return $this->status === SubscriptionStatus::PastDue
            && $this->past_due_since !== null
            && $this->past_due_since->copy()->addDays((int) config('billing.grace_days'))->isFuture();
    }

    /** Does this subscription currently grant its tier? */
    public function grantsAccess(): bool
    {
        if ($this->status === SubscriptionStatus::PastDue) {
            return $this->inGracePeriod();
        }

        if (! in_array($this->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true)) {
            return false;
        }

        if ($this->current_period_start !== null && $this->current_period_start->isFuture()) {
            return false;
        }

        return $this->is_lifetime || $this->current_period_end === null || $this->current_period_end->isFuture();
    }
}

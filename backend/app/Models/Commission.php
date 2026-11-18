<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $affiliate_id
 * @property int $referral_id
 * @property int|null $payment_id
 * @property int $level
 * @property string $kind
 * @property int|null $parent_id
 * @property string $currency
 * @property int $base_amount
 * @property string $rate
 * @property int $amount
 * @property int $reversed_amount
 * @property string $status
 * @property bool $needs_review
 * @property Carbon $available_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $reversed_at
 * @property string|null $reversal_reason
 * @property int|null $payout_id
 * @property Carbon|null $created_at
 * @property Affiliate $affiliate
 * @property Referral $referral
 * @property Payment|null $payment
 */
class Commission extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'kind' => 'sale', 'level' => 1, 'reversed_amount' => 0, 'needs_review' => false];

    protected function casts(): array
    {
        return ['needs_review' => 'boolean', 'available_at' => 'datetime', 'approved_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    /** What the affiliate actually earns from this row after partial refunds. */
    public function net(): int
    {
        return $this->amount - $this->reversed_amount;
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return BelongsTo<Referral, $this> */
    public function referral(): BelongsTo
    {
        return $this->belongsTo(Referral::class);
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

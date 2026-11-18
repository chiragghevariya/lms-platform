<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $code
 * @property string $status
 * @property string $tier
 * @property string|null $commission_type
 * @property int|null $commission_rate
 * @property array<string, int>|null $commission_fixed
 * @property string|null $website
 * @property string|null $promotion_plan
 * @property string|null $payout_method
 * @property array<string, string>|null $payout_details
 * @property string|null $review_notes
 * @property Carbon|null $approved_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $created_at
 * @property User $user
 */
class Affiliate extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'code', 'status', 'tier', 'commission_type', 'commission_rate', 'commission_fixed', 'website', 'promotion_plan', 'payout_method', 'payout_details'];

    protected $attributes = ['status' => 'pending', 'tier' => 'standard'];

    protected $hidden = ['payout_details'];

    protected function casts(): array
    {
        return ['commission_fixed' => 'array', 'payout_details' => 'encrypted:array', 'approved_at' => 'datetime', 'suspended_at' => 'datetime'];
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** @return array{type: string, rate: int|null, fixed: array<string, int>|null} */
    public function commissionTerms(): array
    {
        if ($this->commission_type) {
            return ['type' => $this->commission_type, 'rate' => $this->commission_rate, 'fixed' => $this->commission_fixed];
        }
        $tier = (array) config('affiliates.tiers.'.$this->tier, config('affiliates.tiers.standard'));

        return ['type' => (string) $tier['type'], 'rate' => isset($tier['rate']) ? (int) $tier['rate'] : null, 'fixed' => $tier['fixed'] ?? null];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Referral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class);
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
}

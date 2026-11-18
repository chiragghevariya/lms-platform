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
 * @property int $affiliate_id
 * @property int $referred_user_id
 * @property int|null $click_id
 * @property string $attribution
 * @property string $status
 * @property list<string>|null $flags
 * @property string|null $signup_ip_hash
 * @property string|null $signup_device_hash
 * @property array<string, string>|null $utm
 * @property Carbon|null $converted_at
 * @property Carbon|null $created_at
 * @property Affiliate $affiliate
 * @property User $referredUser
 */
class Referral extends Model
{
    use HasPublicUuid;

    protected $fillable = ['affiliate_id', 'referred_user_id', 'click_id', 'attribution', 'status', 'flags', 'signup_ip_hash', 'signup_device_hash', 'utm'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['flags' => 'array', 'utm' => 'array', 'converted_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function isFlagged(): bool
    {
        return $this->status === 'flagged';
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
}

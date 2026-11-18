<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $referrer_id
 * @property int $friend_id
 * @property int|null $payment_id
 * @property string $type
 * @property int|null $days
 * @property int|null $credit_amount
 * @property string|null $currency
 * @property string $status
 * @property list<string>|null $flags
 * @property Carbon|null $available_at
 * @property Carbon|null $granted_at
 * @property Carbon|null $created_at
 * @property User $referrer
 * @property User $friend
 */
class ReferralReward extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'invited'];

    protected function casts(): array
    {
        return ['flags' => 'array', 'available_at' => 'datetime', 'granted_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function friend(): BelongsTo
    {
        return $this->belongsTo(User::class, 'friend_id');
    }
}

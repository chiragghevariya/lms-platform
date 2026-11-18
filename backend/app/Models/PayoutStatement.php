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
 * @property int $instructor_id
 * @property string $period
 * @property string $currency
 * @property int $pool_percent
 * @property int $revenue_share_percent
 * @property int $subscription_revenue
 * @property int $pool_amount
 * @property int $watch_seconds
 * @property int $total_watch_seconds
 * @property int $subscription_earnings
 * @property int $sales_earnings
 * @property int $total
 * @property string $status
 * @property Carbon|null $finalized_at
 * @property Carbon|null $paid_at
 * @property string|null $paid_reference
 * @property User $instructor
 */
class PayoutStatement extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['finalized_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    /** @return HasMany<PayoutStatementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PayoutStatementLine::class, 'statement_id');
    }
}

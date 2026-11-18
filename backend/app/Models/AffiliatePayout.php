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
 * @property string $period
 * @property string $currency
 * @property int $amount
 * @property int $commissions_count
 * @property string $status
 * @property string|null $method
 * @property string $gateway
 * @property string|null $reference
 * @property Carbon|null $paid_at
 * @property Carbon|null $created_at
 * @property Affiliate $affiliate
 */
class AffiliatePayout extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'gateway' => 'manual'];

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    /** @return HasMany<Commission, $this> */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'payout_id');
    }
}

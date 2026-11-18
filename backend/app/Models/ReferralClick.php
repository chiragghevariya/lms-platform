<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $affiliate_id
 * @property string|null $visitor_id
 * @property string $ip_hash
 * @property string|null $user_agent
 * @property string|null $landing_url
 * @property string|null $referrer
 * @property array<string, string>|null $utm
 * @property Carbon $created_at
 */
class ReferralClick extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['affiliate_id', 'visitor_id', 'ip_hash', 'user_agent', 'landing_url', 'referrer', 'utm'];

    protected function casts(): array
    {
        return ['utm' => 'array'];
    }

    /** @return BelongsTo<Affiliate, $this> */
    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }
}

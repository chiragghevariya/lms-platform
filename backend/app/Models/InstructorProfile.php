<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $revenue_share_percent
 * @property string|null $payout_method
 * @property array<string, string>|null $payout_details
 * @property string|null $tax_id
 * @property Carbon|null $approved_at
 */
class InstructorProfile extends Model
{
    protected $fillable = ['user_id', 'revenue_share_percent', 'payout_method', 'payout_details', 'tax_id', 'approved_at'];

    protected $hidden = ['payout_details', 'tax_id'];

    protected function casts(): array
    {
        return ['payout_details' => 'encrypted:array', 'tax_id' => 'encrypted', 'approved_at' => 'datetime'];
    }

    public function sharePercent(): int
    {
        return $this->revenue_share_percent ?? (int) config('earnings.default_revenue_share_percent');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

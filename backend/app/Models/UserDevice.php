<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $kind session|token
 * @property int|null $token_id
 * @property string|null $name
 * @property string|null $ip_address
 * @property Carbon $last_seen_at
 */
class UserDevice extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'kind', 'token_id', 'name', 'ip_address', 'last_seen_at'];

    protected $attributes = ['token_id' => null, 'name' => null, 'ip_address' => null];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use App\Enums\LoginEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $email
 * @property LoginEvent $event
 * @property string|null $channel
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class LoginActivity extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'email', 'event', 'channel', 'ip_address', 'user_agent', 'device_hash'];

    protected function casts(): array
    {
        return ['event' => LoginEvent::class, 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

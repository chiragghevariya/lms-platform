<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property int $live_session_id
 * @property int $user_id
 * @property bool $attended
 * @property Carbon|null $joined_at
 * @property Carbon|null $left_at
 * @property int $attended_seconds
 * @property Carbon|null $reminded_24h_at
 * @property Carbon|null $reminded_15m_at
 * @property Carbon|null $created_at
 * @property LiveSession $session
 * @property User $user
 */
class LiveRegistration extends Model
{
    use HasPublicUuid;

    protected $table = 'live_session_registrations';

    protected $fillable = ['live_session_id', 'user_id', 'attended', 'joined_at', 'left_at', 'attended_seconds'];

    protected $attributes = ['attended' => false, 'attended_seconds' => 0];

    protected function casts(): array
    {
        return [
            'attended' => 'boolean',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'reminded_24h_at' => 'datetime',
            'reminded_15m_at' => 'datetime',
        ];
    }

    /** Zoom `customer_key` (≤ 35 chars) that ties report rows back to this registration. */
    public function customerKey(): string
    {
        return str_replace('-', '', $this->uuid);
    }

    /** @return BelongsTo<LiveSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(LiveSession::class, 'live_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

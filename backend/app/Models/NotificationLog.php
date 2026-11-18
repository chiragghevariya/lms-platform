<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $notification_id
 * @property int|null $user_id
 * @property int|null $campaign_id
 * @property string $event
 * @property string $channel
 * @property string $status
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property string|null $recipient
 * @property string|null $subject
 * @property string|null $error
 * @property int $attempts
 * @property array<string, mixed>|null $vars
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $created_at
 * @property User|null $user
 */
class NotificationLog extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $attributes = ['attempts' => 0];

    protected function casts(): array
    {
        return ['vars' => 'array', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    /** Status only moves forward (a late "delivered" never overwrites "read"). */
    public const RANK = ['skipped' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4, 'failed' => 5];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property list<string> $channels
 * @property array<string, mixed> $segment
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $action_text
 * @property string|null $action_url
 * @property string|null $whatsapp_template
 * @property string|null $whatsapp_language
 * @property list<string>|null $whatsapp_params
 * @property string $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int $audience_count
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property User $creator
 */
class Campaign extends Model
{
    use HasPublicUuid;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'audience_count' => 0];

    protected function casts(): array
    {
        return ['channels' => 'array', 'segment' => 'array', 'whatsapp_params' => 'array', 'scheduled_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

<?php

namespace App\Models;

use App\Enums\Gateway;
use App\Enums\WebhookStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Gateway $gateway
 * @property string $event_id
 * @property string $type
 * @property string $payload
 * @property WebhookStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property Carbon|null $processed_at
 */
class WebhookEvent extends Model
{
    protected $fillable = ['gateway', 'event_id', 'type', 'payload', 'status'];

    protected $attributes = ['status' => 'received', 'attempts' => 0, 'error' => null, 'processed_at' => null];

    protected function casts(): array
    {
        return [
            'gateway' => Gateway::class,
            'status' => WebhookStatus::class,
            'attempts' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        /** @var array<string, mixed> */
        return json_decode($this->payload, true, 512, JSON_THROW_ON_ERROR);
    }
}

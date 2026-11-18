<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $user_id
 * @property string $event
 * @property string $channel
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'event', 'channel', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}

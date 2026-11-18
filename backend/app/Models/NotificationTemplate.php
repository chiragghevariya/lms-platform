<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $event
 * @property string $channel
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $action_text
 * @property string|null $whatsapp_template
 * @property string|null $whatsapp_language
 * @property list<string>|null $whatsapp_params
 * @property bool $is_active
 * @property int|null $updated_by
 */
class NotificationTemplate extends Model
{
    protected $fillable = ['event', 'channel', 'subject', 'body', 'action_text', 'whatsapp_template', 'whatsapp_language', 'whatsapp_params', 'is_active', 'updated_by'];

    protected function casts(): array
    {
        return ['whatsapp_params' => 'array', 'is_active' => 'boolean'];
    }
}

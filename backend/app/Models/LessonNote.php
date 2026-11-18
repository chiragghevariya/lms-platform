<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $lesson_id
 * @property int|null $position_seconds
 * @property string $body
 */
class LessonNote extends Model
{
    use HasPublicUuid;

    protected $fillable = ['user_id', 'lesson_id', 'position_seconds', 'body'];

    protected $attributes = ['position_seconds' => null];

    protected function casts(): array
    {
        return ['position_seconds' => 'integer'];
    }
}
